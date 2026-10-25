// HilosI18nLanguageLocalesPage — the locales of one language
// (HilosPages.I18N_LANGUAGE_LOCALES): a row for the language alone and one for
// every country of the system, whether the pair has a locale or not (HIL-1476);
// a pair stays a row after its locale is deleted. The table is opened on the
// language the address names — its code travels as the table's filter, and
// moving to another language on the same page asks for that language's window.
// A row shows the country by its code in capitals and its base name in the
// card's language, "— no country" on the language's own row; the locale's code
// or the "No locale" plate — a plate, not a dash, because a dash reads like a
// value; and the locale's switch as a disabled tick. A switched-off locale's text
// is muted with the secondary text color, which keeps its contrast, never with
// opacity. The row's one button opens the locale window in the mode its pair
// stands in — add, edit or view (HIL-1491) — and a stored locale has a delete
// beside it, opened over the row the table takes into focus. One row is in focus
// at a time, let go when its window closes. The shared entity head, shared
// with the main page and the names page, stand above the table, with the three
// states of the main page. The table, the card and the closed format lists are
// bound to one project context; the windows to that context and to the language
// the address names now: either changing replaces them whole, with their
// tracked actions, so an answer to the former subject reaches nothing — the
// single-pass @for over each binding, tracked by what it made, is what React
// writes as a key. Bootstrap classes only, painted with classes that follow the
// theme (styling-rules.md).
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
  HILOS_I18N_LOCALE_COPY,
  HilosI18nLocaleRowKey,
  createHilosI18nLanguageCard,
  createHilosI18nLanguageLocalesTable,
  createHilosI18nLocaleDelete,
  createHilosI18nLocaleTemplates,
  hilosI18nLocaleCountryLabel,
  hilosI18nLocaleWindowMode,
} from '@hilos/core/admin/i18n'
import {
  HILOS_TABLE_ACTIONS_KEY,
  HilosPages,
  bindPageInstanceName,
  computedSignal,
} from '@hilos/core/base'
import type {
  HilosI18nLanguageCard,
  HilosI18nLanguageContext,
  HilosI18nLocaleDeleteTarget,
  HilosI18nLocaleRow,
  HilosI18nLocaleTemplates,
} from '@hilos/core/admin/i18n'

import {
  HilosAdminPage,
  HilosTableCell,
  HilosViewportTable,
  HILOS_ROUTER,
  hilosSignal,
  mirrorHilosSignal,
} from '@hilos/angular/base'
import { HilosI18nLocaleDelete } from './HilosI18nLocaleDelete.js'
import { HilosI18nLocaleWindow } from './HilosI18nLocaleWindow.js'
import type { HilosI18nLocaleWindowOpening } from './HilosI18nLocaleWindow.js'

/** A plate standing where there is no value of its own, in both themes. */
const PLATE = 'badge bg-body-tertiary text-body-secondary border fw-normal'

