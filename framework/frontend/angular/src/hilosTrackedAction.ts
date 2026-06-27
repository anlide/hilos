// The Angular driver for the action lifecycle (core ActionLifecycle,
// wire-protocol.md step 7.4): run an ActionHandle and reflect it as Angular
// signals. `loading` mirrors the handle's deferred-loading signal (the spinner
// that never flashes on a fast op); `busy` is the immediate in-flight guard
// (re-submit / cancel disable); `error` carries the failure message. A modal
// submits, calls `run(handle)`, and closes only when it resolves true — the
// authoritative-backend close (conflict-resolution.md), correlated by the action's
// own reply rather than a table echo (which is why a no-op save no longer hangs:
// the `::success` ack always arrives, even when the row did not change). This is
// the Angular counterpart of the Vue/React `useTrackedAction`; it builds plain
// writable signals, so a component creates it at field init.
import { signal } from '@angular/core'
import type { Signal } from '@angular/core'
import { ActionError, subscribeSignal } from '@hilos/core'
import type { ActionHandle } from '@hilos/core'

/** The reactive state and runner {@link createHilosTrackedAction} exposes. */
export interface HilosTrackedAction {
  /** Deferred loading (the handle's signal): false until ~0.3s while still pending. */
  readonly loading: Signal<boolean>
  /** Immediate in-flight guard: true from dispatch until the reply settles. */
  readonly busy: Signal<boolean>
  /** The latest failure message, or null when clear. */
  readonly error: Signal<string | null>
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
 * Build an Angular tracked-action driver over the action lifecycle.
 *
 * @param describeError Map a caught failure to a user-facing message; defaults to
 *   a generic phrasing that keeps the backend reason off-screen.
 */
export function createHilosTrackedAction(
  describeError: (error: unknown) => string = defaultDescribeError,
): HilosTrackedAction {
  const loading = signal(false)
  const busy = signal(false)
  const error = signal<string | null>(null)

  async function run(handle: ActionHandle): Promise<boolean> {
    // The guard reads the signal synchronously — an Angular set is immediate, so
    // a second submit before the reply sees `busy` already true and bails.
    if (busy()) {
      return false
    }
    busy.set(true)
    error.set(null)
    const stopLoading = subscribeSignal(handle.loading, (next) =>
      loading.set(next),
    )
    try {
      await handle.done

      return true
    } catch (caught) {
      error.set(describeError(caught))

      return false
    } finally {
      stopLoading()
      loading.set(false)
      busy.set(false)
    }
  }

  return {
    loading: loading.asReadonly(),
    busy: busy.asReadonly(),
    error: error.asReadonly(),
    run,
    clearError: () => error.set(null),
  }
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
