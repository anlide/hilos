// HilosModal — the one home for editing (edit-in-modal is a hard Hilos rule;
// docs/agents/frontend/conflict-resolution.md). A slot-first dialog: the parent
// fills `header` (defaults to the title), the body (children), and `actions`
// (defaults to Cancel/OK, and receives `requestClose` so a custom footer can
// close through the confirm guard). showFooter=false renders no footer element
// at all — that, not an empty actions declaration, is how a dialog says it has
// no footer. Open state is controlled (`open` +
// `onClose`); the dialog portals to <body>, traps Tab focus and returns focus to
// the opener on close, and is keyboard- and ARIA-labelled (a11y ships in v1).
// With confirmOnClose, an Esc/backdrop/close attempt raises an inline confirm
// step instead of discarding a dirty draft. The confirm-step state machine is
// the core modal controller and the focus trap / scroll lock are core/dom; this
// view only renders and wires events. Bootstrap classes only.
import { useEffect, useMemo, useRef } from 'react'
import { createPortal } from 'react-dom'
import type { KeyboardEvent, ReactNode } from 'react'
import {
  FocusTrap,
  createModalController,
  lockBodyScroll,
  unlockBodyScroll,
} from '@hilos/core'

import { useSignal } from './useSignal.js'

/** Props for {@link HilosModal}. */
export interface HilosModalProps {
  /** Whether the dialog is open (controlled). */
  open: boolean
  /** The default header title (overridable via `header`). */
  title?: string
  /**
   * The dialog's accessible name when it shows no title of its own — a surface
   * whose heading changes with the step owns that heading in the body, and the
   * name of the dialog still has to say what it is for. Ignored when `title` is
   * set: a visible title names the dialog already.
   */
  ariaLabel?: string
  /** Close on the Escape key (through the confirm guard). Defaults to true. */
  closeOnEsc?: boolean
  /** Close on a backdrop click (through the confirm guard). Defaults to true. */
  closeOnBackdrop?: boolean
  /** Raise a confirm step before closing — set it when the draft is dirty. */
  confirmOnClose?: boolean
  /** Confirm-step heading. */
  confirmTitle?: string
  /** Confirm-step body. */
  confirmMessage?: string
  /** Confirm-step discard label. */
  confirmOkText?: string
  /** Confirm-step keep-editing label. */
  confirmCancelText?: string
  /** Dismiss the dialog — the parent sets `open` to false. */
  onClose?: () => void
  /** The default OK action fired. */
  onOk?: () => void
  /** Replace the header (defaults to the title). */
  header?: ReactNode
  /** The dialog body. */
  children?: ReactNode
  /** Replace the footer; receives `requestClose` to close through the guard. */
  actions?: (args: { requestClose: () => void }) => ReactNode
  /**
   * Whether the dialog has a footer at all. False draws no footer element —
   * neither buttons nor the bordered strip: a surface that ends with its own
   * last button says so here instead of declaring empty actions.
   */
  showFooter?: boolean
}

/**
 * The edit-in-modal dialog with built-in discard-confirmation.
 *
 * @param props The open state, labels, dismiss / OK handlers, and the header,
 *   body, and actions slots.
 */
