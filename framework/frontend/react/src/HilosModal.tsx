// HilosModal — the one home for editing (edit-in-modal is a hard Hilos rule;
// docs/agents/frontend/conflict-resolution.md). A slot-first dialog: the parent
// fills `header` (defaults to the title), the body (children), and `actions`
// (which receives `requestClose` so a footer button closes through the confirm
// guard). The footer exists exactly when `actions` is given or a `copyText` is:
// a dialog with neither gets no footer element — neither buttons nor the
// bordered strip — and there is no default footer to opt out of.
// The body is what scrolls, always: the dialog is never taller than the window
// (modal-dialog-scrollable), the header and the footer stay put, and a short
// dialog is not changed by it at all. On a narrow screen the dialog becomes a
// sheet at the bottom edge and its buttons one row sharing it equally, the main
// action last, on the right — inside the modal, so no surface that opens one is
// touched.
// The dialog stays narrow by default; `size='wide'` applies Bootstrap's modal-lg
// when the opening surface knows its content is a table or an analysis
// (mockups/components/modal, the Sizes node).
// Copy is the modal's own button: pass `copyText` and it draws one first in the
// footer, because a long technical text almost always has to be carried
// somewhere else, and the rule for showing such a text belongs here rather than
// to every page that has one (rules-and-violations.md, section E).
// Open state is controlled (`open` +
// `onClose`); the dialog portals to <body>, traps Tab focus and returns focus to
// the opener on close, and is keyboard- and ARIA-labelled (a11y ships in v1).
// With confirmOnClose, an Esc/backdrop/close attempt raises an inline confirm
// step instead of discarding a dirty draft. The confirm-step state machine is
// the core modal controller and the focus trap / scroll lock are core/dom; this
// view only renders and wires events. Bootstrap classes only, save for the
// declarations the Sass layer names — the bottom sheet, which stock Bootstrap
// has nothing for, and the modal layer. A modal opened over a modal learns its
// depth from the core modal stack by itself, with nothing passed by the surface
// that opens it, and hands the number to the Sass layer through
// `--hilos-modal-depth`: each layer dims everything under it and stands one
// step narrower (mockups/components/modal, the node for a modal over a modal).
import { useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import type { CSSProperties, KeyboardEvent, ReactNode } from 'react'
import {
  FocusTrap,
  copyToClipboard,
  createModalController,
  enterModalLayer,
  isClipboardAvailable,
  leaveModalLayer,
  lockBodyScroll,
  type FocusPlacement,
  type ModalLayerOwner,
  type ScrollLockOwner,
  unlockBodyScroll,
} from '@hilos/core'

import { useSignal } from './useSignal.js'

/**
 * The trap placement the prop maps onto: `'dialog'` when there is nothing to
 * fill, `'marked'` for a mark in this file or in the body another component
 * draws.
 *
 * @param initialFocus The modal's `initialFocus` prop.
 * @returns The placement handed to {@link FocusTrap.activate}.
 */
function trapPlacement(initialFocus: '' | 'dialog' | 'inner'): FocusPlacement {
  return initialFocus === 'dialog' ? 'dialog' : 'marked'
}

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
  /**
   * The id of the node that carries the dialog's name, when that name is
   * written somewhere else — the heading a surface draws in the body. The
   * accessible name is then the very text a sighted person reads, and it
   * follows that text when it changes. Ignored when `title` is set, for the
   * same reason `ariaLabel` is. Given together with `ariaLabel`, this wins
   * while the node exists, and `ariaLabel` stays as the fallback name for a
   * surface that carries no such heading.
   */
  ariaLabelledby?: string
  /**
   * Where focus lands when the dialog opens. Empty (the default) means a
   * `[data-autofocus]` mark lives in this file; `'dialog'` lands on the
   * dialog itself when there is nothing to fill; `'inner'` means the body
   * is drawn by another component and the mark lives there.
   */
  initialFocus?: '' | 'dialog' | 'inner'
  /**
   * The width the content asks for. Empty keeps the narrow default; `'wide'`
   * applies the mockup's wide size for a table or an analysis. The opening
   * surface owns this choice because it knows what the dialog contains.
   */
  size?: '' | 'wide'
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
  /**
   * The text the footer's Copy button writes to the clipboard. Empty means no
   * such button — and so does a document with no clipboard at all (plain http),
   * because a button that silently does nothing is worse than none.
   */
  copyText?: string
  /** Dismiss the dialog — the parent sets `open` to false. */
  onClose?: () => void
  /** Replace the header (defaults to the title). */
  header?: ReactNode
  /** The dialog body. */
  children?: ReactNode
  /** Replace the footer; receives `requestClose` to close through the guard. */
  actions?: (args: { requestClose: () => void }) => ReactNode
}

/**
 * The edit-in-modal dialog with built-in discard-confirmation.
 *
 * @param props The open state, labels, the dismiss handler, and the header,
 *   body, and actions slots.
 */
