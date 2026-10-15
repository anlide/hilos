// HilosI18nCountriesPage — the Countries admin page (HilosPages.I18N_COUNTRIES):
// every country of the system in one table. The code links to the country card;
// a missing name shows the code, a missing default locale shows "Not chosen",
// a custom country carries an icon mark with its word beside it for a screen
// reader, and a switched-off country is drawn in the secondary text color. A
// custom country is created through the "Add country" main action and its
// confirmation; a known one is already in the list and the server refuses it as
// a duplicate. Each row opens the shared edit/view window — the pencil of a
// switched-off country, the eye of a switched-on one — beside its delete
// control. The table, its frame and main action, the row view-model, the
// creation flow, the row window and the catalog the page answer carries are the
// core's (createHilosI18nCountriesTable and its neighbors); this view owns only
// the markup and the opening of the delete confirmation over the row it takes
// into focus — one row in focus at a time, let go when that confirmation
// closes. Everything is bound to one project context: a new context replaces
// the binding whole — the table, the windows and their tracked actions — so an
// answer to the former context reaches nothing; the single-pass @for over the
// binding, tracked by the session it made, is what React writes as a key. The
// list keeps its address after a deletion: the removed row waits for Apply like
// any other. Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
} from '@angular/core'
import {
  HILOS_I18N_COUNTRIES_LEGEND,
  HILOS_I18N_COUNTRY_MARKS,
  HILOS_I18N_COUNTRY_WINDOW_COPY,
  HilosPages,
  createHilosI18nBuiltInCatalog,
  createHilosI18nBuiltInCountryCodes,
  createHilosI18nCountriesTable,
  createHilosI18nCountryAdd,
  createHilosI18nCountryRowDelete,
  createHilosI18nCountryRowWindow,
  hilosI18nCountryDeleteFromRow,
} from '@hilos/core'
import type {
  HilosI18nBuiltInCatalog,
  HilosI18nCountryContext,
  HilosI18nCountryDeleteTarget,
  HilosI18nCountryRow,
} from '@hilos/core'

import { HilosAdminPage } from '../../../HilosAdminPage.js'
import { HilosLink } from '../../../HilosLink.js'
import { HilosTableCell } from '../../../HilosTableCell.js'
import { HilosViewportTable } from '../../../HilosViewportTable.js'
import { HILOS_ROUTER } from '../../../hilosRouterToken.js'
import { mirrorHilosSignal } from '../../../hilosSignal.js'
import { HilosI18nCountryDelete } from '../details/HilosI18nCountryDelete.js'
import { HilosI18nCountryWindow } from '../details/HilosI18nCountryWindow.js'
import { HilosI18nCatalogNote } from './HilosI18nCatalogNote.js'
import { HilosI18nCountryAdd } from './HilosI18nCountryAdd.js'
import { HilosI18nCountryRowActions } from './HilosI18nCountryRowActions.js'
import { HilosI18nMarksLegend } from './HilosI18nMarksLegend.js'

