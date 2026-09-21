// HilosModal — the one home for editing (edit-in-modal is a hard Hilos rule;
// docs/agents/frontend/conflict-resolution.md). A slot-first dialog: the parent
// fills `[modalHeader]` (defaults to the title), the body (default content), and
// an `<ng-template #modalActions>` (which receives `requestClose` so a footer
// button closes through the confirm guard). The footer exists exactly when that
// template is declared or a `copyText` is given: a dialog with neither gets no
// footer element — neither buttons nor the bordered strip — and there is no
// default footer to opt out of.
// The body is what scrolls, always: the dialog is never taller than the window
// (modal-dialog-scrollable), the header and the footer stay put, and a short
// dialog is not changed by it at all. On a narrow screen the dialog becomes a
// sheet at the bottom edge and its buttons a full-width column, main action on
// top — inside the modal, so no surface that opens one is touched.
// The dialog stays narrow by default; `size='wide'` applies Bootstrap's modal-lg
// when the opening surface knows its content is a table or an analysis
// (mockups/components/modal, the Sizes node).
// Copy is the modal's own button: pass `copyText` and it draws one first in the
// footer, because a long technical text almost always has to be carried
// somewhere else, and the rule for showing such a text belongs here rather than
// to every page that has one (rules-and-violations.md, section E). Open
// state is two-way (`[(open)]`); it traps Tab focus and returns focus to the
// opener on close, and is keyboard- and ARIA-labelled (a11y ships in v1). With
// confirmOnClose, an Esc/backdrop/close attempt raises an inline confirm step
// instead of discarding a dirty draft. The confirm-step state machine is the core
// modal controller and the focus trap / scroll lock are core/dom; this view only
// renders and wires events. PORTAL: unlike the Vue (teleport) and React
// (createPortal) views, this renders in place — Bootstrap's `.modal` is
// position:fixed, so it overlays the viewport without a portal; a project needing
// to escape a transformed-ancestor stacking context wraps it in a CDK overlay.
// Bootstrap classes only, save for the declarations the Sass layer names — the
// bottom sheet, which stock Bootstrap has nothing for, and the modal layer. A
// modal opened over a modal learns its depth from the core modal stack by
// itself, with nothing passed by the surface that opens it, and hands the number
// to the Sass layer through `--hilos-modal-depth`: each layer dims everything
// under it and stands one step narrower (mockups/components/modal, the node for
// a modal over a modal). Rendered in place, a nested layer lifts its z-index
// inside the stacking context of the modal it stands in, which is still over
// that modal's dialog.
import { DOCUMENT, NgTemplateOutlet } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  computed,
  contentChild,
  effect,
  inject,
  input,
  model,
  output,
  signal,
  viewChild,
} from '@angular/core'
import type { TemplateRef } from '@angular/core'
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

import { hilosSignal } from './hilosSignal.js'

/**
 * The trap placement the prop maps onto: `'dialog'` when there is nothing to
 * fill, `'marked'` for a mark in this file or in the body another component
 * draws.
 *
 * @param initialFocus The modal's `initialFocus` input.
 * @returns The placement handed to {@link FocusTrap.activate}.
 */
function trapPlacement(initialFocus: '' | 'dialog' | 'inner'): FocusPlacement {
  return initialFocus === 'dialog' ? 'dialog' : 'marked'
}

/** The context a custom `#modalActions` template receives. */
export interface ModalActionsContext {
  /** Close the dialog through the confirm guard. */
  requestClose: () => void
}

