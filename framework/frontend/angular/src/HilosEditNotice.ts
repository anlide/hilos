// HilosEditNotice — the one message of an edit modal about the other side:
// the row was deleted under the modal, a field conflicts, or a field took a
// value that arrived elsewhere ("Updated just now"). It is drawn in room that
// was taken before there was anything to say: the block is exactly one line
// tall and never changes height, because while there is no message an
// invisible twin of the very same markup holds the room, so no message moves
// the fields the person is editing (styling-rules.md, "The room a live message
// takes"). One line at any length: the text is truncated with an ellipsis and
// the whole of it lives behind the details button, which stands at every
// message and is always the same width — a button that came and went would
// change the row from message to message. Truncation is visual only, so the
// node's text stays whole.
// The mechanics are HilosFormError's, but this is not that row with a tone:
// that one is the refusal of a form (HIL-957), its details carry a class name,
// an original text and a Copy button, and a word about a change made elsewhere
// is not a refusal.
// The slot is the modal's live region for this message — role=status, polite.
// An edit modal does not swap its steps under the row, so the permanent node
// can be the slot itself, the same reasoning as `announce` on HilosFormError;
// the row carries no role of its own, or the reader would say it twice
// (accessibility.md, "Live regions").
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import type { RowEditNoticeKind } from '@hilos/core'

import { HilosLongText } from './HilosLongText.js'
import { HilosModal } from './HilosModal.js'

/**
 * The tone of each message: a warning where a choice is needed or saving is
 * over, a quiet note where the form took a value on its own.
 */
const TONES: Record<RowEditNoticeKind, { row: string; icon: string }> = {
  deleted: { row: 'alert-warning', icon: 'bi bi-exclamation-triangle' },
  conflict: { row: 'alert-warning', icon: 'bi bi-exclamation-triangle' },
  updated: { row: 'alert-secondary', icon: 'bi bi-arrow-repeat' },
}

/** The shape the twin takes: any of the three is as tall as the others. */
const IDLE_TONE = TONES.conflict

/** An edit modal's message about the other side, in room that is held whether or not there is one. */
@Component({
  selector: 'hilos-edit-notice',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLongText, HilosModal],
  template: `
    <div [attr.data-id]="dataId() + '-slot'" role="status" aria-live="polite">
      @if (kind(); as kind) {
        <div [class]="rowClass + ' ' + tone().row" [attr.data-id]="dataId()">
          <i [class]="tone().icon + ' flex-shrink-0'" aria-hidden="true"></i>
          <span class="flex-grow-1 text-truncate">{{ text() }}</span>
          <button
            type="button"
            [class]="detailsClass"
            aria-label="Show details"
            title="Show details"
            [attr.data-id]="dataId() + '-details'"
            (click)="detailOpen.set(true)"
          >
            <i class="bi bi-info-circle" aria-hidden="true"></i>
          </button>
        </div>
      } @else {
        <div
          [class]="rowClass + ' ' + idleTone.row + ' invisible'"
          aria-hidden="true"
          [attr.data-id]="dataId() + '-idle'"
        >
          <i [class]="idleTone.icon + ' flex-shrink-0'" aria-hidden="true"></i>
          <span class="flex-grow-1 text-truncate">&nbsp;</span>
          <!-- A span, not a button: the twin holds room, it does not take focus. -->
          <span [class]="detailsClass">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
          </span>
        </div>
      }
    </div>

    <hilos-modal
      [open]="detailOpen()"
      (openChange)="detailOpen.set($event)"
      title="Details"
      initialFocus="dialog"
    >
      <hilos-long-text
        kind="prose"
        [text]="text()"
        [dataId]="dataId() + '-full'"
      />
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-secondary"
          [attr.data-id]="dataId() + '-close'"
          (click)="requestClose()"
        >
          Close
        </button>
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosEditNotice {
  /** Which message to draw; null draws none, and the twin holds the room. */
  readonly kind = input.required<RowEditNoticeKind | null>()

  /** The message, whole: truncated on the row, entire in the details panel. */
  readonly text = input.required<string>()

  /** The data-id of the visible row; the slot, the twin, the button and the full text derive theirs from it. */
  readonly dataId = input.required<string>()

  /**
   * The row and its idle twin, to the character — only the tone and
   * `invisible` differ, and the tone changes no height. The room equals the
   * true height of the row exactly while the markup matches.
   */
  protected readonly rowClass =
    'alert small py-1 px-2 my-2 d-flex align-items-center gap-2'

  /** The details button and the inert copy of it the twin holds the room for. */
  protected readonly detailsClass =
    'btn btn-link btn-sm p-0 lh-1 flex-shrink-0 text-decoration-none text-nowrap'

  protected readonly idleTone = IDLE_TONE

  protected readonly detailOpen = signal(false)

  /** The tone of the message on the row; the twin's while there is none. */
  protected readonly tone = computed(() => {
    const kind = this.kind()

    return kind === null ? IDLE_TONE : TONES[kind]
  })

  constructor() {
    // A message that went takes the panel with it: a panel left open would be
    // showing a text the row no longer says.
    effect(() => {
      if (this.kind() === null) {
        this.detailOpen.set(false)
      }
    })
  }
}
