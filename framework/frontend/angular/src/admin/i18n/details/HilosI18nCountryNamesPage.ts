// HilosI18nCountryNamesPage — the names of one country
// (HilosPages.I18N_COUNTRY_NAMES): how the country the address names is called
// in every language of the system (HIL-1483). The card's header and tabs,
// shared with the main country page, stand above the one names table, with the
// three states of the main page: the loading skeleton, the card with its table,
// and the unavailable alert once it is gone. The table, the card, the add/edit
// window and the deletion dispatch are the core's (createHilosI18nNamesTable
// and its neighbors); this view owns the buttons it hands the table — a plus
// where nothing is stored (null), a pencil over a stored value even when it is
// empty ('') — and the opening of the delete confirmation over the row it takes
// into focus. A correction is offered wherever a base is stored, an empty one
// included. One row is in focus at a time: opening one window lets the other go
// first, closing a window lets go only of its own, and neither window opens
// over the other's write in flight. The confirmation is opened only over a
// fresh row of the country the address still names, with the value it removes
// fixed from that row. The table and the card are bound to one project context
// and follow the address's country themselves — another country is a new
// window of the same table. The windows are bound to that context and to the
// country the address names now: either changing replaces them whole, with
// their tracked actions, so an answer to the former subject reaches nothing;
// the single-pass @for over each binding, tracked by what it made, is what
// React writes as a key. Bootstrap classes only, painted with classes that
// follow the theme (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
  viewChild,
} from '@angular/core'
import {
  HILOS_I18N_COUNTRY_NAMES,
  HILOS_I18N_COUNTRY_NAME_COPY,
  HILOS_I18N_COUNTRY_NAME_DELETE_COPY,
  HilosPages,
  computedSignal,
  createHilosI18nCountryCard,
  createHilosI18nCountryNameWindow,
  createHilosI18nNamesTable,
} from '@hilos/core'
import type {
  HilosI18nCountryCard,
  HilosI18nCountryContext,
  HilosI18nCountryNameDeleteTarget,
  HilosI18nNameRow,
} from '@hilos/core'

import { HilosAdminPage } from '../../../HilosAdminPage.js'
import { LoadingButton } from '../../../LoadingButton.js'
import { HILOS_ROUTER } from '../../../hilosRouterToken.js'
import { hilosSignal, mirrorHilosSignal } from '../../../hilosSignal.js'
import { HilosI18nCountryHeader } from './HilosI18nCountryHeader.js'
import { HilosI18nCountryNameDelete } from './HilosI18nCountryNameDelete.js'
import { HilosI18nCountryNameWindow } from './HilosI18nCountryNameWindow.js'
import { HilosI18nNamesTable } from './HilosI18nNamesTable.js'