/** The edit-in-modal dialog with built-in discard-confirmation. */
@Component({
  selector: 'hilos-modal',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [NgTemplateOutlet],
  template: `
    @if (open()) {
      <div
        class="modal-backdrop fade show hilos-modal-layer"
        [style.--hilos-modal-depth]="layerDepth()"
      ></div>
      <div
        #dialog
        class="modal fade show d-block hilos-modal-layer"
        [style.--hilos-modal-depth]="layerDepth()"
        tabindex="-1"
        role="dialog"
        aria-modal="true"
        [attr.aria-label]="title() || ariaLabel() || null"
        [attr.aria-labelledby]="
          !title() && ariaLabelledby() ? ariaLabelledby() : null
        "
        data-id="modal"
        (keydown)="onKeydown($event, dialog)"
        (click)="onClick($event, dialog)"
      >
        <div
          class="modal-dialog modal-dialog-centered modal-dialog-scrollable hilos-modal-sheet"
          [class.modal-lg]="size() === 'wide'"
        >
          <div class="modal-content">
            <div class="modal-header">
              <ng-content select="[modalHeader]">
                <!-- No title, no heading: an empty one is a heading in the
                accessibility tree that names nothing, and a dialog whose
                heading lives in its body (the auth surface) has a title here
                only by accident. -->
                @if (title(); as heading) {
                  <h5 class="modal-title mb-0">{{ heading }}</h5>
                }
              </ng-content>
              <button
                type="button"
                class="btn-close"
                aria-label="Close"
                data-id="modal-close"
                (click)="modal.requestClose()"
              ></button>
            </div>
            <div class="modal-body"><ng-content /></div>
            @if (actions() || showCopy()) {
              <div
                class="modal-footer flex-column-reverse flex-sm-row align-items-stretch align-items-sm-center"
              >
                <!-- Copy comes first in the markup so the reversed column on a
                narrow screen puts it under Close, the main action on top. -->
                @if (showCopy()) {
                  <button
                    type="button"
                    class="btn btn-outline-secondary"
                    data-id="modal-copy"
                    (click)="copy()"
                  >
                    <i class="bi bi-clipboard me-1" aria-hidden="true"></i>
                    {{ copied() ? 'Copied' : 'Copy' }}
                  </button>
                }
                @if (actions(); as tpl) {
                  <ng-container
                    [ngTemplateOutlet]="tpl"
                    [ngTemplateOutletContext]="{ requestClose: requestClose }"
                  />
                }
              </div>
            }
          </div>
        </div>
      </div>
      @if (confirmVisible()) {
        <div
          #confirmDialog
          class="modal fade show d-block hilos-modal-layer"
          [style.--hilos-modal-depth]="layerDepth()"
          tabindex="-1"
          role="alertdialog"
          aria-modal="true"
          [attr.aria-label]="confirmTitle()"
          data-id="modal-confirm"
          (keydown)="onKeydown($event, confirmDialog)"
        >
          <div
            class="modal-dialog modal-dialog-centered modal-dialog-scrollable hilos-modal-sheet"
          >
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title mb-0">{{ confirmTitle() }}</h5>
              </div>
              <div class="modal-body">
                <p class="mb-0">{{ confirmMessage() }}</p>
              </div>
              <div
                class="modal-footer flex-column-reverse flex-sm-row align-items-stretch align-items-sm-center"
              >
                <button
                  type="button"
                  class="btn btn-secondary"
                  data-id="modal-confirm-cancel"
                  (click)="modal.keepEditing()"
                >
                  {{ confirmCancelText() }}
                </button>
                <button
                  type="button"
                  class="btn btn-danger"
                  data-id="modal-confirm-discard"
                  (click)="modal.discard()"
                >
                  {{ confirmOkText() }}
                </button>
              </div>
            </div>
          </div>
        </div>
      }
    }
  `,
})
export class HilosModal {
  /** Whether the dialog is open (two-way `[(open)]`). */
  readonly open = model(false)
  /** The default header title (overridable via `[modalHeader]`). */
  readonly title = input('')
  /**
   * The dialog's accessible name when it shows no title of its own — a surface
   * whose heading changes with the step owns that heading in the body, and the
   * name of the dialog still has to say what it is for. Ignored when `title` is
   * set: a visible title names the dialog already.
   */
  readonly ariaLabel = input('')
  /**
   * The id of the node that carries the dialog's name, when that name is
   * written somewhere else — the heading a surface draws in the body. The
   * accessible name is then the very text a sighted person reads, and it
   * follows that text when it changes. Ignored when `title` is set, for the
   * same reason `ariaLabel` is. Given together with `ariaLabel`, this wins
   * while the node exists, and `ariaLabel` stays as the fallback name for a
   * surface that carries no such heading.
   */
  readonly ariaLabelledby = input('')
  /**
   * Where focus lands when the dialog opens. Empty (the default) means a
   * `[data-autofocus]` mark lives in this file; `'dialog'` lands on the
   * dialog itself when there is nothing to fill; `'inner'` means the body
   * is drawn by another component and the mark lives there.
   */
  readonly initialFocus = input<'' | 'dialog' | 'inner'>('')
  /**
   * The width the content asks for. Empty keeps the narrow default; `'wide'`
   * applies the mockup's wide size for a table or an analysis. The opening
   * surface owns this choice because it knows what the dialog contains.
   */
  readonly size = input<'' | 'wide'>('')
  /** Close on the Escape key (through the confirm guard). */
  readonly closeOnEsc = input(true)
  /** Close on a backdrop click (through the confirm guard). */
  readonly closeOnBackdrop = input(true)
  /** Raise a confirm step before closing — set it when the draft is dirty. */
  readonly confirmOnClose = input(false)
  /** Confirm-step heading. */
  readonly confirmTitle = input('Discard changes?')
  /** Confirm-step body. */
  readonly confirmMessage = input('You have unsaved changes. Discard them?')
  /** Confirm-step discard label. */
  readonly confirmOkText = input('Discard')
  /** Confirm-step keep-editing label. */
  readonly confirmCancelText = input('Keep editing')
  /**
   * The text the footer's Copy button writes to the clipboard. Empty means no
   * such button — and so does a document with no clipboard at all (plain http),
   * because a button that silently does nothing is worse than none.
   */
  readonly copyText = input('')
  /** The dialog was dismissed. */
  readonly cancel = output<void>()

