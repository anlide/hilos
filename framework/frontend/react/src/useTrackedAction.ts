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
import { ActionError, subscribeSignal } from '@hilos/core'
import type { ActionHandle } from '@hilos/core'

/** The reactive state and runner {@link useTrackedAction} exposes. */
export interface TrackedAction {
  /** Deferred loading (the handle's signal): false until ~0.3s while still pending. */
  readonly loading: boolean
  /** Immediate in-flight guard: true from dispatch until the reply settles. */
  readonly busy: boolean
  /** The latest failure message, or null when clear. */
  readonly error: string | null
  /**
   * Await a dispatched action: resolves true on `::success`, false on a failure
   * (with `error` set). Returns false immediately while already busy.
   *
   * @param handle The handle returned by `actions.dispatch(...)`.
   */
  run(handle: ActionHandle): Promise<boolean>
  /** Clear the error — e.g. when opening or re-arming a form. */
  clearError(): void
}

/**
 * Drive the action lifecycle from a React component.
 *
 * @param describeError Map a caught failure to a user-facing message; defaults to
 *   a generic phrasing that keeps the backend reason off-screen.
 */
export function useTrackedAction(
  describeError: (error: unknown) => string = defaultDescribeError,
): TrackedAction {
  const [loading, setLoading] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  // The in-flight guard is read synchronously inside `run`, so it lives in a ref
  // (React state lags a render and would let a double-submit through).
  const busyRef = useRef(false)
  // The describe map is read at failure time through a ref so `run` stays stable
  // even when the caller passes a fresh inline function each render.
  const describe = useRef(describeError)
  describe.current = describeError

  const run = useCallback(async (handle: ActionHandle): Promise<boolean> => {
    if (busyRef.current) {
      return false
    }
    busyRef.current = true
    setBusy(true)
    setError(null)
    const stopLoading = subscribeSignal(handle.loading, (next) =>
      setLoading(next),
    )
    try {
      await handle.done

      return true
    } catch (caught) {
      setError(describe.current(caught))

      return false
    } finally {
      stopLoading()
      setLoading(false)
      busyRef.current = false
      setBusy(false)
    }
  }, [])

  const clearError = useCallback(() => setError(null), [])

  return { loading, busy, error, run, clearError }
}

/**
 * The default failure message: generic, and never leaks the backend reason.
 *
 * @param error The caught failure from a tracked action.
 */
function defaultDescribeError(error: unknown): string {
  return error instanceof ActionError && error.outcome === 'timeout'
    ? 'The action timed out. Please try again.'
    : 'The action could not be completed. Please try again.'
}
