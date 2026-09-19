// A flag that rises only once its cause has held for a short while. Something
// that clears quickly — a backend reply, a page answer — never shows it at all,
// so a fast round trip does not flash a spinner or a skeleton for a frame. The
// timer is subtle enough to write once: the loading button and the page skeleton
// both drive `set` in and render `shown` out, with no DOM and no framework here.

import { createSignal, type ReadonlySignal } from '../state/signal.js'

/**
 * The delay before the flag rises: a fixed number of milliseconds, or a getter
 * read each time the flag is armed (so a view can keep it reactive without
 * recreating the state).
 */
export type DeferredDelay = number | (() => number)

/** The headless state of a deferred flag. */
export interface DeferredFlagState {
  /** Whether the flag is shown — true only after the delay while active. */
  readonly shown: ReadonlySignal<boolean>
  /**
   * Set the underlying condition. Call it whenever the condition changes: `true`
   * arms the delayed flag (re-arming it if already armed), `false` cancels a
   * pending one and lowers the flag at once.
   *
   * @param active Whether the condition behind the flag holds.
   */
  set(active: boolean): void
  /** Release a pending timer; call it when the view unmounts. */
  dispose(): void
}

/**
 * Create the headless state for a deferred flag.
 *
 * @param delay The delay before the flag rises. Pass a getter to keep it reactive.
 */
export function createDeferredFlagState(
  delay: DeferredDelay,
): DeferredFlagState {
  const shown = createSignal(false)
  let timer: ReturnType<typeof setTimeout> | undefined

  function dispose(): void {
    if (timer !== undefined) {
      clearTimeout(timer)
      timer = undefined
    }
  }

  function set(active: boolean): void {
    dispose()
    if (!active) {
      shown.set(false)

      return
    }
    const ms = typeof delay === 'function' ? delay() : delay
    timer = setTimeout(() => {
      timer = undefined
      shown.set(true)
    }, ms)
  }

  return {
    shown,
    set,
    dispose,
  }
}
