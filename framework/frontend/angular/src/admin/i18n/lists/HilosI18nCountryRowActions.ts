// HilosI18nCountryRowActions — the question button explains a refused delete,
// whose server-written sentence also describes the disabled control. Each
// table and card copy owns its description id: the table draws this cell twice
// (row and card), so an id built from the country code would join two distinct
// DOM nodes. Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input,
  output,
} from '@angular/core'
import {
  HILOS_I18N_COUNTRY_MARKS,
  hilosI18nCountryDeleteLabel,
  hilosI18nCountryRowDeleteReason,
} from '@hilos/core/admin/i18n'
import type { HilosI18nCountryRow } from '@hilos/core/admin/i18n'

import { HilosActionReason } from '@hilos/angular/base'

// Distinct ids so the row and the card copy of one country never share one.
let countryRowActionsSeq = 0

/** The delete opener of one country row. */
@Component({
  selector: 'hilos-i18n-country-row-actions',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosActionReason],
  host: { class: 'd-inline-flex gap-1' },
  template: `
    <button
      hilosActionReason
      [reason]="reason()"
      data-id="hilos-action-reason"
    ></button>
    <button
      type="button"
      [class]="
        'btn btn-sm ' + (row().canDelete ? 'btn-outline-danger' : blockedTone)
      "
      [disabled]="!row().canDelete"
      [attr.aria-label]="'Delete country ' + label(row())"
      [attr.aria-describedby]="reason() ? reasonId : null"
      [attr.data-id]="'i18n-countries-delete-' + row().code"
      (click)="openDelete()"
    >
      <i class="bi bi-trash" aria-hidden="true"></i>
    </button>
    @if (reason(); as reason) {
      <span
        [id]="reasonId"
        class="visually-hidden"
        [attr.data-id]="'i18n-countries-delete-reason-' + row().code"
      >
        {{ reason }}
      </span>
    }
  `,
})
export class HilosI18nCountryRowActions {
  /** The country row the control belongs to. */
  readonly row = input.required<HilosI18nCountryRow>()
  /** Open the delete confirmation over this row. */
  readonly remove = output<void>()

  protected readonly blockedTone = HILOS_I18N_COUNTRY_MARKS.deleteBlocked.tone
  protected readonly label = hilosI18nCountryDeleteLabel
  protected readonly reason = computed(() =>
    hilosI18nCountryRowDeleteReason(this.row()),
  )
  protected readonly reasonId = `i18n-country-delete-reason-${countryRowActionsSeq++}`

  protected openDelete(): void {
    if (this.row().canDelete) {
      this.remove.emit()
    }
  }
}
