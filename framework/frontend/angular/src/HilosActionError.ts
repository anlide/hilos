// HilosActionError — the refusal of a tracked action, drawn where the person
// acted. The "server refused" plate of the modal mockup: an alert with an icon
// and the sentence, at the top of the modal body and above the fields, where a
// toast alone would fly away from the form it belongs to (toasts.md). An admin
// surface additionally gets the two fields the backend only sends there — the
// class name of what actually failed, as a badge, and its original text behind
// that badge in a detail panel with a Copy button (HIL-779). Their presence is
// the sign the framework held something back: a refusal written for a person is
// already shown in full, so it carries no detail. The panel is a hilos-modal over
// the modal the action was sent from; like every Angular hilos-modal it renders
// in place and overlays through Bootstrap's fixed positioning. Bootstrap classes
// only, save the one pre-wrap declaration the mockup calls for because Bootstrap
// has no utility for it.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import { copyToClipboard, isClipboardAvailable } from '@hilos/core'

import { HilosModal } from './HilosModal.js'
import type { HilosTrackedAction } from './hilosTrackedAction.js'

/** The refusal of a tracked action, with the admin-only detail behind its badge. */
@Component({
  selector: 'hilos-action-error',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosModal],
  template: `
    @if (action().error(); as message) {
      <div
        class="alert alert-danger d-flex align-items-center gap-2 py-2"
        role="alert"
        data-id="hilos-action-error"
      >
        <i
          class="bi bi-exclamation-circle flex-shrink-0"
          aria-hidden="true"
        ></i>
        <span class="flex-grow-1">{{ message }}</span>
        @if (errorType(); as type) {
          @if (hasDetail()) {
            <button
              type="button"
              [class]="badgeClass"
              [title]="'Show the original ' + type + ' message'"
              data-id="hilos-action-error-type"
              (click)="detailOpen.set(true)"
            >
              <i class="bi bi-info-circle" aria-hidden="true"></i>
              <span>{{ type }}</span>
            </button>
          } @else {
            <span [class]="badgeClass" data-id="hilos-action-error-type">
              <i class="bi bi-info-circle" aria-hidden="true"></i>
              <span>{{ type }}</span>
            </span>
          }
        }
      </div>
    }

    <hilos-modal
      [open]="detailOpen()"
      (openChange)="detailOpen.set($event)"
      [title]="errorType() ?? ''"
    >
      <pre
        class="mb-0 small text-break"
        style="white-space: pre-wrap; max-height: 60vh; overflow-y: auto"
        data-id="hilos-action-error-detail"
        >{{ errorDetail() }}</pre
      >
      <ng-template #modalActions let-requestClose="requestClose">
        @if (canCopy) {
          <button
            type="button"
            class="btn btn-outline-secondary"
            data-id="hilos-action-error-copy"
            (click)="copyDetail()"
          >
            <i class="bi bi-clipboard me-1" aria-hidden="true"></i>Copy
          </button>
        }
        <button
          type="button"
          class="btn btn-secondary"
          data-id="hilos-action-error-close"
          (click)="requestClose()"
        >
          Close
        </button>
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosActionError {
  /** The tracked action whose latest failure this draws; nothing renders while it is clear. */
  readonly action = input.required<HilosTrackedAction>()

  /** The type badge, in both the clickable and the inert form. */
  protected readonly badgeClass =
    'badge rounded-pill bg-danger-subtle text-danger-emphasis border border-danger-subtle d-inline-flex align-items-center gap-1 flex-shrink-0'

  /** Whether a clipboard exists to copy the detail into; no clipboard, no button. */
  protected readonly canCopy = isClipboardAvailable()

  protected readonly detailOpen = signal(false)

  protected readonly errorType = computed(
    () => this.action().failure()?.errorType,
  )

  protected readonly errorDetail = computed(
    () => this.action().failure()?.errorDetail,
  )

  /**
   * An empty message leaves nothing to open the panel with, and the type is then
   * all that is known — so the badge is still drawn, just not as a button.
   */
  protected readonly hasDetail = computed(
    () => (this.errorDetail() ?? '') !== '',
  )

  constructor() {
    // Clearing the failure takes the panel with it: the screen re-arms on the
    // next attempt, and a panel left open would show the previous one's text.
    effect(() => {
      if (this.action().failure() === null) {
        this.detailOpen.set(false)
      }
    })
  }

  /** Put the original text where an administrator can paste it into a ticket. */
  protected copyDetail(): void {
    void copyToClipboard(this.errorDetail() ?? '')
  }
}
