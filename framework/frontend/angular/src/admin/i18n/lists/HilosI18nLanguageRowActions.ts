// HilosI18nLanguageRowActions — a language row's own buttons: the eye of a
// switched-on language or the pencil of a switched-off one, both only opening the
// shared window, and the delete that only opens its confirmation. A refused
// delete stays disabled with the server's reason as visible text beside it.
// Each table and card copy owns its description id: the table draws this cell
// twice (row and card), so an id built from the language code would join two
// distinct DOM nodes. Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input,
  output,
} from '@angular/core'
import {
  HILOS_I18N_LANGUAGE_DELETE_COPY,
  HILOS_I18N_LANGUAGE_WINDOW_COPY,
  hilosI18nLanguageDelete,
} from '@hilos/core/admin/i18n'
import type { HilosI18nLanguageRow } from '@hilos/core/admin/i18n'

// Distinct ids so the row and the card copy of one language never share one.
let languageRowActionsSeq = 0

/** The view/edit and delete openers of one language row. */
@Component({
  selector: 'hilos-i18n-language-row-actions',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <span class="d-inline-flex flex-column align-items-end text-end">
      <span class="d-flex gap-1 justify-content-end">
        @if (row().enabled) {
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            [title]="copy.open.view"
            [attr.aria-label]="copy.open.view"
            [attr.data-id]="'i18n-languages-view-' + row().code"
            (click)="edit.emit()"
          >
            <i class="bi bi-eye" aria-hidden="true"></i>
          </button>
        } @else {
          <button
            type="button"
            class="btn btn-sm btn-outline-primary"
            [title]="copy.open.edit"
            [attr.aria-label]="copy.open.edit"
            [attr.data-id]="'i18n-languages-edit-' + row().code"
            (click)="edit.emit()"
          >
            <i class="bi bi-pencil" aria-hidden="true"></i>
          </button>
        }
        <button
          type="button"
          [class]="
            'btn btn-sm ' +
            (verdict().disabled
              ? 'btn-outline-secondary'
              : 'btn-outline-danger')
          "
          [disabled]="verdict().disabled"
          [attr.aria-label]="deleteCopy.rowLabel(row().nativeName)"
          [attr.aria-describedby]="verdict().reason ? reasonId : null"
          [attr.data-id]="'i18n-languages-delete-' + row().code"
          (click)="remove.emit()"
        >
          <i class="bi bi-trash" aria-hidden="true"></i>
        </button>
      </span>
      @if (verdict().reason; as reason) {
        <span
          [id]="reasonId"
          class="small text-body-secondary text-wrap text-end mt-1 mb-0"
          [attr.data-id]="'i18n-languages-delete-reason-' + row().code"
        >
          {{ reason }}
        </span>
      }
    </span>
  `,
})
export class HilosI18nLanguageRowActions {
  /** The language row the buttons belong to. */
  readonly row = input.required<HilosI18nLanguageRow>()
  /** Open the shared view/edit window over this row. */
  readonly edit = output<void>()
  /** Open the delete confirmation over this row. */
  readonly remove = output<void>()

  protected readonly copy = HILOS_I18N_LANGUAGE_WINDOW_COPY
  protected readonly deleteCopy = HILOS_I18N_LANGUAGE_DELETE_COPY
  protected readonly verdict = computed(() =>
    hilosI18nLanguageDelete(this.row()),
  )
  protected readonly reasonId = `i18n-language-delete-reason-${languageRowActionsSeq++}`
}
