// The OAuth callback relay (HIL-281, HIL-633), the React peer of
// framework/frontend/vue/src/auth/HilosOAuthCallbackPage.vue. The provider (or the
// offline stub) brings a browser window back to this static SPA route
// (/auth/callback?code=…&state=…), and this view has exactly two jobs, depending on
// which window it is.
//
// COURIER — the usual one. The trip opened this window, so it has an opener: hand
// the return over by postMessage and close. Nothing is dispatched from here, because
// the daemon answers the exchange on the acceptKey of the connection that asked, and
// the connection that must hear the answer is the one in the page the person never
// left.
//
// COLD — no opener, which means the window that started the trip is gone (it was
// closed while the person was at the provider, or somebody opened this URL
// directly). Then this page is the only one left to finish in: the core runs the
// exchange over this document's own connection and this view navigates on the
// outcome. It is the only way to complete a link whose starting window is gone.
//
// The outcome itself is the core's to decide either way; what is left here is where
// to go and what to say when it will not complete.
// Bootstrap classes only, no CSS of its own (styling-rules.md).
import { useContext, useEffect, useMemo, useRef, useState } from 'react'
import {
  createOAuthLogin,
  OAUTH_RETURN_MESSAGE_TYPE,
  type HilosAuthContext,
  type OAuthReturnMessage,
} from '@hilos/core'

import { HilosRouterContext } from '../hilosRouterContext.js'

// The home path a successful sign-in lands on; also the "back to sign-in" target,
// where the auth surface shows for the still-anonymous session.
const HOME_PATH = '/'

// The profile path a successful account link (HIL-401) returns to: the link was
// started there, the session is unchanged, and the new method now shows in the list.
const PROFILE_PATH = '/profile'

/** The column the relay stands in, the width the mockup gives it. */
const MAX_WIDTH = { maxWidth: '24rem' }

/** Props for {@link HilosOAuthCallbackPage}. */
export interface HilosOAuthCallbackPageProps {
  /** The project context the cold path dispatches over. */
  context: HilosAuthContext
}

/**
 * The route an OAuth provider brings a window back to: courier the return home, or
 * finish it here when there is no home left to courier it to.
 *
 * @param props The project's auth context.
 */
export function HilosOAuthCallbackPage({
  context,
}: HilosOAuthCallbackPageProps) {
  // The framework OAuth client, bound to that context. The trip state it reads is
  // the framework module's own, so a cold return here sees the stash the start left.
  const oauth = useMemo(() => createOAuthLogin(context), [context])

  const router = useContext(HilosRouterContext)
  if (!router) {
    throw new Error(
      'HilosOAuthCallbackPage requires a HilosRouterContext provider.',
    )
  }

  // The relay outcome: `verifying` while the cold exchange is in flight, `error`
  // once it fails. Every other ending navigates away, so it needs no visible state.
  const [status, setStatus] = useState<'verifying' | 'error'>('verifying')
  const [message, setMessage] = useState('')

  // The return is handed over — or exchanged — exactly once per mounted view: both
  // spend the code, and React's development double-mount would otherwise do it
  // twice.
  const relayed = useRef(false)

  useEffect(() => {
    const params = new URLSearchParams(window.location.search)
    const payload: OAuthReturnMessage = {
      type: OAUTH_RETURN_MESSAGE_TYPE,
      code: params.get('code') ?? '',
      state: params.get('state') ?? '',
      error: params.get('error') ?? '',
    }
    // A browser with no opener answers null; a DOM that never defines the property
    // at all answers undefined, and both mean the same thing — nobody to hand this
    // to.
    const opener = (window.opener ?? null) as Window | null
    if (opener !== null) {
      if (!relayed.current) {
        relayed.current = true
        // Targeted at our own origin, so the message cannot be read by a document
        // that merely happens to hold a handle on this window.
        opener.postMessage(payload, window.location.origin)
        window.close()
      }

      return
    }

    // Armed before the resume, because a return with nothing to exchange is refused
    // synchronously and would otherwise be answered to nobody.
    const unsubscribeOutcome = oauth.subscribeOAuthOutcome((outcome) => {
      if (outcome.kind === 'error') {
        setStatus('error')
        setMessage(outcome.message)

        return
      }
      router.navigate(outcome.kind === 'linked' ? PROFILE_PATH : HOME_PATH)
    })
    if (!relayed.current) {
      relayed.current = true
      oauth.resumeOAuthReturn(payload.code, payload.state, payload.error)
    }

    return unsubscribeOutcome
  }, [oauth, router])

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