export function HilosModal({
  open,
  title = '',
  ariaLabel = '',
  closeOnEsc = true,
  closeOnBackdrop = true,
  confirmOnClose = false,
  confirmTitle = 'Discard changes?',
  confirmMessage = 'You have unsaved changes. Discard them?',
  confirmOkText = 'Discard',
  confirmCancelText = 'Keep editing',
  onClose,
  onOk,
  header,
  children,
  actions,
  showFooter = true,
}: HilosModalProps) {
  const dialogRef = useRef<HTMLDivElement>(null)
  const confirmRef = useRef<HTMLDivElement>(null)
  const trap = useRef(new FocusTrap()).current

  // The controller reads its config live through a ref so it is created once.
  const cfg = useRef({ confirmOnClose, closeOnEsc, closeOnBackdrop, onClose })
  cfg.current = { confirmOnClose, closeOnEsc, closeOnBackdrop, onClose }

  const modal = useMemo(
    () =>
      createModalController({
        confirmOnClose: () => cfg.current.confirmOnClose,
        closeOnEsc: () => cfg.current.closeOnEsc,
        closeOnBackdrop: () => cfg.current.closeOnBackdrop,
        onClose: () => cfg.current.onClose?.(),
      }),
    [],
  )
  const confirmVisible = useSignal(modal.confirmVisible)

  useEffect(() => {
    if (!open) {
      return
    }
    modal.reset()
    lockBodyScroll(document)
    const root = dialogRef.current
    if (root) {
      trap.activate(root)
    }

    return () => {
      unlockBodyScroll(document)
      trap.release()
    }
  }, [open, modal, trap])

  // Moving in and out of the confirm step keeps focus inside the visible dialog.
  useEffect(() => {
    if (!open) {
      return
    }
    const root = confirmVisible ? confirmRef.current : dialogRef.current
    if (root) {
      trap.refocus(root)
    }
  }, [confirmVisible, open, trap])

  if (!open) {
    return null
  }

  function onKeyDown(
    event: KeyboardEvent<HTMLDivElement>,
    root: HTMLDivElement | null,
  ): void {
    if (event.key === 'Escape') {
      event.preventDefault()
      modal.onEsc()
    } else if (event.key === 'Tab' && root) {
      trap.handleTab(root, event.nativeEvent)
    }
  }

  return createPortal(
    <>
      <div className="modal-backdrop fade show" />
      <div
        ref={dialogRef}
        className="modal fade show d-block"
        tabIndex={-1}
        role="dialog"
        aria-modal="true"
        aria-label={title || ariaLabel || undefined}
        data-id="modal"
        onKeyDown={(event) => onKeyDown(event, dialogRef.current)}
        onClick={(event) => {
          if (event.target === event.currentTarget) {
            modal.onBackdrop()
          }
        }}
      >
        <div className="modal-dialog modal-dialog-centered">
          <div className="modal-content">
            <div className="modal-header">
              {header ??
                /* No title, no heading: an empty one is a heading in the
                   accessibility tree that names nothing, and a dialog whose
                   heading lives in its body (the auth surface) has a title here
                   only by accident. */
                (title ? <h5 className="modal-title mb-0">{title}</h5> : null)}
              <button
                type="button"
                className="btn-close"
                aria-label="Close"
                data-id="modal-close"
                onClick={() => modal.requestClose()}
              />
            </div>
            <div className="modal-body">{children}</div>
            {showFooter ? (
              <div className="modal-footer">
                {actions ? (
                  actions({ requestClose: () => modal.requestClose() })
                ) : (
                  <>
                    <button
                      type="button"
                      className="btn btn-secondary"
                      onClick={() => modal.requestClose()}
                    >
                      Cancel
                    </button>
                    <button
                      type="button"
                      className="btn btn-primary"
                      onClick={() => onOk?.()}
                    >
                      OK
                    </button>
                  </>
                )}
              </div>
            ) : null}
          </div>
        </div>
      </div>
      {confirmVisible ? (
        <div
          ref={confirmRef}
          className="modal fade show d-block"
          tabIndex={-1}
          role="alertdialog"
          aria-modal="true"
          aria-label={confirmTitle}
          data-id="modal-confirm"
          onKeyDown={(event) => onKeyDown(event, confirmRef.current)}
        >
          <div className="modal-dialog modal-dialog-centered">
            <div className="modal-content">
              <div className="modal-header">
                <h5 className="modal-title mb-0">{confirmTitle}</h5>
              </div>
              <div className="modal-body">
                <p className="mb-0">{confirmMessage}</p>
              </div>
              <div className="modal-footer">
                <button
                  type="button"
                  className="btn btn-secondary"
                  data-id="modal-confirm-cancel"
                  onClick={() => modal.keepEditing()}
                >
                  {confirmCancelText}
                </button>
                <button
                  type="button"
                  className="btn btn-danger"
                  data-id="modal-confirm-discard"
                  onClick={() => modal.discard()}
                >
                  {confirmOkText}
                </button>
              </div>
            </div>
          </div>
        </div>
      ) : null}
    </>,
    document.body,
  )
}
