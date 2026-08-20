// The OAuth callback relay (HIL-281), the React peer of
// framework/frontend/vue/src/auth/HilosOAuthCallbackPage.vue. The provider (or
// the offline stub) bounces the browser back to this static SPA route
// (/auth/callback?code=…&state=…); the app has already opened its single live
// connection (subscribed to the main page through the router fallback), so this
// view hands the code + signed state back as the `hilos_oauth_callback` action.
// The provider key rode session storage across the redirect, since the callback
// URL carries only code + state.
//
// Unlike magic-link, the login completes asynchronously: the action ack means
// only "accepted, working", so the spinner stays and the outcome arrives out of
// band — success as the current-user handshake fan-out (HIL-161), failure as the
// OAuth result signal — with a client timeout as the last-resort backstop. A
// synchronous CSRF/state rejection, a failure signal, a timeout, or a malformed
// return each show a generic error with a way back to sign-in; no provider detail
// is disclosed.
// Bootstrap classes only, no CSS of its own (styling-rules.md).
import { useContext, useEffect, useMemo, useRef, useState } from 'react'
import {
  createOAuthLogin,
  describeOAuthError,
  OAUTH_REASON_LINK_DUPLICATE,
  OAUTH_REASON_LINK_FAILED,
  OAUTH_REASON_LINK_OK,
  OAUTH_REASON_REAUTH_REQUIRED,
  sessionUserId,
  subscribeSignal,
  type HilosAuthContext,
} from '@hilos/core'

import { HilosRouterContext } from '../hilosRouterContext.js'

// The home path a successful sign-in lands on; also the "back to sign-in" target,
// where the auth surface shows for the still-anonymous session.
const HOME_PATH = '/'

// The profile path a successful account link (HIL-401) returns to: the link was
// started there, the session is unchanged, and the new method now shows in the list.
const PROFILE_PATH = '/profile'

// The messages shown when a profile link (HIL-401) does not succeed. A duplicate is
// a distinct, non-sensitive outcome (the provider is tied to another account); any
// other link failure is generic (no provider/network detail on the wire).
const LINK_DUPLICATE_MESSAGE = 'That account is already linked to another user.'
const LINK_FAILED_MESSAGE = 'Could not link the account. Please try again.'

// Client-side backstop past the backend exchange deadline (EXCHANGE_TTL_MS = 15s):
// if neither the current-user update nor the failure signal arrives, resolve the
// spinner to a generic error so it can never wedge.
const CALLBACK_TIMEOUT_MS = 20000

/** The column the relay stands in, the width the mockup gives it. */
const MAX_WIDTH = { maxWidth: '24rem' }

/** Props for {@link HilosOAuthCallbackPage}. */
export interface HilosOAuthCallbackPageProps {
  /** The project context the callback dispatches over. */
  context: HilosAuthContext
}

/**
 * The route an OAuth provider bounces back to: relay the code, then go where the
 * outcome says.
 *
 * @param props The project's auth context.
 */