/** The locales of one language with the locale window and its delete. */
@Component({
  selector: 'hilos-i18n-language-locales-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosI18nLocaleDelete,
    HilosI18nLocaleWindow,
    HilosTableCell,
    HilosViewportTable,
  ],
  template: `
    <hilos-admin-page
      [page]="page"
      headKind="entity"
      tabsLabel="Language pages"
    >
      @for (subject of [binding()]; track subject.locales) {
        @if (card(); as current) {
          <ng-template #headVisual>
            <span
              class="hilos-avatar-md rounded-3 bg-secondary-subtle text-body-emphasis d-inline-flex align-items-center justify-content-center flex-shrink-0 fw-semibold text-uppercase"
              aria-hidden="true"
              data-id="language-head-tile"
              >{{ current.code }}</span
            >
          </ng-template>
          @if (current.summary.isDefault || current.summary.isOwn) {
            <ng-template #headBadges>
              @if (current.summary.isDefault) {
                <span
                  [class]="'badge ' + marks.default.tone"
                  [title]="marks.default.label"
                  data-id="language-head-default"
                >
                  <i
                    [class]="'bi ' + marks.default.icon"
                    aria-hidden="true"
                  ></i>
                  <span class="visually-hidden">{{ marks.default.label }}</span>
                </span>
              }
              @if (current.summary.isOwn) {
                <span
                  [class]="'badge ' + marks.own.tone"
                  [title]="marks.own.label"
                  data-id="language-head-own"
                >
                  <i [class]="'bi ' + marks.own.icon" aria-hidden="true"></i>
                  <span class="visually-hidden">{{ marks.own.label }}</span>
                </span>
              }
            </ng-template>
          }
          <ng-template #headDescription>
            <p
              class="text-body-secondary mb-0"
              data-id="language-head-direction"
            >
              {{ current.rtl ? 'Right to left' : 'Left to right' }}
            </p>
          </ng-template>
          <hilos-viewport-table [controller]="subject.locales.controller">
            <ng-template [hilosTableCell]="keys.countryCode" let-row>
              @if (row.countryCode === null) {
                <span
                  class="fst-italic text-body-secondary"
                  [attr.data-id]="'i18n-locales-country-' + pairCode(row)"
                  >— no country</span
                >
              } @else {
                <span
                  [class.text-body-secondary]="row.enabled === false"
                  [attr.data-id]="'i18n-locales-country-' + pairCode(row)"
                  ><span class="text-uppercase text-body-secondary me-1">{{
                    row.countryCode
                  }}</span
                  >{{ row.countryName }}</span
                >
              }
            </ng-template>
            <ng-template [hilosTableCell]="keys.localeCode" let-row>
              @if (row.localeCode === null) {
                <span
                  [class]="plate"
                  [attr.data-id]="'i18n-locales-none-' + pairCode(row)"
                  ><i class="bi bi-dash-circle me-1" aria-hidden="true"></i>No
                  locale</span
                >
              } @else {
                <span
                  [class.text-body-secondary]="row.enabled === false"
                  [attr.data-id]="'i18n-locales-code-' + pairCode(row)"
                  >{{ row.localeCode }}</span
                >
              }
            </ng-template>
            <ng-template [hilosTableCell]="keys.enabled" let-row>
              @if (row.localeCode !== null) {
                <input
                  class="form-check-input"
                  type="checkbox"
                  disabled
                  [checked]="row.enabled === true"
                  aria-label="Enabled"
                  [attr.data-id]="'i18n-locales-enabled-' + pairCode(row)"
                />
              }
            </ng-template>
            <ng-template [hilosTableCell]="actionsKey" let-row>
              <div class="hilos-button-row">
                @switch (modeOf(row)) {
                  @case ('add') {
                    <button
                      type="button"
                      class="btn btn-sm btn-primary"
                      [title]="copy.open.add"
                      [attr.aria-label]="copy.open.add"
                      [attr.data-id]="'i18n-locales-add-' + pairCode(row)"
                      (click)="openWindow(row)"
                    >
                      <i class="bi bi-plus-lg" aria-hidden="true"></i>
                    </button>
                  }
                  @case ('edit') {
                    <button
                      type="button"
                      class="btn btn-sm btn-outline-primary"
                      [title]="copy.open.edit"
                      [attr.aria-label]="copy.open.edit"
                      [attr.data-id]="'i18n-locales-edit-' + pairCode(row)"
                      (click)="openWindow(row)"
                    >
                      <i class="bi bi-pencil" aria-hidden="true"></i>
                    </button>
                  }
                  @default {
                    <button
                      type="button"
                      class="btn btn-sm btn-outline-secondary"
                      [title]="copy.open.view"
                      [attr.aria-label]="copy.open.view"
                      [attr.data-id]="'i18n-locales-view-' + pairCode(row)"
                      (click)="openWindow(row)"
                    >
                      <i class="bi bi-eye" aria-hidden="true"></i>
                    </button>
                  }
                }
                @if (row.localeCode !== null) {
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-danger"
                    [title]="'Delete locale ' + row.localeCode"
                    [attr.aria-label]="'Delete locale ' + row.localeCode"
                    [attr.data-id]="'i18n-locales-delete-' + pairCode(row)"
                    (click)="openDelete(row)"
                  >
                    <i class="bi bi-trash" aria-hidden="true"></i>
                  </button>
                }
              </div>
            </ng-template>
          </hilos-viewport-table>
        } @else if (loading()) {
          <div
            class="placeholder-glow"
            role="status"
            aria-label="Loading language"
          >
            <span class="placeholder col-6 d-block mb-2 rounded"></span>
            <span class="placeholder col-4 d-block rounded"></span>
          </div>
        } @else {
          <p
            class="alert alert-secondary"
            role="status"
            data-id="language-card-unavailable"
          >
            Language details are no longer available.
          </p>
        }
        @for (address of [windows()]; track address.deletion) {
          <hilos-i18n-locale-window
            [context]="subject.context"
            [controller]="subject.locales.controller"
            [card]="card()"
            [templates]="templates()"
            [opened]="opened()"
            (closed)="opened.set(null)"
          />
          <hilos-i18n-locale-delete
            [open]="deleteTarget() !== null"
            [target]="deleteTarget()"
            [source]="deleteSource()"
            [deleteLocale]="address.deletion.deleteLocale"
            (closed)="closeDelete()"
          />
        }
      }
    </hilos-admin-page>
  `,
})
export class HilosI18nLanguageLocalesPage {
  /** The project context: connection, scope stores, and the action lifecycle. */
  readonly context = input.required<HilosI18nLanguageContext>()