export function HilosModal({
  open,
  title = '',
  ariaLabel = '',
  ariaLabelledby = '',
  initialFocus = '',
  size = '',
  closeOnEsc = true,
  closeOnBackdrop = true,
  confirmOnClose = false,
  confirmTitle = 'Discard changes?',
  confirmMessage = 'You have unsaved changes. Discard them?',
  confirmOkText = 'Discard',
  confirmCancelText = 'Keep editing',
  copyText = '',
  onClose,
  header,
  children,
  actions,
}: HilosModalProps) {
  const dialogRef = useRef<HTMLDivElement>(null)
  const confirmRef = useRef<HTMLDivElement>(null)
  const trap = useRef(new FocusTrap()).current
  const scrollLockOwner = useRef<ScrollLockOwner>({}).current
  const modalLayerOwner = useRef<ModalLayerOwner>({}).current
  // The layer this modal stands on: 0 over the page, 1 over another modal.
  const [layerDepth, setLayerDepth] = useState(0)
  const [copied, setCopied] = useState(false)

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
    // The label is the only thing the button says, so it starts over with the
    // dialog: a reopened modal reporting "Copied" is reporting the last visit.
    setCopied(false)
    lockBodyScroll(document, scrollLockOwner)
    // An effect runs after the first paint and children before parents: a modal
    // opened over an open one draws its first frame on layer 0, and two modals
    // opened in one render would take their layers bottom-up. Every nested
    // panel today opens on a click, over a parent that is already up.
    setLayerDepth(enterModalLayer(document, modalLayerOwner))
    const root = dialogRef.current
    if (root) {
      trap.activate(root, trapPlacement(initialFocus))
    }

    return () => {
      unlockBodyScroll(scrollLockOwner)
      leaveModalLayer(modalLayerOwner)
      trap.release()
    }
  }, [open, modal, scrollLockOwner, modalLayerOwner, trap])

  // Moving in and out of the confirm step keeps focus inside the visible dialog.
  useEffect(() => {
    if (!open) {
      return
    }
    const root = confirmVisible ? confirmRef.current : dialogRef.current
    if (root) {
      trap.refocus(
        root,
        confirmVisible ? 'dialog' : trapPlacement(initialFocus),
      )
    }
  }, [confirmVisible, open, trap])

  if (!open) {
    return null
  }

  const showCopy = copyText !== '' && isClipboardAvailable()

  async function onCopy(): Promise<void> {
    setCopied(await copyToClipboard(copyText))
  }

  function onKeyDown(
    event: KeyboardEvent<HTMLDivElement>,
    root: HTMLDivElement | null,
  ): void {
    // A key this layer handles stays in this layer. Through the portal the
    // event still bubbles up the React tree into a modal this one stands over,
    // whose handler would close that modal too, or hand Tab to its trap.
    if (event.key === 'Escape') {
      event.preventDefault()
      event.stopPropagation()
      modal.onEsc()
    } else if (event.key === 'Tab' && root) {
      event.stopPropagation()
      trap.handleTab(root, event.nativeEvent)
    }
  }

  return createPortal(
    <>
      <div
        className="modal-backdrop fade show hilos-modal-layer"
        style={{ '--hilos-modal-depth': layerDepth } as CSSProperties}
      />
      <div
        ref={dialogRef}
        className="modal fade show d-block hilos-modal-layer"
        style={{ '--hilos-modal-depth': layerDepth } as CSSProperties}
        tabIndex={-1}
        role="dialog"
        aria-modal="true"
        aria-label={title || ariaLabel || undefined}
        aria-labelledby={!title && ariaLabelledby ? ariaLabelledby : undefined}
        data-id="modal"
        onKeyDown={(event) => onKeyDown(event, dialogRef.current)}
        onClick={(event) => {
          if (event.target === event.currentTarget) {
            modal.onBackdrop()
          }
        }}
      >
        <div
          className={`modal-dialog modal-dialog-centered modal-dialog-scrollable hilos-modal-sheet${size === 'wide' ? ' modal-lg' : ''}`}
        >
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
            {actions || showCopy ? (
              <div className="modal-footer hilos-button-row">
                {/* Copy comes first in the markup so the main action stands on
                    the right, last in the row. */}
                {showCopy ? (
                  <button
                    type="button"
                    className="btn btn-outline-secondary"
                    data-id="modal-copy"
                    onClick={() => void onCopy()}
                  >
                    <i className="bi bi-clipboard me-1" aria-hidden="true" />
                    {copied ? 'Copied' : 'Copy'}
                  </button>
                ) : null}
                {actions?.({ requestClose: () => modal.requestClose() })}
              </div>
            ) : null}
          </div>
        </div>
      </div>
      {confirmVisible ? (
        <div
          ref={confirmRef}
          className="modal fade show d-block hilos-modal-layer"
          style={{ '--hilos-modal-depth': layerDepth } as CSSProperties}
          tabIndex={-1}
          role="alertdialog"
          aria-modal="true"
          aria-label={confirmTitle}
          data-id="modal-confirm"
          onKeyDown={(event) => onKeyDown(event, confirmRef.current)}
        >
          <div className="modal-dialog modal-dialog-centered modal-dialog-scrollable hilos-modal-sheet">
            <div className="modal-content">
              <div className="modal-header">
                <h5 className="modal-title mb-0">{confirmTitle}</h5>
              </div>
              <div className="modal-body">
                <p className="mb-0">{confirmMessage}</p>
              </div>
              <div className="modal-footer hilos-button-row">
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
