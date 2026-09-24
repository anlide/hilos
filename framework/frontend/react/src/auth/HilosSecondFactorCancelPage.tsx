// The "it was not me" relay of a second-factor removal (HIL-494). Every notice
// about a removal carries a link to this static SPA route
// (/auth/second-factor/cancel?token=…); the view relays the token as the cancel
// action and says the outcome in place. No sign-in is involved and none results:
// the token alone names the removal, so the page works in a browser that never
// signed in — the person reading the notice may be anywhere.
//
// The route loads cold from a click in a mail client, so the cancel waits for a
// connection that can carry it and gives the wait up on the connection's own
// verdict that the server cannot be reached (HIL-1044), exactly as the
// magic-link relay does; Try again repeats the step whole. A token is spent only
// by a cancel that worked, so a retry after a failed wait is safe.
// Bootstrap classes only, no CSS of its own (styling-rules.md): the column is the
// grid's, not a width of this page's.
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
  whenPageReadyOrUnreachable,
  type HilosAuthContext,
} from '@hilos/core'

import { HilosRouterContext } from '../hilosRouterContext.js'

/** Where Continue goes: the home page, which signs the person in if they want to. */
const HOME_PATH = '/'

/**
 * A link the server does not recognize and a link with nothing in it read the
 * same to the person: the removal it named is not there to cancel.
 */
const LINK_DEAD_MESSAGE = 'This link no longer works.'

/** The sentence for a cancel that never reached the server. */
const UNREACHABLE_MESSAGE = 'Could not reach the server. Please try again.'

/** Props for {@link HilosSecondFactorCancelPage}. */
export interface HilosSecondFactorCancelPageProps {
  /** The project context the relay dispatches the cancel over. */
  context: HilosAuthContext
}

/**
 * The route a removal notice's "it was not me" link opens: relay the token, and
 * say what became of the removal.
 *
 * @param props The project's auth context.
 */
export function HilosSecondFactorCancelPage({
  context,
}: HilosSecondFactorCancelPageProps) {
  const authActions = useMemo(() => createAuthActions(context), [context])

  const router = useContext(HilosRouterContext)
  if (!router) {
    throw new Error(
      'HilosSecondFactorCancelPage requires a HilosRouterContext provider.',
    )
  }

  // `verifying` while the token is in flight, `done` once the removal is
  // canceled, `error` once the link names no removal or the server is out of reach.
  const [status, setStatus] = useState<'verifying' | 'done' | 'error'>(
    'verifying',
  )
  const [message, setMessage] = useState('')
  // Whether a retry can do anything: a link with no token will not grow one.
  const [retryable, setRetryable] = useState(false)
  // The token as it arrived, read once at mount and kept for Try again.
  const token = useRef('')
  // Which attempt is the one on screen; a newer one supersedes what is in
  // flight, which is also what keeps React's development double-mount from
  // spending the link twice.
  const attempt = useRef(0)

  const showError = useCallback((reason: string, canRetry: boolean): void => {
    setStatus('error')
    setMessage(reason)
    setRetryable(canRetry)
  }, [])

  const runCancel = useCallback(async (): Promise<void> => {
    attempt.current += 1
    const mine = attempt.current
    setStatus('verifying')
    setMessage('')
    if (!(await whenPageReadyOrUnreachable(context.connection))) {
      if (attempt.current === mine) {
        showError(UNREACHABLE_MESSAGE, true)
      }

      return
    }
    if (attempt.current !== mine) {
      return
    }

    const outcome = await authActions.cancelSecondFactorReset(token.current)
    if (attempt.current !== mine) {
      return
    }
    if (outcome.ok) {
      setStatus('done')

      return
    }
    showError(outcome.message ?? LINK_DEAD_MESSAGE, true)
  }, [authActions, context, showError])

  useEffect(() => {
    token.current =
      new URLSearchParams(window.location.search).get('token') ?? ''
    if (token.current === '') {
      showError(LINK_DEAD_MESSAGE, false)

      return
    }

    void runCancel()

    return () => {
      // Whatever is in flight belongs to a view that is going away.
      attempt.current += 1
    }
  }, [runCancel, showError])

  const goHome = (): void => router.navigate(HOME_PATH)

  return (
    <section
      data-id="auth-second-factor-cancel"
      className="row justify-content-center text-center"
    >
      <div className="col-sm-8 col-md-6 col-lg-4">
        {status === 'verifying' ? (
          <div role="status" data-id="auth-second-factor-cancel-verifying">
            <span
              className="spinner-border"
              role="status"
              aria-hidden="true"
            ></span>
            <p className="mt-3">Canceling the request…</p>
          </div>
        ) : status === 'done' ? (
          <>
            <div
              className="alert alert-success"
              role="status"
              data-id="auth-second-factor-cancel-done"
            >
              The request to remove two-step verification was canceled.
            </div>
            <button
              type="button"
              className="btn btn-primary"
              data-id="auth-second-factor-cancel-continue"
              onClick={goHome}
            >
              Continue
            </button>
          </>
        ) : (
          <>
            <div
              className="alert alert-danger"
              role="alert"
              data-id="auth-second-factor-cancel-error"
            >
              {message}
            </div>
            <div className="d-flex justify-content-center gap-3">
              {retryable ? (
                <button
                  type="button"
                  className="btn btn-link p-0"
                  data-id="auth-second-factor-cancel-retry"
                  onClick={() => void runCancel()}
                >
                  Try again
                </button>
              ) : null}
              <button
                type="button"
                className="btn btn-link p-0"
                data-id="auth-second-factor-cancel-continue"
                onClick={goHome}
              >
                Continue
              </button>
            </div>
          </>
        )}
      </div>
    </section>
  )
}