  protected readonly page = HilosPages.I18N_LANGUAGE_LOCALES
  protected readonly copy = HILOS_I18N_LOCALE_COPY
  protected readonly keys = HilosI18nLocaleRowKey
  protected readonly actionsKey = HILOS_TABLE_ACTIONS_KEY
  protected readonly plate = PLATE
  protected readonly modeOf = hilosI18nLocaleWindowMode
  protected readonly marks = HILOS_I18N_LANGUAGE_MARKS

  private readonly router = inject(HILOS_ROUTER)
  protected readonly loading = hilosSignal(this.router.pageLoading)
  // The language the address names now — read live, never a snapshot of the
  // first mount: navigating to another language is a new subject.
  private readonly language = computedSignal(() =>
    String(this.router.currentRoute.get().params['languageCode'] ?? ''),
  )
  private readonly languageCode = hilosSignal(this.language)

  /** The card, the format lists and the locales table, bound to one context. */
  protected readonly binding = computed(() => {
    const context = this.context()
    const card = createHilosI18nLanguageCard(context)

    return {
      context,
      card,
      templates: createHilosI18nLocaleTemplates(context),
      locales: createHilosI18nLanguageLocalesTable(
        context,
        computedSignal(() =>
          card.get()?.code === this.language.get()
            ? (card.get()?.languageId ?? null)
            : null,
        ),
      ),
    }
  })
  /** The windows' deletion dispatch, bound to that context and to one language. */
  protected readonly windows = computed(() => {
    const { context, locales } = this.binding()

    return {
      languageCode: this.languageCode(),
      locales,
      deletion: createHilosI18nLocaleDelete(context),
    }
  })
  protected readonly card = signal<HilosI18nLanguageCard | null>(null)
  protected readonly templates = signal<HilosI18nLocaleTemplates | null>(null)
  // The row the table holds in focus for whichever window is open.
  private readonly focusedRow = signal<HilosI18nLocaleRow | undefined>(
    undefined,
  )
  protected readonly opened = signal<HilosI18nLocaleWindowOpening | null>(null)
  protected readonly deleteTarget = signal<HilosI18nLocaleDeleteTarget | null>(
    null,
  )
  /** The live pair the confirmation follows, while it still has its locale. */
  protected readonly deleteSource = computed(
    (): HilosI18nLocaleDeleteTarget | null => {
      const target = this.deleteTarget()
      const card = this.card()
      const row = this.focusedRow()
      if (
        target === null ||
        card?.languageId !== target.languageId ||
        row?.localeId !== target.localeId ||
        row.localeCode === null ||
        row.formats === null
      ) {
        return null
      }

      return {
        languageId: row.languageId,
        countryId: row.countryId,
        localeId: row.localeId!,
        languageCode: card.code,
        countryCode: row.countryCode,
        localeCode: row.localeCode,
        nativeName: card.nativeName,
        countryLabel: hilosI18nLocaleCountryLabel(row),
      }
    },
  )

