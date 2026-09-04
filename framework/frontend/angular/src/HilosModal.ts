// HilosModal — the one home for editing (edit-in-modal is a hard Hilos rule;
// docs/agents/frontend/conflict-resolution.md). A slot-first dialog: the parent
// fills `[modalHeader]` (defaults to the title), the body (default content), and
// an `<ng-template #modalActions>` (defaults to Cancel/OK, and receives
// `requestClose` so a custom footer can close through the confirm guard).
// showFooter=false renders no footer element at all — that, not an empty actions
// declaration, is how a dialog says it has no footer. Open
// state is two-way (`[(open)]`); it traps Tab focus and returns focus to the
// opener on close, and is keyboard- and ARIA-labelled (a11y ships in v1). With
// confirmOnClose, an Esc/backdrop/close attempt raises an inline confirm step
// instead of discarding a dirty draft. The confirm-step state machine is the core
// modal controller and the focus trap / scroll lock are core/dom; this view only
// renders and wires events. PORTAL: unlike the Vue (teleport) and React
// (createPortal) views, this renders in place — Bootstrap's `.modal` is
// position:fixed, so it overlays the viewport without a portal; a project needing
// to escape a transformed-ancestor stacking context wraps it in a CDK overlay.
// Bootstrap classes only.
import { DOCUMENT, NgTemplateOutlet } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  contentChild,
  effect,
  inject,
  input,
  model,
  output,
  viewChild,
} from '@angular/core'
import type { TemplateRef } from '@angular/core'
import {
  FocusTrap,
  createModalController,
  lockBodyScroll,
  unlockBodyScroll,
} from '@hilos/core'

import { hilosSignal } from './hilosSignal.js'

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
      <div class="modal-backdrop fade show"></div>
      <div
        #dialog
        class="modal fade show d-block"
        tabindex="-1"
        role="dialog"
        aria-modal="true"
        [attr.aria-label]="title() || ariaLabel() || null"
        data-id="modal"
        (keydown)="onKeydown($event, dialog)"
        (click)="onClick($event, dialog)"
      >
        <div class="modal-dialog modal-dialog-centered">
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
            @if (showFooter()) {
              <div class="modal-footer">
                @if (actions(); as tpl) {
                  <ng-container
                    [ngTemplateOutlet]="tpl"
                    [ngTemplateOutletContext]="{ requestClose: requestClose }"
                  />
                } @else {
                  <button
                    type="button"
                    class="btn btn-secondary"
                    (click)="modal.requestClose()"
                  >
                    Cancel
                  </button>
                  <button
                    type="button"
                    class="btn btn-primary"
                    (click)="ok.emit()"
                  >
                    OK
                  </button>
                }
              </div>
            }
          </div>
        </div>
      </div>
      @if (confirmVisible()) {
        <div
          #confirmDialog
          class="modal fade show d-block"
          tabindex="-1"
          role="alertdialog"
          aria-modal="true"
          [attr.aria-label]="confirmTitle()"
          data-id="modal-confirm"
          (keydown)="onKeydown($event, confirmDialog)"
        >
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title mb-0">{{ confirmTitle() }}</h5>
              </div>
              <div class="modal-body">
                <p class="mb-0">{{ confirmMessage() }}</p>
              </div>
              <div class="modal-footer">
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
   * Whether the dialog has a footer at all. False draws no footer element —
   * neither buttons nor the bordered strip: a surface that ends with its own
   * last button says so here instead of declaring empty actions.
   */
  readonly showFooter = input(true)

  /** The default OK action fired. */
  readonly ok = output<void>()
  /** The dialog was dismissed. */
  readonly cancel = output<void>()

  private readonly doc = inject(DOCUMENT)
  private readonly trap = new FocusTrap()
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
  protected readonly actions =
    contentChild<TemplateRef<ModalActionsContext>>('modalActions')
  protected readonly requestClose = (): void => {
    this.modal.requestClose()
  }

  constructor() {
    // Lock scroll and trap focus once while open; the cleanup runs on close.
    effect((onCleanup) => {
      const root = this.dialog()?.nativeElement
      if (!this.open() || !root) {
        return
      }
      this.modal.reset()
      lockBodyScroll(this.doc)
      this.trap.activate(root)
      onCleanup(() => {
        unlockBodyScroll(this.doc)
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
        this.trap.refocus(root)
      }
    })
  }

  protected onKeydown(event: KeyboardEvent, root: HTMLElement): void {
    if (event.key === 'Escape') {
      event.preventDefault()
      this.modal.onEsc()
    } else if (event.key === 'Tab') {
      this.trap.handleTab(root, event)
    }
  }

  protected onClick(event: MouseEvent, dialog: HTMLElement): void {
    if (event.target === dialog) {
      this.modal.onBackdrop()
    }
  }
}