/** The countries list with its creation, view/edit and delete windows. */
@Component({
  selector: 'hilos-i18n-countries-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosI18nCatalogNote,
    HilosI18nCountryAdd,
    HilosI18nCountryDelete,
    HilosI18nCountryRowActions,
    HilosI18nCountryWindow,
    HilosI18nMarksLegend,
    HilosLink,
    HilosTableCell,
    HilosViewportTable,
  ],
  template: `
    <hilos-admin-page [page]="page">
      @for (subject of [binding()]; track subject.window) {
        @if (builtInCatalog(); as catalog) {
          <hilos-i18n-catalog-note
            [catalog]="catalog"
            dataId="i18n-countries-catalog"
          >
            The framework's built-in catalog holds
            {{ catalog.countryCount }} countries with their currency and their
            names in its {{ catalog.languageCount }} languages; each of them
            arrives on its own, switched off. Whenever a framework update
            changes the catalog, a country's currency is refreshed while that
            country is switched off, and its names wherever nobody has edited
            them. A code that is not in the catalog can still be added as a
            country of your own, but keeping it up is on you.
          </hilos-i18n-catalog-note>
        }
        <hilos-viewport-table [controller]="subject.countries.controller">
          <ng-template hilosTableCell="code" let-row>
            @if (countryPath(row); as path) {
              <a
                [hilosLink]="path"
                [class]="
                  'text-uppercase' + (row.enabled ? '' : ' link-secondary')
                "
                [attr.data-id]="'i18n-countries-link-' + row.code"
              >
                {{ row.code }}
              </a>
            } @else {
              <span
                [class]="
                  'text-uppercase' + (row.enabled ? '' : ' text-body-secondary')
                "
                [attr.data-id]="'i18n-countries-link-' + row.code"
              >
                {{ row.code }}
              </span>
            }
          </ng-template>
          <ng-template hilosTableCell="name" let-row>
            <span [class.text-body-secondary]="!row.enabled">{{
              row.name ?? row.code.toUpperCase()
            }}</span>
            @if (row.isOwn) {
              <span
                [class]="'badge ms-1 ' + marks.own.tone"
                [title]="marks.own.label"
                [attr.data-id]="'i18n-countries-own-' + row.code"
              >
                <i [class]="'bi ' + marks.own.icon" aria-hidden="true"></i>
                <span class="visually-hidden">{{ marks.own.label }}</span>
              </span>
            }
          </ng-template>
          <ng-template hilosTableCell="currencyCode" let-row>
            <span
              class="fw-semibold"
              [class.text-body-secondary]="!row.enabled"
              >{{ row.currencySymbol }}</span
            >
            <span class="font-monospace text-body-secondary ms-1">{{
              row.currencyCode
            }}</span>
          </ng-template>
          <ng-template hilosTableCell="defaultLocaleCode" let-row>
            @if (row.defaultLocaleCode) {
              <span
                class="font-monospace"
                [class.text-body-secondary]="!row.enabled"
                >{{ row.defaultLocaleCode }}</span
              >
            } @else {
              <span
                class="badge bg-body-tertiary border text-body-secondary fw-normal"
                [attr.data-id]="'i18n-countries-locale-none-' + row.code"
              >
                <i class="bi bi-dash-circle me-1" aria-hidden="true"></i>Not
                chosen
              </span>
            }
          </ng-template>
          <ng-template hilosTableCell="enabled" let-row>
            <input
              class="form-check-input"
              type="checkbox"
              disabled
              [checked]="row.enabled"
              aria-label="Enabled"
              [attr.data-id]="'i18n-countries-enabled-' + row.code"
            />
          </ng-template>
          <ng-template hilosTableCell="actions" let-row>
            <span class="d-flex align-items-start gap-2">
              <button
                type="button"
                [class]="
                  'btn btn-sm ' +
                  (row.enabled
                    ? 'btn-outline-secondary'
                    : 'btn-outline-primary')
                "
                [title]="row.enabled ? copy.open.view : copy.open.edit"
                [attr.aria-label]="
                  row.enabled ? copy.open.view : copy.open.edit
                "
                [attr.data-id]="
                  'i18n-countries-' +
                  (row.enabled ? 'view' : 'edit') +
                  '-' +
                  row.code
                "
                (click)="
                  subject.window.open(row.enabled ? 'view' : 'edit', row.rowKey)
                "
              >
                <i
                  [class]="'bi ' + (row.enabled ? 'bi-eye' : 'bi-pencil')"
                  aria-hidden="true"
                ></i>
              </button>
              <hilos-i18n-country-row-actions
                [row]="row"
                (remove)="openDelete(row.rowKey)"
              />
            </span>
          </ng-template>
        </hilos-viewport-table>
        <hilos-i18n-marks-legend
          [rows]="legend"
          dataId="i18n-countries-legend"
          class="mt-4"
        />
        <hilos-i18n-country-add [add]="subject.add" />
        <hilos-i18n-country-window [window]="subject.window" />
        <hilos-i18n-country-delete
          [open]="deleteTarget() !== null"
          [target]="deleteTarget()"
          [source]="deleteSource()"
          [deleteCountry]="subject.deleteCountry"
          (closed)="closeDelete()"
        />
      }
    </hilos-admin-page>
  `,
})
export class HilosI18nCountriesPage {
  /** The project context: connection, scope stores, and the action lifecycle. */
  readonly context = input.required<HilosI18nCountryContext>()

