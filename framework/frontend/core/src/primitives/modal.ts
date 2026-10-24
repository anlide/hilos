// The headless modal controller: the discard-confirmation state machine an
// edit-in-modal dialog runs, with no DOM and no framework. Closing a dirty modal
// raises a confirm step ("Discard changes?") instead of closing at once; Escape
// and a backdrop click route through the same guard. While the dialog is busy
// (its request in flight) or interaction is blocked, no user gesture changes the
// confirm step or closes it. Suppressed gestures are never replayed; the parent
// may still close it programmatically. The view owns rendering, the portal, and the
// focus trap (core/dom); this owns only the confirm-step state and the
// close-decision logic (busy guard included), identical across the view layers
// (multiframework-core.md). Config arrives as getters so a view keeps it
// reactive; closing is a callback the view wires to its own close event.

import { createSignal, type ReadonlySignal } from '../state/signal.js'

/** Options for {@link createModalController}. */
export interface ModalControllerOptions {
  /** Whether closing must be confirmed (a dirty draft); read on each attempt. */
  confirmOnClose?: () => boolean
  /** Whether Escape closes; read on each attempt. Defaults to true. */
  closeOnEsc?: () => boolean
  /** Whether a backdrop click closes; read on each attempt. Defaults to true. */
  closeOnBackdrop?: () => boolean
  /**
   * Whether a request of the dialog is in flight; read on each attempt.
   * Defaults to false. While true no user gesture closes the dialog or raises
   * the confirm step, over `closeOnEsc` and `closeOnBackdrop`.
   */
  busy?: () => boolean
  /**
   * Whether interaction is independently blocked; read on each attempt.
   * Defaults to false. This blocks user gestures without starting loading.
   */
  interactionBlocked?: () => boolean
  /** Actually close the dialog — the view wires its own close event here. */
  onClose: () => void
}

/** The headless modal state a view renders and drives. */
export interface ModalController {
  /** Whether the discard-confirmation step is showing. */
  readonly confirmVisible: ReadonlySignal<boolean>
  /**
   * Attempt to close: raise the confirm step when guarding, else close. Does
   * nothing while busy or interaction is blocked.
   */
  requestClose(): void
  /**
   * Escape pressed: back out of the confirm step, else attempt to close. Does
   * nothing while busy or interaction is blocked — an open confirm step stays.
   */
  onEsc(): void
  /**
   * Backdrop clicked: attempt to close unless guarding or in the confirm step.
   * Does nothing while busy or interaction is blocked.
   */
  onBackdrop(): void
  /**
   * Discard the draft: leave the confirm step and close. Does nothing while
   * busy or interaction is blocked — the confirm step stays.
   */
  discard(): void
  /** Keep editing: leave the confirm step unless busy or interaction is blocked. */
  keepEditing(): void
  /** Clear the confirm step; call it when the dialog (re)opens. */
  reset(): void
}

/**
 * Create the headless confirm-on-close state machine for a modal.
 *
 * @param options Close and interaction guard getters and the view's close callback.
 */
export function createModalController(
  options: ModalControllerOptions,
): ModalController {
  const confirmVisible = createSignal(false)
  const confirmOnClose = options.confirmOnClose ?? (() => false)
  const closeOnEsc = options.closeOnEsc ?? (() => true)
  const closeOnBackdrop = options.closeOnBackdrop ?? (() => true)
  const busy = options.busy ?? (() => false)
  const interactionBlocked = options.interactionBlocked ?? (() => false)

  function requestClose(): void {
    if (busy() || interactionBlocked()) {
      return
    }
    if (confirmOnClose()) {
      confirmVisible.set(true)

      return
    }
    options.onClose()
  }

  return {
    confirmVisible,
    requestClose,
    onEsc(): void {
      if (busy() || interactionBlocked()) {
        return
      }
      if (confirmVisible.get()) {
        confirmVisible.set(false)

        return
      }
      if (closeOnEsc()) {
        requestClose()
      }
    },
    onBackdrop(): void {
      if (
        busy() ||
        interactionBlocked() ||
        confirmVisible.get() ||
        !closeOnBackdrop()
      ) {
        return
      }
      requestClose()
    },
    discard(): void {
      if (busy() || interactionBlocked()) {
        return
      }
      confirmVisible.set(false)
      options.onClose()
    },
    keepEditing(): void {
      if (busy() || interactionBlocked()) {
        return
      }
      confirmVisible.set(false)
    },
    reset(): void {
      confirmVisible.set(false)
    },
  }
}