  private readonly doc = inject(DOCUMENT)
  private readonly trap = new FocusTrap()
  private readonly scrollLockOwner: ScrollLockOwner = {}
  private readonly modalLayerOwner: ModalLayerOwner = {}
  private readonly dialog = viewChild<ElementRef<HTMLElement>>('dialog')
  private readonly confirmDialog =
    viewChild<ElementRef<HTMLElement>>('confirmDialog')

  protected readonly modal = createModalController({
    confirmOnClose: () => this.confirmOnClose(),
    closeOnEsc: () => this.closeOnEsc(),
    closeOnBackdrop: () => this.closeOnBackdrop(),
    onClose: () => {
      this.open.set(false)
      this.cancel.emit()
    },
  })
  protected readonly confirmVisible = hilosSignal(this.modal.confirmVisible)
  protected readonly copied = signal(false)
  /** The layer this modal stands on: 0 over the page, 1 over another modal. */
  protected readonly layerDepth = signal(0)
  protected readonly showCopy = computed(
    () => this.copyText() !== '' && isClipboardAvailable(),
  )
  protected readonly actions =
    contentChild<TemplateRef<ModalActionsContext>>('modalActions')
  protected readonly requestClose = (): void => {
    this.modal.requestClose()
  }

  constructor() {
    // Lock scroll, enter the modal layer and trap focus once while open; the
    // cleanup runs on close.
    effect((onCleanup) => {
      const root = this.dialog()?.nativeElement
      if (!this.open() || !root) {
        return
      }
      this.modal.reset()
      // The label is the only thing the button says, so it starts over with the
      // dialog: a reopened modal reporting "Copied" is reporting the last visit.
      this.copied.set(false)
      lockBodyScroll(this.doc, this.scrollLockOwner)
      this.layerDepth.set(enterModalLayer(this.doc, this.modalLayerOwner))
      this.trap.activate(root, trapPlacement(this.initialFocus()))
      onCleanup(() => {
        unlockBodyScroll(this.scrollLockOwner)
        leaveModalLayer(this.modalLayerOwner)
        this.trap.release()
      })
    })
    // Moving in and out of the confirm step keeps focus inside the visible dialog.
    effect(() => {
      if (!this.open()) {
        return
      }
      const root = (
        this.confirmVisible() ? this.confirmDialog() : this.dialog()
      )?.nativeElement
      if (root) {
        this.trap.refocus(
          root,
          this.confirmVisible() ? 'dialog' : trapPlacement(this.initialFocus()),
        )
      }
    })
  }

  protected async copy(): Promise<void> {
    this.copied.set(await copyToClipboard(this.copyText()))
  }

  protected onKeydown(event: KeyboardEvent, root: HTMLElement): void {
    // A key this layer handles stays in this layer. Rendered in place, a modal
    // opened over a modal sits inside that modal's element, whose handler would
    // close it too, or hand Tab to a trap that holds this layer's elements.
    if (event.key === 'Escape') {
      event.preventDefault()
      event.stopPropagation()
      this.modal.onEsc()
    } else if (event.key === 'Tab') {
      event.stopPropagation()
      this.trap.handleTab(root, event)
    }
  }

  protected onClick(event: MouseEvent, dialog: HTMLElement): void {
    if (event.target === dialog) {
      this.modal.onBackdrop()
    }
  }
}
