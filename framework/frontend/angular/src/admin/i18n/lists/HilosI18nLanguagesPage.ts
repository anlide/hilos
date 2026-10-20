// HilosI18nLanguagesPage — the Languages admin page (HilosPages.I18N_LANGUAGES):
// every language of the system in one table. The code links to the language
// card; default and custom are icon marks with their words beside them for a
// screen reader; rtl and enabled are read-only ticks; a switched-off language is
// drawn in the secondary text color. The table, its frame and main action, the
// row view-model, the creation flow, the row window and the catalog the page
// answer carries are the core's (createHilosI18nLanguagesTable and its
// neighbors); this view owns only the markup and the opening of the delete
// confirmation over the row it takes into focus — one row in focus at a time,
// let go when that confirmation closes. Everything is bound to one project
// context: a new context replaces the binding whole — the table, the windows and
// their tracked actions — so an answer to the former context reaches nothing;
// the single-pass @for over the binding, tracked by the session it made, is
// what React writes as a key. The list keeps its address after a deletion: the
// removed row waits for Apply like any other. Bootstrap classes only
// (styling-rules.md).
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
  HILOS_I18N_LANGUAGE_MARKS,
  HILOS_I18N_LANGUAGE_ROW_DELETE_ACTION,
  HILOS_I18N_LANGUAGES_LEGEND,
  createHilosI18nBuiltInCatalog,
  createHilosI18nBuiltInLanguages,
  createHilosI18nLanguageAdd,
  createHilosI18nLanguageRowWindow,
  createHilosI18nLanguagesTable,
  hilosI18nLanguageDelete,
} from '@hilos/core/admin/i18n'
import { HilosPages } from '@hilos/core/base'
import type {
  HilosI18nBuiltInCatalog,
  HilosI18nBuiltInLanguage,
  HilosI18nLanguageContext,
  HilosI18nLanguageRow,
} from '@hilos/core/admin/i18n'

import {
  HilosAdminPage,
  HilosLink,
  HilosTableCell,
  HilosViewportTable,
  HILOS_ROUTER,
  mirrorHilosSignal,
} from '@hilos/angular/base'
import { HilosI18nLanguageDelete } from '../details/HilosI18nLanguageDelete.js'
import { HilosI18nLanguageWindow } from '../details/HilosI18nLanguageWindow.js'
import { HilosI18nCatalogNote } from './HilosI18nCatalogNote.js'
import { HilosI18nLanguageAdd } from './HilosI18nLanguageAdd.js'
import { HilosI18nLanguageRowActions } from './HilosI18nLanguageRowActions.js'
import { HilosI18nMarksLegend } from './HilosI18nMarksLegend.js'