/** The names of one country with their add/edit and delete windows. */
@Component({
  selector: 'hilos-i18n-country-names-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosI18nCountryHeader,
    HilosI18nCountryNameDelete,
    HilosI18nCountryNameWindow,
    HilosI18nNamesTable,
    LoadingButton,
  ],
  template: `
    <hilos-admin-page [page]="page">
      @for (subject of [binding()]; track subject.names) {
        @if (card(); as current) {
          <hilos-i18n-country-header [card]="current" [activePage]="page" />
          <section class="mb-4" data-id="country-names">
            <h2 class="h5 d-flex flex-wrap align-items-center gap-2">
              Names in different languages
              <span
                class="badge bg-body-tertiary text-body-secondary border fw-normal"
                >A row for every language</span
              >
            </h2>
            <hilos-i18n-names-table
              [controller]="subject.names.controller"
              [nameActions]="nameActions"
              [correctionActions]="correctionActions"
            />
          </section>
          <ng-template #nameActions let-row>
            <button
              type="button"
              class="btn btn-sm btn-outline-secondary"
              [attr.aria-label]="
                nameCopy.open(row.name === null, label(current), row.nativeName)
              "
              [attr.data-id]="
                'i18n-country-name-' +
                (row.name === null ? 'add' : 'edit') +
                '-' +
                row.code
              "
              (click)="openName(row)"
            >
              <i
                [class]="
                  'bi ' + (row.name === null ? 'bi-plus-lg' : 'bi-pencil')
                "
                aria-hidden="true"
              ></i>
            </button>
            @if (row.name !== null) {
              <button
                hilosLoadingButton
                class="btn-sm btn-outline-danger"
                [attr.aria-label]="copy.baseLabel(row.nativeName)"
                [attr.data-id]="'i18n-names-delete-' + row.code"
                (click)="openDelete(row)"
              >
                <i class="bi bi-trash" aria-hidden="true"></i>
              </button>
            }
          </ng-template>
          <ng-template #correctionActions let-row let-correction="correction">
            @if (row.name !== null) {
              <button
                type="button"
                class="btn btn-sm btn-outline-secondary"
                [attr.aria-label]="
                  nameCopy.open(
                    correction.name === null,
                    label(current),
                    row.nativeName,
                    correction.localeCode
                  )
                "
                [attr.data-id]="
                  'i18n-country-name-locale-' +
                  (correction.name === null ? 'add' : 'edit') +
                  '-' +
                  correction.localeCode
                "
                (click)="openName(row, correction.localeCode)"
              >
                <i
                  [class]="
                    'bi ' +
                    (correction.name === null ? 'bi-plus-lg' : 'bi-pencil')
                  "
                  aria-hidden="true"
                ></i>
              </button>
            }
            @if (correction.name !== null) {
              <button
                hilosLoadingButton
                class="btn-sm btn-outline-danger"
                [attr.aria-label]="copy.overrideLabel(correction.localeCode)"
                [attr.data-id]="'i18n-names-delete-' + correction.localeCode"
                (click)="openDelete(row, correction.localeCode)"
              >
                <i class="bi bi-trash" aria-hidden="true"></i>
              </button>
            }
          </ng-template>
        } @else if (loading()) {
          <div
            class="placeholder-glow"
            role="status"
            aria-label="Loading country"
          >
            <span class="placeholder col-6 d-block mb-2 rounded"></span>
            <span class="placeholder col-4 d-block rounded"></span>
          </div>
        } @else {
          <p
            class="alert alert-secondary"
            role="status"
            data-id="country-names-unavailable"
          >
            Country details are no longer available.
          </p>
        }
        @for (address of [windows()]; track address.window) {
          <hilos-i18n-country-name-delete
            [open]="deleteOpen()"
            [context]="subject.context"
            [target]="target()"
            [source]="source()"
            (closed)="closeDelete()"
          />
          <hilos-i18n-country-name-window [window]="address.window" />
        }
      }
    </hilos-admin-page>
  `,
})
export class HilosI18nCountryNamesPage {
  /** The project context: connection, scope stores, and the action lifecycle. */
  readonly context = input.required<HilosI18nCountryContext>()

  protected readonly page = HilosPages.I18N_COUNTRY_NAMES
  protected readonly nameCopy = HILOS_I18N_COUNTRY_NAME_COPY
  protected readonly copy = HILOS_I18N_COUNTRY_NAME_DELETE_COPY

  private readonly router = inject(HILOS_ROUTER)
  protected readonly loading = hilosSignal(this.router.pageLoading)
  // The country the address names now — read live, never a snapshot of the
  // first mount: navigating to another country is a new subject.
  private readonly country = computedSignal(() =>
    String(this.router.currentRoute.get().params['countryCode'] ?? ''),
  )
  private readonly countryCode = hilosSignal(this.country)