  constructor() {
    // Bind the table to the connection and mirror the card, the format lists
    // and the focused row; a new binding, or the end of this page, unbinds it
    // and lets its row go.
    effect((onCleanup) => {
      const binding = this.binding()
      const unbindName = bindPageInstanceName(
        binding.context.scopes,
        HilosPages.I18N_LANGUAGE,
        computedSignal(() => {
          const page = binding.context.scopes.page()?.key
          if (
            page !== HilosPages.I18N_LANGUAGE &&
            page !== HilosPages.I18N_LANGUAGE_NAMES &&
            page !== HilosPages.I18N_LANGUAGE_LOCALES
          ) {
            return undefined
          }
          return binding.card.get()?.nativeName
        }),
      )
      const stops = [
        mirrorHilosSignal(binding.card, this.card),
        mirrorHilosSignal(binding.templates, this.templates),
        mirrorHilosSignal(
          binding.locales.controller.focusedRow,
          this.focusedRow,
        ),
      ]
      binding.locales.start()
      onCleanup(() => {
        unbindName()
        for (const stop of stops) stop()
        binding.locales.controller.releaseFocus()
        binding.locales.dispose()
      })
    })
    // Another language, another context, or the end of this page closes the
    // windows opened over the former subject and lets their row go.
    effect((onCleanup) => {
      const { locales } = this.windows()
      onCleanup(() => {
        this.opened.set(null)
        this.deleteTarget.set(null)
        locales.controller.releaseFocus()
      })
    })
  }

  // One window at a time, and only over the language the address still names;
  // the window takes the pair into focus through the session of its mode.
  protected pairCode(row: HilosI18nLocaleRow): string {
    return (
      row.localeCode ??
      `${this.card()?.code ?? ''}${row.countryCode === null ? '' : '-' + row.countryCode.toUpperCase()}`
    )
  }

  protected openWindow(row: HilosI18nLocaleRow): void {
    if (
      this.opened() !== null ||
      this.deleteTarget() !== null ||
      this.card()?.code !== this.language.get()
    )
      return
    this.opened.set({
      rowKey: row.rowKey,
      mode: hilosI18nLocaleWindowMode(row),
    })
  }

  // Take the row into focus — applying what waits on it first — and open the
  // confirmation only over a live stored locale of the language the address
  // still names, fixing its codes and labels from that row.
  protected openDelete(row: HilosI18nLocaleRow): void {
    if (this.opened() !== null || this.deleteTarget() !== null) return
    const { locales, card } = this.binding()
    const fresh = locales.controller.focusRow(row.rowKey)
    const subject = card.get()
    if (
      fresh === null ||
      fresh.localeCode === null ||
      fresh.formats === null ||
      subject?.code !== this.language.get()
    ) {
      locales.controller.releaseFocus()
      return
    }
    this.deleteTarget.set({
      languageId: fresh.languageId,
      countryId: fresh.countryId,
      localeId: fresh.localeId!,
      languageCode: subject.code,
      countryCode: fresh.countryCode,
      localeCode: fresh.localeCode,
      nativeName: subject.nativeName,
      countryLabel: hilosI18nLocaleCountryLabel(fresh),
    })
  }

  // Closing lets the row go at once, so a window opened next keeps its own.
  protected closeDelete(): void {
    this.deleteTarget.set(null)
    this.binding().locales.controller.releaseFocus()
  }
}
