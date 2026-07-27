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
   * Map a caught failure to a user-facing message; defaults to a generic
   * phrasing that keeps the backend reason off-screen.
   */
  describeError?: (error: unknown) => string
  /**
   * Whether the outcome surfaces as a toast — success and failure alike. A
   * submit toasts success by default (the backend's message, or a generic
   * fallback) and toasts failure by default; pass false only where the outcome
   * belongs next to the control that raised it (field validation, a sign-in
   * form). `error` is still set on failure either way (toasts.md).
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
 * @param options How the failure is described and where it surfaces.
 */
export function useTrackedAction(
  options: TrackedActionOptions = {},
): TrackedAction {
  const describeError = options.describeError ?? defaultDescribeError
  const [loading, setLoading] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
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
    const stopLoading = subscribeSignal(handle.loading, (next) =>
      setLoading(next),
    )
    try {
      const message = await handle.done
      if (toast.current) {
        hilosToasts.push(message ?? DEFAULT_SUCCESS_MESSAGE, {
          severity: 'success',
        })
      }

      return true
    } catch (caught) {
      const message = describe.current(caught)
      setError(message)
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

  const clearError = useCallback(() => setError(null), [])

  return { loading, busy, error, run, clearError }
}

/** The generic success text shown when the backend supplied no message. */
const DEFAULT_SUCCESS_MESSAGE = 'Done.'

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