  /** The card and the names table, bound to one context. */
  protected readonly binding = computed(() => {
    const context = this.context()

    return {
      context,
      card: createHilosI18nCountryCard(context),
      names: createHilosI18nNamesTable(
        context,
        HILOS_I18N_COUNTRY_NAMES,
        this.country,
      ),
    }
  })
  /** The name window, bound to that context and to one country. */
  protected readonly windows = computed(() => {
    const { context, card, names } = this.binding()

    return {
      countryCode: this.countryCode(),
      names,
      window: createHilosI18nCountryNameWindow(
        names.controller,
        card,
        this.country,
        context,
      ),
    }
  })
  protected readonly card = signal<HilosI18nCountryCard | null>(null)
  // The row the table holds in focus for whichever window is open.
  private readonly focusedRow = signal<HilosI18nNameRow | undefined>(undefined)
  protected readonly deleteOpen = signal(false)
  protected readonly target = signal<HilosI18nCountryNameDeleteTarget | null>(
    null,
  )
  /** The live row the confirmation follows, while it is still the one it opened over. */
  protected readonly source = computed(() => {
    const target = this.target()
    const row = this.focusedRow()

    return target !== null &&
      this.card()?.code === target.countryCode &&
      this.countryCode() === target.countryCode &&
      row?.code === target.inLanguageCode
      ? row
      : null
  })
  // The confirmation standing now, read for whether its write is in flight.
  private readonly deleteWindow = viewChild(HilosI18nCountryNameDelete)
  // Every opening of the confirmation and every closing of it counts once, so
  // an opening that a close overtook never shows a target.
  private opening = 0

  constructor() {
    // Bind the table to the connection and mirror the card and the focused row;
    // a new binding, or the end of this page, unbinds it and lets its row go.
    effect((onCleanup) => {
      const binding = this.binding()
      const stops = [
        mirrorHilosSignal(binding.card, this.card),
        mirrorHilosSignal(binding.names.controller.focusedRow, this.focusedRow),
      ]
      binding.names.start()
      onCleanup(() => {
        for (const stop of stops) stop()
        binding.names.controller.releaseFocus()
        binding.names.dispose()
      })
    })
    // Another country, another context, or the end of this page drops the
    // confirmation opened over the former subject — and an opening still under
    // way — and lets its row go.
    effect((onCleanup) => {
      const { names } = this.windows()
      onCleanup(() => {
        this.opening++
        this.deleteOpen.set(false)
        this.target.set(null)
        names.controller.releaseFocus()
      })
    })
  }

  /**
   * The country as the buttons name it: its name, or its code in capitals.
   *
   * @param card The country card the page stands on.
   */
  protected label(card: HilosI18nCountryCard): string {
    return card.summary.name ?? card.code.toUpperCase()
  }

  // Opening the name window closes the confirmation first, so the window takes
  // the focus the confirmation let go and keeps it; neither interrupts the
  // other's write in flight.
  protected openName(
    row: HilosI18nNameRow,
    localeCode: string | null = null,
  ): void {
    const { window } = this.windows()
    if (window.saving.get() || this.deleteWindow()?.busy()) return
    this.closeDelete()
    window.open(row.code, localeCode)
  }

  // Take the row into focus — applying what waits on it first — and open the
  // confirmation only over a fresh row of the country the address still
  // names, fixing the value it removes from that row.
  protected openDelete(row: HilosI18nNameRow, localeCode?: string): void {
    const binding = this.binding()
    const { window } = this.windows()
    if (this.deleteOpen() || window.saving.get()) return
    window.close()
    const attempt = ++this.opening
    const countryCode = this.country.get()
    const resolved = binding.names.controller.focusRow(row.code)
    if (attempt !== this.opening) return
    const fresh = binding.names.controller.focusedRow.get()
    const subject = binding.card.get()
    if (
      resolved === null ||
      this.country.get() !== countryCode ||
      subject?.code !== countryCode ||
      fresh?.code !== row.code ||
      fresh.name === null
    ) {
      this.closeDelete()
      return
    }
    const expectedName =
      localeCode === undefined
        ? fresh.name
        : fresh.corrections.find((item) => item.localeCode === localeCode)?.name
    if (expectedName == null) {
      this.closeDelete()
      return
    }
    const coordinates = {
      countryCode,
      countryName: subject.summary.name || subject.code.toUpperCase(),
      inLanguageCode: fresh.code,
      inLanguageNativeName: fresh.nativeName,
      expectedName,
    }
    this.target.set(
      localeCode === undefined
        ? { ...coordinates, kind: 'base' }
        : { ...coordinates, kind: 'override', localeCode },
    )
    this.deleteOpen.set(true)
  }

  // Closing lets the row go at once, before any window opened next takes its own.
  protected closeDelete(): void {
    this.opening++
    this.deleteOpen.set(false)
    this.binding().names.controller.releaseFocus()
  }
}
