// The Vue driver for the action lifecycle (core ActionLifecycle, wire-protocol.md
// step 7.4): run an ActionHandle and reflect it as Vue state. `loading` mirrors
// the handle's deferred-loading signal (the spinner that never flashes on a fast
// op); `busy` is the immediate in-flight guard (re-submit / cancel disable);
// `error` carries the failure message. A modal submits, calls `run(handle)`, and
// closes only when it resolves true — the authoritative-backend close
// (conflict-resolution.md), now correlated by the action's own reply rather than
// a table echo (which is why a no-op save no longer hangs: the `::success` ack
// always arrives, even when the row did not change).
import { ref, type Ref } from 'vue'
import { ActionError, subscribeSignal, type ActionHandle } from '@hilos/core'

/** The reactive state and runner {@link useTrackedAction} exposes. */
export interface TrackedAction {
  /** Deferred loading (the handle's signal): false until ~0.3s while still pending. */
  readonly loading: Readonly<Ref<boolean>>
  /** Immediate in-flight guard: true from dispatch until the reply settles. */
  readonly busy: Readonly<Ref<boolean>>
  /** The latest failure message, or null when clear. */
  readonly error: Readonly<Ref<string | null>>
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
 * Drive the action lifecycle from a Vue component.
 *
 * @param describeError Map a caught failure to a user-facing message; defaults to a generic phrasing that keeps the backend reason off-screen.
 */
export function useTrackedAction(
  describeError: (error: unknown) => string = defaultDescribeError,
): TrackedAction {
  const loading = ref(false)
  const busy = ref(false)
  const error = ref<string | null>(null)

  async function run(handle: ActionHandle): Promise<boolean> {
    if (busy.value) {
      return false
    }
    busy.value = true
    error.value = null
    const stopLoading = subscribeSignal(handle.loading, (next) => {
      loading.value = next
    })
    try {
      await handle.done

      return true
    } catch (caught) {
      error.value = describeError(caught)

      return false
    } finally {
      stopLoading()
      loading.value = false
      busy.value = false
    }
  }

  return {
    loading,
    busy,
    error,
    run,
    clearError: () => {
      error.value = null
    },
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
