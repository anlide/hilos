// The headless modal controller: the discard-confirmation state machine an
// edit-in-modal dialog runs, with no DOM and no framework. Closing a dirty modal
// raises a confirm step ("Discard changes?") instead of closing at once; Escape
// and a backdrop click route through the same guard. The view owns rendering,
// the portal, and the focus trap (core/dom); this owns only the confirm-step
// state and the close-decision logic, identical across the view layers
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
  /** Actually close the dialog — the view wires its own close event here. */
  onClose: () => void
}

/** The headless modal state a view renders and drives. */
export interface ModalController {
  /** Whether the discard-confirmation step is showing. */
  readonly confirmVisible: ReadonlySignal<boolean>
  /** Attempt to close: raise the confirm step when guarding, else close. */
  requestClose(): void
  /** Escape pressed: back out of the confirm step, else attempt to close. */
  onEsc(): void
  /** Backdrop clicked: attempt to close unless guarding or in the confirm step. */
  onBackdrop(): void
  /** Discard the draft: leave the confirm step and close. */
  discard(): void
  /** Keep editing: leave the confirm step without closing. */
  keepEditing(): void
  /** Clear the confirm step; call it when the dialog (re)opens. */
  reset(): void
}

/**
 * Create the headless confirm-on-close state machine for a modal.
 *
 * @param options Close-guard getters and the view's close callback.
 */
export function createModalController(
  options: ModalControllerOptions,
): ModalController {
  const confirmVisible = createSignal(false)
  const confirmOnClose = options.confirmOnClose ?? (() => false)
  const closeOnEsc = options.closeOnEsc ?? (() => true)
  const closeOnBackdrop = options.closeOnBackdrop ?? (() => true)

  function requestClose(): void {
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
      if (confirmVisible.get()) {
        confirmVisible.set(false)

        return
      }
      if (closeOnEsc()) {
        requestClose()
      }
    },
    onBackdrop(): void {
      if (confirmVisible.get() || !closeOnBackdrop()) {
        return
      }
      requestClose()
    },
    discard(): void {
      confirmVisible.set(false)
      options.onClose()
    },
    keepEditing(): void {
      confirmVisible.set(false)
    },
    reset(): void {
      confirmVisible.set(false)
    },
  }
}
