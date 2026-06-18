// The headless LoadingButton state machine: the delayed-spinner timer a button
// view renders, with no DOM and no framework. A button shows a spinner only
// after a short delay so a fast backend reply (the authoritative-backend
// pattern: act -> block -> reply clears it) never flashes it. The timer logic is
// subtle enough to be worth writing once here; the per-framework view only
// drives `loading` in and renders `showSpinner` out (multiframework-core.md).
//
// The trivial parts stay in the view by design (the floor of the primitive
// contract): `disabled || loading` and swallowing a click while disabled are
// one-liners no view gets wrong, so they are not pulled down here.

import { createSignal, type ReadonlySignal } from '../state/signal.js'

/** The default delay before the spinner appears, in milliseconds. */
export const DEFAULT_SPINNER_DELAY_MS = 300

/**
 * The spinner delay: a fixed number, or a getter read each time the spinner is
 * armed (so a view can keep it reactive without recreating the controller).
 */
export type SpinnerDelay = number | (() => number)

/** The headless LoadingButton state a view renders. */
export interface LoadingButtonState {
  /** Whether the spinner is shown — true only after the delay while loading. */
  readonly showSpinner: ReadonlySignal<boolean>
  /**
   * Set the in-flight flag. Call it whenever `loading` changes: `true` arms the
   * delayed spinner, `false` cancels a pending one and hides the spinner at once.
   *
   * @param loading Whether the action is in flight.
   */
  setLoading(loading: boolean): void
  /** Release a pending timer; call it when the view unmounts. */
  dispose(): void
}

/**
 * Create the headless state for a loading button.
 *
 * @param delay The delay before the spinner appears; defaults to
 *   {@link DEFAULT_SPINNER_DELAY_MS}. Pass a getter to keep it reactive.
 */
export function createLoadingButtonState(
  delay: SpinnerDelay = DEFAULT_SPINNER_DELAY_MS,
): LoadingButtonState {
  const showSpinner = createSignal(false)
  let timer: ReturnType<typeof setTimeout> | undefined

  function clearTimer(): void {
    if (timer !== undefined) {
      clearTimeout(timer)
      timer = undefined
    }
  }

  function setLoading(loading: boolean): void {
    clearTimer()
    if (!loading) {
      showSpinner.set(false)

      return
    }
    const ms = typeof delay === 'function' ? delay() : delay
    timer = setTimeout(() => {
      timer = undefined
      showSpinner.set(true)
    }, ms)
  }

  return {
    showSpinner,
    setLoading,
    dispose: clearTimer,
  }
}
