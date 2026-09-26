// HilosActionError — the refusal of a tracked action, drawn where the person
// acted. The "server refused" row of the modal mockup: at the top of the modal
// body and above the fields, where a toast alone would fly away from the form
// it belongs to (toasts.md).
// It draws nothing of its own. The row, its invisible twin, the truncation, the
// details button and the details modal are hilos-form-error's; this component
// translates a HilosTrackedAction into that row's inputs — the sentence, the
// class name of what actually failed (the sign the framework held something
// back, HIL-779), the original text, and what Copy copies — so a change to how
// a refusal looks is made in one place, not two (rules-and-violations.md).
// `detailsTitle` is the one thing the plate cannot learn from the action: what
// it failed to do, which only the place that mounts it knows.
// The slot is the live region here, and not on a form: an admin surface does
// not change under the row, so a region on the slot lives as long as the room
// does and announces each refusal once (accessibility.md). A form that swaps
// its steps keeps its voice on the surface instead, which is why `announce` is
// set here and nowhere else.
// `suppressed` says "this same action is answering somewhere else right now":
// the room stays, the voice goes, and an open details panel closes with it. It
// has to be the room that stays — a page that drops the row to keep it out of
// its own confirmation moves everything under the backdrop and hands it back
// shifted.
// Bootstrap classes only.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input,
} from '@angular/core'

import { HilosFormError } from './HilosFormError.js'
import type { HilosTrackedAction } from './hilosTrackedAction.js'

/** The refusal of a tracked action, in room that is held whether it failed or not. */
@Component({
  selector: 'hilos-action-error',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosFormError],
  template: `
    <hilos-form-error
      [message]="suppressed() ? null : message()"
      dataId="hilos-action-error"
      [errorType]="errorType()"
      [errorDetail]="errorDetail()"
      [copyText]="copyText()"
      [detailsTitle]="detailsTitle()"
      [announce]="true"
    />
  `,
})
export class HilosActionError {
  /** The tracked action whose latest failure this draws; the room is held either way. */
  readonly action = input.required<HilosTrackedAction>()

  /** Hold the room and stay silent — this action is answering somewhere else. */
  readonly suppressed = input(false)

  /**
   * What the action failed to do, as the heading of its details panel - a
   * verb, the way every modal title is: "Couldn't save", "Couldn't delete the
   * backup". Required: a panel headed "Error details" tells the person nothing
   * the row did not.
   */
  readonly detailsTitle = input.required<string>()

  protected readonly message = computed(() => this.action().error())

  protected readonly errorType = computed(
    () => this.action().failure()?.errorType ?? null,
  )

  protected readonly errorDetail = computed(
    () => this.action().failure()?.errorDetail ?? null,
  )

  /**
   * Copy carries what the original-text block shows when there is one — the
   * class name heading the original text — and the sentence when there is not:
   * it is copied to be pasted into a ticket, and what is pasted is the long half.
   */
  protected readonly copyText = computed(() => {
    const detail = this.errorDetail() ?? ''
    if (detail === '') {
      return this.message() ?? ''
    }
    const type = this.errorType()
    return type ? `${type}\n${detail}` : detail
  })
}
