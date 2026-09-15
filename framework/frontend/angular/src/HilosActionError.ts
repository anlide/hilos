// HilosActionError — the refusal of a tracked action, drawn where the person
// acted. The "server refused" plate of the modal mockup: an alert with an icon
// and the sentence, at the top of the modal body and above the fields, where a
// toast alone would fly away from the form it belongs to (toasts.md).
// Two layers, and they never collapse into one. The slot is permanent and
// carries no styling class at all: it is the live region — a node carrying
// aria-live that is inserted together with its own text announces nothing
// (accessibility.md) — and while there is nothing to say it looks like nothing.
// Inside it stands either the visible plate, which carries no role of its own or
// the reader says the same sentence twice, or an invisible twin of that very
// plate. The twin is what holds the room, so what stands below the plate never
// jumps when a refusal arrives; it is the plate itself made `invisible` rather
// than a guessed `min-height`, because it is then exactly as tall as the real
// thing whatever the real thing becomes (LoadingButton holds the room for the
// text under its spinner the same way), and a declaration of our own outside the
// Sass layer is not ours to write (styling-rules.md).
// The plate is one line at any length: the sentence truncates and is read whole
// in the detail modal behind the button on the right. That button is there for
// every refusal, because it is the only way to the full text; on an admin
// surface it also carries the class name of what actually failed, which is the
// sign the framework held something back (HIL-779). The name hides on a narrow
// screen and the icon does not: the name is longer than the narrowest plate and
// would eat the sentence the plate exists for. Inside the modal the sentence
// comes first and the original text below it, both drawn by hilos-long-text and
// copied by the modal's own copyText, so neither the wrapping of a long line nor
// the Copy button is written here a second time (rules-and-violations.md,
// section E).
// `suppressed` says "this same action is answering somewhere else right now":
// the room stays, the voice goes. It has to be the room that stays — a page that
// drops the plate to keep it out of its own confirmation moves everything under
// the backdrop and hands it back shifted.
// Bootstrap classes only.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'

import { HilosLongText } from './HilosLongText.js'
import { HilosModal } from './HilosModal.js'
import type { HilosTrackedAction } from './hilosTrackedAction.js'

/** The refusal of a tracked action, in room that is held whether it failed or not. */
@Component({
  selector: 'hilos-action-error',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLongText, HilosModal],
  template: `
    <div data-id="hilos-action-error-slot" role="alert" aria-live="assertive">
      @if (plateShown()) {
        <div [class]="plateClass" data-id="hilos-action-error">
          <i
            class="bi bi-exclamation-circle flex-shrink-0"
            aria-hidden="true"
          ></i>
          <span class="flex-grow-1 text-truncate">{{ message() }}</span>
          <button
            type="button"
            [class]="badgeClass"
            aria-label="Show error details"
            title="Show error details"
            data-id="hilos-action-error-details"
            (click)="detailOpen.set(true)"
          >
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            @if (errorType(); as type) {
              <span
                class="d-none d-sm-inline"
                data-id="hilos-action-error-type"
              >
                {{ type }}
              </span>
            }
          </button>
        </div>
      } @else {
        <div
          [class]="idlePlateClass"
          aria-hidden="true"
          data-id="hilos-action-error-idle"
        >
          <i
            class="bi bi-exclamation-circle flex-shrink-0"
            aria-hidden="true"
          ></i>
          <span class="flex-grow-1 text-truncate">&nbsp;</span>
          <span [class]="badgeClass">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
          </span>
        </div>
      }
    </div>

    <hilos-modal
      [open]="detailOpen()"
      (openChange)="detailOpen.set($event)"
      [title]="errorType() ?? 'Error details'"
      [copyText]="copyText()"
      initialFocus="dialog"
    >
      <div class="d-flex flex-column gap-3">
        <hilos-long-text
          kind="prose"
          [text]="message() ?? ''"
          dataId="hilos-action-error-message"
        />
        @if (hasDetail()) {
          <hilos-long-text
            kind="output"
            [text]="errorDetail() ?? ''"
            dataId="hilos-action-error-detail"
          />
        }
      </div>
      <ng-template #modalActions let-requestClose="requestClose">
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
  /** The tracked action whose latest failure this draws; the room is held either way. */
  readonly action = input.required<HilosTrackedAction>()

  /** Hold the room and stay silent — this action is answering somewhere else. */
  readonly suppressed = input(false)

  /** The details button, and the inert copy of it the twin holds the room for. */
  protected readonly badgeClass =
    'badge rounded-pill bg-danger-subtle text-danger-emphasis border border-danger-subtle d-inline-flex align-items-center gap-1 flex-shrink-0'

  /** The classes the plate and its invisible twin share, to the character. */
  protected readonly plateClass =
    'alert alert-danger d-flex align-items-center gap-2 py-2'

  /** The twin: the very same plate, with only the visibility taken away. */
  protected readonly idlePlateClass = `${this.plateClass} invisible`

  protected readonly detailOpen = signal(false)

  protected readonly message = computed(() => this.action().error())

  protected readonly errorType = computed(
    () => this.action().failure()?.errorType,
  )

  protected readonly errorDetail = computed(
    () => this.action().failure()?.errorDetail,
  )

  /** Whether the plate itself is drawn; the room below it is held regardless. */
  protected readonly plateShown = computed(
    () => this.message() !== null && !this.suppressed(),
  )

  /**
   * An original text the backend did not send is not a shorter panel but no
   * panel: what is drawn then is the sentence alone.
   */
  protected readonly hasDetail = computed(
    () => (this.errorDetail() ?? '') !== '',
  )

  /**
   * Copy carries the original text when there is one and the sentence when
   * there is not: it is copied to be pasted into a ticket, and what is pasted
   * is the long half.
   */
  protected readonly copyText = computed(() =>
    this.hasDetail() ? (this.errorDetail() ?? '') : (this.message() ?? ''),
  )

  constructor() {
    // Clearing the message takes the panel with it: the screen re-arms on the
    // next attempt, and a panel left open would show the previous one's text.
    // The guard watches the message and not the failure, because the failure is
    // null for everything thrown as something other than an ActionError — and
    // the panel now opens for those too.
    effect(() => {
      if (this.message() === null) {
        this.detailOpen.set(false)
      }
    })
  }
}