export function HilosOAuthCallbackPage({
  context,
}: HilosOAuthCallbackPageProps) {
  // The framework OAuth client, bound to that context. The redirect state it
  // reads back (the stashed provider, the armed link) is the framework module's
  // own, so this route sees the trip the surface started.
  const oauth = useMemo(() => createOAuthLogin(context), [context])
  // Who the session belongs to now: its turn to non-null is the success carrier
  // (the async agent upgraded this session and the handshake fan-out landed).
  // It is SUBSCRIBED to below rather than mirrored into a render value, so only a
  // change after arming counts: a link started from the profile (HIL-401) arrives
  // here already signed in, and reading that standing value as the outcome would
  // send the person home before the exchange it came for ever happened.
  const currentUserId = useMemo(() => sessionUserId(context.scopes), [context])

  const router = useContext(HilosRouterContext)
  if (!router) {
    throw new Error(
      'HilosOAuthCallbackPage requires a HilosRouterContext provider.',
    )
  }

  // The relay outcome: `verifying` while the login is in flight, `error` once it
  // is rejected, fails, or times out. A success navigates away, so it needs no
  // visible state.
  const [status, setStatus] = useState<'verifying' | 'error'>('verifying')
  const [message, setMessage] = useState('')

  // One-shot resolution: the first of success / failure / timeout wins, so a late
  // signal cannot fire into a resolved view. The subscriptions themselves are
  // dropped by the effect's cleanup.
  const settled = useRef(false)
  // The provider the redirect stashed. Reading it CONSUMES it, so it is kept
  // here: the effect below can run a second time (React's development
  // double-mount) and would otherwise find the stash empty and report a perfectly
  // good return as malformed.
  const provider = useRef<string | null>(null)
  // The exchange is one-time — the first attempt spends the code — so it is armed
  // once per mounted view, while the subscriptions and the timer follow the
  // effect's own lifetime.
  const exchanged = useRef(false)

  useEffect(() => {
    /**
     * Show a refusal, unless something already resolved this view.
     *
     * @param reason The sentence to show.
     */
    const fail = (reason: string): void => {
      if (settled.current) {
        return
      }
      settled.current = true
      setStatus('error')
      setMessage(reason)
    }

    /**
     * Leave for `path`, unless something already resolved this view.
     *
     * @param path Where the resolved outcome lands.
     */
    const leaveFor = (path: string): void => {
      if (settled.current) {
        return
      }
      settled.current = true
      router.navigate(path)
    }

    const params = new URLSearchParams(window.location.search)
    const code = params.get('code') ?? ''
    const state = params.get('state') ?? ''
    provider.current ??= oauth.takeOAuthProvider()
    if (provider.current === '' || code === '' || state === '') {
      fail('This sign-in link is invalid or incomplete.')

      return
    }

    // Arm the out-of-band resolvers before dispatching, so an early current-user
    // update or failure signal cannot slip past an unsubscribed view.
    const stopUserWatch = subscribeSignal(currentUserId, (id) => {
      if (id !== null) {
        leaveFor(HOME_PATH)
      }
    })
    const unsubscribeFailure = oauth.subscribeOAuthFailure((data) => {
      if (
        data.reason === OAUTH_REASON_REAUTH_REQUIRED &&
        data.email !== null &&
        data.linkToken !== null
      ) {
        // The provider email collided with an existing verified account
        // (HIL-282): not a failure — arm the pending link (module state, so it
        // outlives this view and the sign-in surface the gate later closes) and
        // send the user home, where the auth gate shows the sign-in surface
        // pre-filled to re-authenticate. The token is redeemed by the global
        // replay watcher once that re-auth upgrades the session.
        if (!settled.current) {
          oauth.armOAuthLink(data.email, data.linkToken)
        }
        leaveFor(HOME_PATH)

        return
      }
      if (data.reason === OAUTH_REASON_LINK_OK) {
        // A profile account link (HIL-401) resolved: the session never changed,
        // so success arrives as an explicit result signal rather than a
        // current-user update, and the person goes back to the profile where the
        // new method now shows.
        leaveFor(PROFILE_PATH)

        return
      }
      if (data.reason === OAUTH_REASON_LINK_DUPLICATE) {
        fail(LINK_DUPLICATE_MESSAGE)

        return
      }
      if (data.reason === OAUTH_REASON_LINK_FAILED) {
        fail(LINK_FAILED_MESSAGE)

        return
      }
      fail('OAuth login failed. Please try again.')
    })
    const timeoutTimer = setTimeout(() => {
      fail('OAuth login timed out. Please try again.')
    }, CALLBACK_TIMEOUT_MS)

    if (!exchanged.current) {
      exchanged.current = true
      void (async () => {
        try {
          await oauth.dispatchOAuthCallback(provider.current ?? '', code, state)
          // Accepted, working: the outcome arrives through the armed resolvers.
        } catch (error) {
          fail(describeOAuthError(error))
        }
      })()
    }

    return () => {
      stopUserWatch()
      unsubscribeFailure()
      clearTimeout(timeoutTimer)
    }
  }, [oauth, currentUserId, router])

  return (
    <section
      data-id="auth-oauth-callback"
      className="mx-auto text-center"
      style={MAX_WIDTH}
    >
      {status === 'verifying' ? (
        <div role="status" data-id="auth-oauth-verifying">
          <span className="spinner-border" role="status" aria-hidden="true" />
          <p className="mt-3">Signing you in…</p>
        </div>
      ) : (
        <>
          <div
            className="alert alert-danger"
            role="alert"
            data-id="auth-oauth-callback-error"
          >
            {message}
          </div>
          <button
            type="button"
            className="btn btn-link p-0"
            data-id="auth-oauth-to-login"
            onClick={() => router.navigate(HOME_PATH)}
          >
            Back to sign in
          </button>
        </>
      )}
    </section>
  )
}
