// HilosI18nNamesTable — the one names table of the i18n section (HIL-1477),
// drawn on the names page of a language and, later, of a country. A row is a
// language: its code in capitals with the native name beside it, and the base
// name written in it or the "No name" plate — a plate, not a dash, because a dash
// can be typed into a name and would then read the same as no name at all. An
// empty base is a stored value and shows empty. Under the framework's chevron the
// corrections of that language's locales of a country: the locale, the country's
// label, and the correction or the "as in" plate naming the base it inherits.
// The frame, the chevron and which rows get one are the framework's
// (createHilosI18nNamesTable); there are no buttons here — the subject page
// hands its own as two templates, one for the row and one for a correction, and
// this table stamps them where they belong in the row and in the card alike.
// Bootstrap classes only, painted with classes that follow the theme
// (styling-rules.md).
import { NgTemplateOutlet } from '@angular/common'
import { ChangeDetectionStrategy, Component, input } from '@angular/core'
import type { TemplateRef } from '@angular/core'
import { HILOS_TABLE_ACTIONS_KEY, HilosI18nNameRowKey } from '@hilos/core'
import type {
  HilosI18nNameCorrection,
  HilosI18nNameRow,
  TableViewportController,
} from '@hilos/core'

import { HilosTableCell } from '../../../HilosTableCell.js'
import { HilosTableDetail } from '../../../HilosTableDetail.js'
import { HilosViewportTable } from '../../../HilosViewportTable.js'

/** What the template of a row's own actions receives. */
export interface HilosI18nNameActionsContext {
  /** The names row the actions belong to (the template's implicit `let-row`). */
  $implicit: HilosI18nNameRow
}

/** What the template of a correction's actions receives. */
export interface HilosI18nCorrectionActionsContext {
  /** The names row the correction belongs to (the template's implicit `let-row`). */
  $implicit: HilosI18nNameRow
  /** The locale correction the actions belong to. */
  correction: HilosI18nNameCorrection
}

/** A plate standing where there is no value of its own, in both themes. */
const PLATE = 'badge bg-body-tertiary text-body-secondary border fw-normal'

/** The shared names view; each subject page supplies only its action controls. */
@Component({
  selector: 'hilos-i18n-names-table',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosTableCell,
    HilosTableDetail,
    HilosViewportTable,
    NgTemplateOutlet,
  ],
  template: `
    <hilos-viewport-table [controller]="controller()">
      <ng-template [hilosTableCell]="keys.code" let-row>
        <span class="text-uppercase">{{ row.code }}</span>
        <span class="text-body-secondary ms-1">{{ row.nativeName }}</span>
      </ng-template>
      <ng-template [hilosTableCell]="keys.name" let-row>
        @if (row.name === null) {
          <span [class]="plate" [attr.data-id]="'i18n-names-none-' + row.code"
            ><i class="bi bi-dash-circle me-1" aria-hidden="true"></i>No
            name</span
          >
        } @else {
          <span class="fw-medium">{{ row.name }}</span>
        }
      </ng-template>
      <ng-template [hilosTableCell]="actionsKey" let-row>
        @if (nameActions(); as actions) {
          <ng-container
            [ngTemplateOutlet]="actions"
            [ngTemplateOutletContext]="{ $implicit: row }"
          />
        }
      </ng-template>
      <ng-template [hilosTableDetail]="keys.corrections" let-row>
        <ul class="list-unstyled mb-0">
          @for (correction of row.corrections; track correction.localeId) {
            <li
              class="d-flex flex-wrap align-items-center gap-2 py-1"
              [attr.data-id]="'i18n-names-correction-' + correction.localeCode"
            >
              <i
                class="bi bi-arrow-return-right text-body-secondary"
                aria-hidden="true"
              ></i>
              <span>{{ correction.localeCode }}</span>
              <span class="text-body-secondary">{{
                correction.countryName ?? correction.countryCode.toUpperCase()
              }}</span>
              @if (correction.name === null) {
                <span
                  [class]="plate"
                  [attr.data-id]="'i18n-names-inherit-' + correction.localeCode"
                  ><i
                    class="bi bi-arrow-return-right me-1"
                    aria-hidden="true"
                  ></i
                  >as in “{{ row.nativeName }}”</span
                >
              } @else {
                <span class="fw-medium">{{ correction.name }}</span>
              }
              @if (correctionActions(); as actions) {
                <ng-container
                  [ngTemplateOutlet]="actions"
                  [ngTemplateOutletContext]="{ $implicit: row, correction }"
                />
              }
            </li>
          }
        </ul>
      </ng-template>
    </hilos-viewport-table>
  `,
})
export class HilosI18nNamesTable {
  /** The names table's controller, built by createHilosI18nNamesTable. */
  readonly controller =
    input.required<TableViewportController<HilosI18nNameRow>>()
  /** The subject page's controls for one row, stamped into its actions cell. */
  readonly nameActions = input<TemplateRef<HilosI18nNameActionsContext> | null>(
    null,
  )
  /** The subject page's controls for one correction, stamped at its end. */
  readonly correctionActions =
    input<TemplateRef<HilosI18nCorrectionActionsContext> | null>(null)

  protected readonly keys = HilosI18nNameRowKey
  protected readonly actionsKey = HILOS_TABLE_ACTIONS_KEY
  protected readonly plate = PLATE
}