/** The languages list with its creation, view/edit and delete windows. */
@Component({
  selector: 'hilos-i18n-languages-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosI18nCatalogNote,
    HilosI18nLanguageAdd,
    HilosI18nLanguageDelete,
    HilosI18nLanguageRowActions,
    HilosI18nLanguageWindow,
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
            dataId="i18n-languages-catalog"
          >
            The framework's built-in catalog holds
            {{ builtInLanguages()?.length ?? catalog.languageCount }} ISO 639-1
            codes with their own names and writing direction. A language from it
            is added by its code alone — the rest is filled in — and is
            refreshed whenever a framework update changes the catalog, but only
            while that language is switched off. A code that is not in the
            catalog can still be added, but keeping that language up is on you.
          </hilos-i18n-catalog-note>
        }
        <hilos-viewport-table [controller]="subject.languages.controller">
          <ng-template hilosTableCell="code" let-row>
            @if (languagePath(row); as path) {
              <a
                [hilosLink]="path"
                [class]="
                  'text-uppercase' + (row.enabled ? '' : ' link-secondary')
                "
                [attr.data-id]="'i18n-languages-link-' + row.code"
              >
                {{ row.code }}
              </a>
            } @else {
              <span
                [class]="
                  'text-uppercase' + (row.enabled ? '' : ' text-body-secondary')
                "
                [attr.data-id]="'i18n-languages-link-' + row.code"
              >
                {{ row.code }}
              </span>
            }
          </ng-template>
          <ng-template hilosTableCell="nativeName" let-row>
            <span [class.text-body-secondary]="!row.enabled">{{
              row.nativeName
            }}</span>
            @if (row.isDefault) {
              <span
                [class]="'badge ms-1 ' + marks.default.tone"
                [title]="marks.default.label"
                [attr.data-id]="'i18n-languages-default-' + row.code"
              >
                <i [class]="'bi ' + marks.default.icon" aria-hidden="true"></i>
                <span class="visually-hidden">{{ marks.default.label }}</span>
              </span>
            }
            @if (row.isOwn) {
              <span
                [class]="'badge ms-1 ' + marks.own.tone"
                [title]="marks.own.label"
                [attr.data-id]="'i18n-languages-own-' + row.code"
              >
                <i [class]="'bi ' + marks.own.icon" aria-hidden="true"></i>
                <span class="visually-hidden">{{ marks.own.label }}</span>
              </span>
            }
          </ng-template>
          <ng-template hilosTableCell="rtl" let-row>
            <input
              class="form-check-input"
              type="checkbox"
              disabled
              [checked]="row.rtl"
              aria-label="Right to left"
              [attr.data-id]="'i18n-languages-rtl-' + row.code"
            />
          </ng-template>
          <ng-template hilosTableCell="enabled" let-row>
            <input
              class="form-check-input"
              type="checkbox"
              disabled
              [checked]="row.enabled"
              aria-label="Enabled"
              [attr.data-id]="'i18n-languages-enabled-' + row.code"
            />
          </ng-template>
          <ng-template hilosTableCell="actions" let-row>
            <hilos-i18n-language-row-actions
              [row]="row"
              (edit)="subject.window.open(row.rowKey)"
              (remove)="openDelete(row.rowKey)"
            />
          </ng-template>
        </hilos-viewport-table>
        <hilos-i18n-marks-legend
          [rows]="legend"
          dataId="i18n-languages-legend"
          class="mt-4"
        />
        <hilos-i18n-language-add
          [add]="subject.add"
          [context]="subject.context"
        />
        <hilos-i18n-language-window [window]="subject.window" />
        <hilos-i18n-language-delete
          [open]="deleteKey() !== null"
          [context]="subject.context"
          [source]="deleteSource()"
          [actionName]="deleteAction"
          (closed)="closeDelete()"
        />
      }
    </hilos-admin-page>
  `,
})
export class HilosI18nLanguagesPage {
  /** The project context: connection, scope stores, and the action lifecycle. */
  readonly context = input.required<HilosI18nLanguageContext>()

  protected readonly page = HilosPages.I18N_LANGUAGES
  protected readonly marks = HILOS_I18N_LANGUAGE_MARKS
  protected readonly legend = HILOS_I18N_LANGUAGES_LEGEND
  protected readonly deleteAction = HILOS_I18N_LANGUAGE_ROW_DELETE_ACTION

  private readonly router = inject(HILOS_ROUTER)

  /** The table and its windows, bound to one project context. */
  protected readonly binding = computed(() => {
    const context = this.context()
    const builtInLanguages = createHilosI18nBuiltInLanguages(context)
    const add = createHilosI18nLanguageAdd(context, builtInLanguages)
    const languages = createHilosI18nLanguagesTable(context, {
      openAdd: () => add.open(),
    })

    return {
      context,
      builtInCatalog: createHilosI18nBuiltInCatalog(context),
      builtInLanguages,
      add,
      languages,
      window: createHilosI18nLanguageRowWindow(languages.controller, context),
    }
  })
  protected readonly builtInCatalog = signal<HilosI18nBuiltInCatalog | null>(
    null,
  )
  protected readonly builtInLanguages = signal<
    readonly HilosI18nBuiltInLanguage[] | null
  >(null)
  // The row the delete confirmation is about, as the table holds it in focus:
  // the server follows it wherever it goes, undefined once nothing is held.
  private readonly focusedRow = signal<HilosI18nLanguageRow | undefined>(
    undefined,
  )
  protected readonly deleteKey = signal<string | null>(null)
  protected readonly deleteSource = computed(() => {
    const row = this.focusedRow()

    return row !== undefined && row.rowKey === this.deleteKey() ? row : null
  })

  constructor() {
    // Bind the table to the connection and mirror what the page answer carries;
    // a new binding, or the end of this page, unbinds it and lets its row go.
    effect((onCleanup) => {
      const binding = this.binding()
      const stops = [
        mirrorHilosSignal(binding.builtInCatalog, this.builtInCatalog),
        mirrorHilosSignal(binding.builtInLanguages, this.builtInLanguages),
        mirrorHilosSignal(
          binding.languages.controller.focusedRow,
          this.focusedRow,
        ),
      ]
      binding.languages.start()
      onCleanup(() => {
        for (const stop of stops) stop()
        this.deleteKey.set(null)
        binding.languages.controller.releaseFocus()
        binding.languages.dispose()
        binding.add.dispose()
      })
    })
  }

  /**
   * The language card's address, or undefined when that page has none here.
   *
   * @param row The language row the code cell links from.
   */
  protected languagePath(row: HilosI18nLanguageRow): string | undefined {
    return this.router.resolvePath(HilosPages.I18N_LANGUAGE, {
      languageCode: row.code,
    })
  }

  // Take the row into focus — applying what waits on it first — and open the
  // confirmation only over a live row the server still lets go.
  protected openDelete(code: string): void {
    const controller = this.binding().languages.controller
    const fresh = controller.focusRow(code)
    if (fresh?.rowKey !== code || hilosI18nLanguageDelete(fresh).disabled) {
      controller.releaseFocus()
      return
    }
    this.deleteKey.set(fresh.rowKey)
  }

  // Closing lets the row go at once, so a window opened next keeps its own.
  protected closeDelete(): void {
    this.deleteKey.set(null)
    this.binding().languages.controller.releaseFocus()
  }
}
