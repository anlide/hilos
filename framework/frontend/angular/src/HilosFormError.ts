// HilosFormError — the refusal of a form, drawn in room that was taken before
// the refusal existed. The block is exactly one line tall and never changes
// height: with no refusal an invisible twin of the very same markup holds the
// room, so showing the refusal moves nothing the person is reading. The room is
// held by that twin and not by a height of our own, because the twin is as tall
// as the row turns out to be at this width and this font, and because a
// declaration of our own is not ours to write (styling-rules.md); the trick is
// LoadingButton's. One line at any length: the text is truncated with an
// ellipsis and the whole of it lives behind the details button, which stands at
// every refusal and is always the same width — a button that came and went would
// change the row from refusal to refusal, and a truncated text would have
// nowhere to open. Truncation is visual only, so the node's text stays whole.
// This row is the one refusal row of the SDK: HilosActionError draws a tracked
// action's refusal by mounting it, and what that needs beyond a form's sentence
// — the class name beside the details icon, the original text under an
// "Exception" caption, the Copy button, a live region on the slot — lives here
// as inputs that are off by default, one behavior rather than a second copy of
// the row.
// The heading of the details panel is an input too: a form keeps the general
// one, a tracked action's plate names what the action failed to do.
// The row carries no role at all. A form's voice is the surface's own permanent
// live region, kept apart from the sight of it (accessibility.md) — a live
// region living inside a form that swaps its steps would die with its step;
// only a surface that stays put under the row makes the slot itself the region
// (`announce`).
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

/** A form's refusal, drawn in room that is held whether or not there is one. */
@Component({
  selector: 'hilos-form-error',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLongText, HilosModal],
  template: `
    <div
      [attr.data-id]="dataId() + '-slot'"
      [attr.role]="announce() ? 'alert' : null"
      [attr.aria-live]="announce() ? 'assertive' : null"
    >
      @if (shown(); as message) {
        <div [class]="rowClass" [attr.data-id]="dataId()">
          <i
            class="bi bi-exclamation-circle flex-shrink-0"
            aria-hidden="true"
          ></i>
          <span class="flex-grow-1 text-truncate">{{ message }}</span>
          <button
            type="button"
            [class]="detailsClass"
            aria-label="Show error details"
            title="Show error details"
            [attr.data-id]="dataId() + '-details'"
            (click)="detailOpen.set(true)"
          >
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            @if (errorType(); as type) {
              <span
                class="d-none d-sm-inline ms-1"
                [attr.data-id]="dataId() + '-type'"
                >{{ type }}</span
              >
            }
          </button>
        </div>
      } @else {
        <div
          [class]="rowClass + ' invisible'"
          aria-hidden="true"
          [attr.data-id]="dataId() + '-idle'"
        >
          <i
            class="bi bi-exclamation-circle flex-shrink-0"
            aria-hidden="true"
          ></i>
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
      [title]="detailsTitle()"
      [copyText]="copyText()"
      initialFocus="dialog"
    >
      <div class="d-flex flex-column gap-3">
        <hilos-long-text
          kind="prose"
          [text]="shown() ?? ''"
          [dataId]="dataId() + '-full'"
        />
        @if (hasDetail()) {
          <div>
            <div class="small text-body-secondary mb-1">Exception</div>
            <hilos-long-text
              kind="output"
              [text]="detailText()"
              [dataId]="dataId() + '-detail'"
            />
          </div>
        }
      </div>
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
export class HilosFormError {
  /** The refusal to draw; an empty string means the same as null — no refusal. */
  readonly message = input.required<string | null>()

  /** The data-id of the visible row; the slot and the idle twin derive theirs from it. */
  readonly dataId = input.required<string>()

  /** Short class name of what failed; drawn beside the details icon and above the original text. */
  readonly errorType = input<string | null>(null)

  /** The failure's original text; when not empty, the details panel shows it under "Exception". */
  readonly errorDetail = input<string | null>(null)

  /** What the details panel's Copy button copies; empty means no Copy button. */
  readonly copyText = input('')

  /**
   * Make the slot itself the live region (role=alert, aria-live=assertive).
   * Only for a surface that does not change under the row, such as an admin
   * modal; a form that swaps its steps leaves it off and keeps its voice on the
   * surface, because a region inside a step would die with the step
   * (accessibility.md, "The room belongs to the block, the voice to the
   * surface").
   */
  readonly announce = input(false)

  /**
   * The heading of the details panel. A form leaves it at 'Error details'; a
   * tracked action's plate passes what the action failed to do
   * (HilosActionError).
   */
  readonly detailsTitle = input('Error details')

  /**
   * The row and its idle twin, to the character — only `invisible` differs. The
   * room equals the true height of the row exactly while the markup matches.
   */
  protected readonly rowClass =
    'alert alert-danger small py-1 px-2 my-2 d-flex align-items-center gap-2'

  /** The details button and the inert copy of it the twin holds the room for. */
  protected readonly detailsClass =
    'btn btn-link btn-sm p-0 lh-1 flex-shrink-0 text-decoration-none text-nowrap'

  protected readonly detailOpen = signal(false)

  /**
   * An empty string is the absence of a refusal, the same as null: one meaning,
   * one behavior — otherwise an empty string would draw a red row about nothing.
   */
  protected readonly shown = computed(() =>
    (this.message() ?? '') === '' ? null : this.message(),
  )

  /** An original text that was not sent is not a shorter block but no block. */
  protected readonly hasDetail = computed(
    () => (this.errorDetail() ?? '') !== '',
  )

  /**
   * The class name heads the original text, so what is read — and copied —
   * names what failed.
   */
  protected readonly detailText = computed(() => {
    const type = this.errorType()
    const detail = this.errorDetail() ?? ''
    return type ? `${type}\n${detail}` : detail
  })

  constructor() {
    // A cleared refusal takes the panel with it: the form re-arms on the next
    // attempt, and a panel left open would be showing the previous one's text.
    effect(() => {
      if (this.shown() === null) {
        this.detailOpen.set(false)
      }
    })
  }
}
