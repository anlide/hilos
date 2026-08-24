// The magic-link confirm relay (HIL-283), the React peer of
// framework/frontend/vue/src/auth/HilosMagicLinkPage.vue. The emailed sign-in
// link opens this static SPA route (/auth/magic?email=…&token=…); the app has
// already opened its single live connection (subscribed to the main page through
// the router fallback), so this view relays the token as the CONFIRM_MAGIC_LINK
// action the request-only auth surface never sends. A valid token upgrades the
// session on the backend; this then navigates home, where the now-authenticated
// main page renders. A missing or rejected token shows a generic error with a way
// back to sign-in — no token detail is disclosed.
//
// The route is entered by a click in a mail client, so it loads cold: the confirm
// is held behind the framework's page-ready gate until the connection can carry it
// (HIL-607, in `authActions`). Waiting is the one thing that can go on forever, so
// this screen carries the backstop the gate deliberately does not — the same 20s
// the OAuth callback relay uses — and offers Try again, which repeats the step
// whole. The link is not spent by a wait that failed: a token is consumed only by
// a successful check, so a retry is the one action that actually helps here.
// Bootstrap classes only, no CSS of its own (styling-rules.md).
import {
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react'
import {
  createAuthActions,
  whenPageReady,
  type HilosAuthContext,
} from '@hilos/core'

import { HilosRouterContext } from '../hilosRouterContext.js'

/**
 * The home path the successful sign-in lands on; also the target of the "back to
 * sign-in" link, where the auth surface shows for the still-anonymous session.
 */
const HOME_PATH = '/'

// Client-side backstop on the wait for a connection that can carry the confirm:
// the page-ready gate parks forever by design, so if no page is ever answered,
// give the wait up rather than let the spinner wedge. The same value as the OAuth
// trip's OAUTH_EXCHANGE_TIMEOUT_MS — the two relays wait on the same thing and
// there is no reason for one to be more patient than the other.
const MAGIC_LINK_TIMEOUT_MS = 20000

// The generic sentence for a confirm that never reached the server, matching what
// `describeAuthError` shows for a dropped connection: here it is the honest
// report it was not, since the frame really did fail to leave.
const UNREACHABLE_MESSAGE = 'Could not reach the server. Please try again.'

/** The column the relay stands in, the width the mockup gives it. */
const MAX_WIDTH = { maxWidth: '24rem' }

/**
 * Wait for a connection that can carry the confirm, and give the wait up after
 * the backstop. Giving up has to end the ATTEMPT, not merely the spinner: a wait
 * abandoned on screen but still pending underneath would dispatch whenever the
 * connection eventually settled, spend the one-time token on a screen already
 * showing an error, and leave Try again nothing left to succeed with.
 *
 * @returns True when a page answered in time, false when the wait was given up on.
 */
function waitForConnection(): Promise<boolean> {
  return new Promise<boolean>((resolve) => {
    const timer = setTimeout(() => resolve(false), MAGIC_LINK_TIMEOUT_MS)
    void whenPageReady().then(() => {
      clearTimeout(timer)
      resolve(true)
    })
  })
}

/** Props for {@link HilosMagicLinkPage}. */
export interface HilosMagicLinkPageProps {
  /** The project context the relay dispatches the confirm over. */
  context: HilosAuthContext
}

/**
 * The route an emailed sign-in link opens: relay the token, then go home.
 *
 * @param props The project's auth context.
 */
export function HilosMagicLinkPage({ context }: HilosMagicLinkPageProps) {
  // The framework wire, bound to that context: this relay dispatches the confirm
  // the sign-in surface itself never sends (its magic-link entry is
  // request-only).
  const authActions = useMemo(() => createAuthActions(context), [context])

  const router = useContext(HilosRouterContext)
  if (!router) {
    throw new Error(
      'HilosMagicLinkPage requires a HilosRouterContext provider.',
    )
  }

  // The relay outcome: `verifying` while the token is in flight, `error` once it
  // is rejected, malformed, or given up on. A success navigates away, so it needs
  // no visible state.
  const [status, setStatus] = useState<'verifying' | 'error'>('verifying')
  const [message, setMessage] = useState('')

  // Whether the failure on screen is one a retry can do anything about. A
  // malformed link is not — nothing in the URL will change by asking again — so
  // it shows the way back to sign-in alone.
  const [retryable, setRetryable] = useState(false)

  // The link as it arrived, read once at mount and kept so Try again can repeat
  // the step from the same URL — the query is not re-read, because nothing
  // navigated.
  const link = useRef({ email: '', token: '' })

  // Which attempt is the one on screen. Taking it is how a new attempt
  // supersedes whatever is still in flight; see `runConfirm`.
  const attempt = useRef(0)

  const showError = useCallback((reason: string, canRetry: boolean): void => {
    setStatus('error')
    setMessage(reason)
    setRetryable(canRetry)
  }, [])

  /**
   * Run the step whole: wait for a connection that can carry the confirm,
   * dispatch it, and show the outcome. This is what mount runs and what Try again
   * runs again — a retry that skipped the wait would be the very bug this screen
   * is here for.
   *
   * Each call takes the attempt token, and every resume point checks it is still
   * the holder. The guard lives HERE rather than in the callers because both of
   * them need it for the same reason: the wait is up to twenty seconds long and
   * the token is one-time, so an attempt that has been superseded — by Try again,
   * or by the view going away — must not spend it and must not navigate out of a
   * page the person has already left. It is also what keeps React's development
   * double-mount from burning the link before the screen is ever seen.
   */
  const runConfirm = useCallback(async (): Promise<void> => {
    attempt.current += 1
    const mine = attempt.current
    setStatus('verifying')
    setMessage('')
    if (!(await waitForConnection())) {
      if (attempt.current === mine) {
        showError(UNREACHABLE_MESSAGE, true)
      }

      return
    }
    if (attempt.current !== mine) {
      return
    }

    const outcome = await authActions.confirmMagicLink(
      link.current.email,
      link.current.token,
    )
    if (attempt.current !== mine) {
      return
    }
    if (outcome.ok) {
      router.navigate(HOME_PATH)

      return
    }
    showError(
      outcome.message ?? 'This sign-in link is invalid or expired.',
      true,
    )
  }, [authActions, router, showError])

  useEffect(() => {
    const params = new URLSearchParams(window.location.search)
    link.current = {
      email: params.get('email') ?? '',
      token: params.get('token') ?? '',
    }
    if (link.current.email === '' || link.current.token === '') {
      showError('This sign-in link is invalid or incomplete.', false)

      return
    }

    void runConfirm()

    return () => {
      // Whatever is in flight belongs to a view that is going away.
      attempt.current += 1
    }
  }, [runConfirm, showError])

  return (
    <section
      data-id="auth-magic"
      className="mx-auto text-center"
      style={MAX_WIDTH}
    >
      {status === 'verifying' ? (
        <div role="status" data-id="auth-magic-verifying">
          <span className="spinner-border" role="status" aria-hidden="true" />
          <p className="mt-3">Signing you in…</p>
        </div>
      ) : (
        <>
          <div
            className="alert alert-danger"
            role="alert"
            data-id="auth-magic-error"
          >
            {message}
          </div>
          <div className="d-flex justify-content-center gap-3">
            {retryable ? (
              <button
                type="button"
                className="btn btn-link p-0"
                data-id="auth-magic-retry"
                onClick={() => void runConfirm()}
              >
                Try again
              </button>
            ) : null}
            <button
              type="button"
              className="btn btn-link p-0"
              data-id="auth-magic-to-login"
              onClick={() => router.navigate(HOME_PATH)}
            >
              Back to sign in
            </button>
          </div>
        </>
      )}
    </section>
  )
}
