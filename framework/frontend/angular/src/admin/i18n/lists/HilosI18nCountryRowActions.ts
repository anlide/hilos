// HilosI18nCountryRowActions — a country row's delete control: it only opens the
// confirmation, and only while the server's live hint lets the country go. A
// refused delete keeps its place, marked as blocked and not offered, with the
// server's reason as visible text beside it — never a title. Each table and
// card copy owns its description id: the table draws this cell twice (row and
// card), so an id built from the country code would join two distinct DOM
// nodes. Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  input,
  output,
} from '@angular/core'
import {
  HILOS_I18N_COUNTRY_MARKS,
  hilosI18nCountryDeleteLabel,
  hilosI18nCountryDeleteReason,
} from '@hilos/core'
import type { HilosI18nCountryRow } from '@hilos/core'

// Distinct ids so the row and the card copy of one country never share one.
let countryRowActionsSeq = 0

/** The delete opener of one country row. */
@Component({
  selector: 'hilos-i18n-country-row-actions',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <span class="d-flex flex-column align-items-start gap-1">
      <button
        type="button"
        [class]="
          'btn btn-sm ' + (row().canDelete ? 'btn-outline-danger' : blockedTone)
        "
        [attr.aria-label]="'Delete country ' + label(row())"
        [attr.aria-disabled]="row().canDelete ? null : 'true'"
        [attr.aria-describedby]="row().canDelete ? null : reasonId"
        [attr.data-id]="'i18n-countries-delete-' + row().code"
        (click)="openDelete()"
      >
        <i class="bi bi-trash" aria-hidden="true"></i>
      </button>
      @if (!row().canDelete) {
        <span
          [id]="reasonId"
          class="small text-body-secondary"
          [attr.data-id]="'i18n-countries-delete-reason-' + row().code"
          >{{ reason(row()) }}</span
        >
      }
    </span>
  `,
})
export class HilosI18nCountryRowActions {
  /** The country row the control belongs to. */
  readonly row = input.required<HilosI18nCountryRow>()
  /** Open the delete confirmation over this row. */
  readonly remove = output<void>()

  protected readonly blockedTone = HILOS_I18N_COUNTRY_MARKS.deleteBlocked.tone
  protected readonly label = hilosI18nCountryDeleteLabel
  protected readonly reason = hilosI18nCountryDeleteReason
  protected readonly reasonId = `i18n-country-delete-reason-${countryRowActionsSeq++}`

  protected openDelete(): void {
    if (this.row().canDelete) {
      this.remove.emit()
    }
  }
}
