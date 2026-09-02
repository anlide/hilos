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
import {
  ActionError,
  hilosToasts,
  subscribeSignal,
  type ActionHandle,
} from '@hilos/core'

/** How a tracked action reports a failure. */
export interface TrackedActionOptions {
  /**
   * Map a caught failure to a user-facing message; defaults to printing what
   * the backend sent, which is already gated to what a client may read.
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
  readonly loading: Readonly<Ref<boolean>>
  /** Immediate in-flight guard: true from dispatch until the reply settles. */
  readonly busy: Readonly<Ref<boolean>>
  /** The latest failure message, or null when clear. */
  readonly error: Readonly<Ref<string | null>>
  /**
   * The latest failure itself, or null when clear or when what was thrown was
   * not an action failure. Carries what {@link TrackedAction.error} cannot: the
   * outcome, and on an admin surface the type and detail of what the backend
   * held back — which is what `HilosActionError` draws.
   */
  readonly failure: Readonly<Ref<ActionError | null>>
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
 * Drive the action lifecycle from a Vue component.
 *
 * @param options How the failure is described and where it surfaces.
 */
export function useTrackedAction(
  options: TrackedActionOptions = {},
): TrackedAction {
  const describeError = options.describeError ?? defaultDescribeError
  const loading = ref(false)
  const busy = ref(false)
  const error = ref<string | null>(null)
  const failure = ref<ActionError | null>(null)

  async function run(handle: ActionHandle): Promise<boolean> {
    if (busy.value) {
      return false
    }
    busy.value = true
    error.value = null
    failure.value = null
    const stopLoading = subscribeSignal(handle.loading, (next) => {
      loading.value = next
    })
    try {
      const result = await handle.done
      if (options.toast !== false) {
        hilosToasts.push(result.message ?? DEFAULT_SUCCESS_MESSAGE, {
          severity: 'success',
        })
      }

      return true
    } catch (caught) {
      const message = describeError(caught)
      error.value = message
      failure.value = caught instanceof ActionError ? caught : null
      if (options.toast !== false) {
        hilosToasts.push(message, { severity: 'error' })
      }

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
    failure,
    run,
    clearError: () => {
      error.value = null
      failure.value = null
    },
  }
}

/** The generic success text shown when the backend supplied no message. */
const DEFAULT_SUCCESS_MESSAGE = 'Done.'

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
