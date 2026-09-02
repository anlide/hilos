// The React driver for the action lifecycle (core ActionLifecycle,
// wire-protocol.md step 7.4): run an ActionHandle and reflect it as React state.
// `loading` mirrors the handle's deferred-loading signal (the spinner that never
// flashes on a fast op); `busy` is the immediate in-flight guard (re-submit /
// cancel disable); `error` carries the failure message. A modal submits, calls
// `run(handle)`, and closes only when it resolves true — the authoritative-backend
// close (conflict-resolution.md), correlated by the action's own reply rather than
// a table echo (which is why a no-op save no longer hangs: the `::success` ack
// always arrives, even when the row did not change).
import { useCallback, useRef, useState } from 'react'
import { ActionError, hilosToasts, subscribeSignal } from '@hilos/core'
import type { ActionHandle } from '@hilos/core'

/** How a tracked action reports a failure. */
export interface TrackedActionOptions {
  /**
   * Map a caught failure to a user-facing message; defaults to printing what
   * the backend sent, which is already gated to what a client may read.
   */
  describeError?: (error: unknown) => string
  /**
   * Whether the outcome surfaces as a toast — success and failure alike. A
   * success toasts the backend's own sentence when it sent one and stays silent
   * when it did not; a failure toasts by default. Pass false only where the
   * outcome belongs next to the control that raised it (field validation, a
   * sign-in form). `error` is still set on failure either way (toasts.md).
   */
  toast?: boolean
}

/** The reactive state and runner {@link useTrackedAction} exposes. */
export interface TrackedAction {
  /** Deferred loading (the handle's signal): false until ~0.3s while still pending. */
  readonly loading: boolean
  /** Immediate in-flight guard: true from dispatch until the reply settles. */
  readonly busy: boolean
  /** The latest failure message, or null when clear. */
  readonly error: string | null
  /**
   * The latest failure itself, or null when clear or when what was thrown was
   * not an action failure. Carries what {@link TrackedAction.error} cannot: the
   * outcome, and on an admin surface the type and detail of what the backend
   * held back — which is what `HilosActionError` draws.
   */
  readonly failure: ActionError | null
  /**
   * Await a dispatched action: resolves true on `::success`, false on a failure
   * (with `error` set). Returns false immediately while already busy.
   *
   * @param handle The handle returned by `actions.dispatch(...)`.
   */
  run(handle: ActionHandle): Promise<boolean>
  /** Clear the error and the failure behind it — e.g. when opening or re-arming a form. */
  clearError(): void
}

/**
 * Drive the action lifecycle from a React component.
 *
 * @param options How the failure is described and where it surfaces.
 */
export function useTrackedAction(
  options: TrackedActionOptions = {},
): TrackedAction {
  const describeError = options.describeError ?? defaultDescribeError
  const [loading, setLoading] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [failure, setFailure] = useState<ActionError | null>(null)
  // The in-flight guard is read synchronously inside `run`, so it lives in a ref
  // (React state lags a render and would let a double-submit through).
  const busyRef = useRef(false)
  // The describe map is read at failure time through a ref so `run` stays stable
  // even when the caller passes a fresh inline function each render. The toast
  // choice rides the same ref for the same reason.
  const describe = useRef(describeError)
  describe.current = describeError
  const toast = useRef(options.toast !== false)
  toast.current = options.toast !== false

  const run = useCallback(async (handle: ActionHandle): Promise<boolean> => {
    if (busyRef.current) {
      return false
    }
    busyRef.current = true
    setBusy(true)
    setError(null)
    setFailure(null)
    const stopLoading = subscribeSignal(handle.loading, (next) =>
      setLoading(next),
    )
    try {
      const result = await handle.done
      if (toast.current && result.message !== undefined) {
        hilosToasts.push(result.message, { severity: 'success' })
      }

      return true
    } catch (caught) {
      const message = describe.current(caught)
      setError(message)
      setFailure(caught instanceof ActionError ? caught : null)
      if (toast.current) {
        hilosToasts.push(message, { severity: 'error' })
      }

      return false
    } finally {
      stopLoading()
      setLoading(false)
      busyRef.current = false
      setBusy(false)
    }
  }, [])

  const clearError = useCallback(() => {
    setError(null)
    setFailure(null)
  }, [])

  return { loading, busy, error, failure, run, clearError }
}

/**
 * The default failure message: the backend's own words on a refusal, and the
 * driver's phrasing only where the backend never answered at all.
 *
 * A refusal that reached us has already passed the one gate on what a client may
 * read (PHP `ActionFailureReason`), so repeating it is not a leak — while
 * replacing it was, for years, the last step that threw a rule's own sentence
 * away (HIL-779). The three outcomes below are the ones no backend sentence
 * exists for.
 *
 * @param error The caught failure from a tracked action.
 */
function defaultDescribeError(error: unknown): string {
  if (!(error instanceof ActionError)) {
    return 'The action failed. Please try again.'
  }
  switch (error.outcome) {
    case 'timeout':
      return 'The action timed out. Please try again.'
    case 'disconnected':
      return 'The connection dropped before the action completed. Please try again.'
    case 'invalid-reply':
      return 'The server answered in a form this page could not read. Please try again.'
    default:
      return error.message
  }
}