  protected readonly page = HilosPages.I18N_COUNTRIES
  protected readonly marks = HILOS_I18N_COUNTRY_MARKS
  protected readonly legend = HILOS_I18N_COUNTRIES_LEGEND
  protected readonly copy = HILOS_I18N_COUNTRY_WINDOW_COPY

  private readonly router = inject(HILOS_ROUTER)

  /** The table and its windows, bound to one project context. */
  protected readonly binding = computed(() => {
    const context = this.context()
    const add = createHilosI18nCountryAdd(
      context,
      createHilosI18nBuiltInCountryCodes(context),
    )
    const countries = createHilosI18nCountriesTable(context, {
      openAdd: () => add.open(),
    })

    return {
      context,
      builtInCatalog: createHilosI18nBuiltInCatalog(context),
      add,
      countries,
      window: createHilosI18nCountryRowWindow(countries.controller, context),
      deleteCountry: createHilosI18nCountryRowDelete(context).deleteCountry,
    }
  })
  protected readonly builtInCatalog = signal<HilosI18nBuiltInCatalog | null>(
    null,
  )
  // The row the delete confirmation is about, as the table holds it in focus:
  // the server follows it wherever it goes, undefined once nothing is held.
  private readonly focusedRow = signal<HilosI18nCountryRow | undefined>(
    undefined,
  )
  /** The country the confirmation opened on; null while it is closed. */
  protected readonly deleteTarget = signal<HilosI18nCountryDeleteTarget | null>(
    null,
  )
  protected readonly deleteSource = computed(() => {
    const row = this.focusedRow()

    return row !== undefined && row.countryId === this.deleteTarget()?.countryId
      ? hilosI18nCountryDeleteFromRow(row)
      : null
  })

  constructor() {
    // Bind the table to the connection and mirror what the page answer carries;
    // a new binding, or the end of this page, unbinds it and lets its row go.
    effect((onCleanup) => {
      const binding = this.binding()
      const stops = [
        mirrorHilosSignal(binding.builtInCatalog, this.builtInCatalog),
        mirrorHilosSignal(
          binding.countries.controller.focusedRow,
          this.focusedRow,
        ),
      ]
      binding.countries.start()
      onCleanup(() => {
        for (const stop of stops) stop()
        this.deleteTarget.set(null)
        binding.countries.controller.releaseFocus()
        binding.countries.dispose()
        binding.add.dispose()
      })
    })
  }

  /**
   * The country card's address, or undefined when that page has none here.
   *
   * @param row The country row the code cell links from.
   */
  protected countryPath(row: HilosI18nCountryRow): string | undefined {
    return this.router.resolvePath(HilosPages.I18N_COUNTRY, {
      countryCode: row.code,
    })
  }

  // Take the row into focus — applying what waits on it first — and open the
  // confirmation only over a live row the server still lets go.
  protected openDelete(code: string): void {
    const controller = this.binding().countries.controller
    const fresh = controller.focusRow(code)
    if (fresh?.rowKey !== code || !fresh.canDelete) {
      controller.releaseFocus()
      return
    }
    this.deleteTarget.set(hilosI18nCountryDeleteFromRow(fresh))
  }

  // Closing lets the row go at once, so a window opened next keeps its own.
  protected closeDelete(): void {
    this.deleteTarget.set(null)
    this.binding().countries.controller.releaseFocus()
  }
}
