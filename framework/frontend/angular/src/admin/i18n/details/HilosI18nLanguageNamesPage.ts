// HilosI18nLanguageNamesPage — the names of one language
// (HilosPages.I18N_LANGUAGE_NAMES): how the language the address names is called
// in every other language of the system (HIL-1477). The shared entity head,
// shared with the main page and the locales page, stands above the one names
// table, with the three states of the main page: the loading skeleton, the card
// with its table, and the unavailable alert once it is gone (HIL-1479). The
// table, the card, the add/edit window and the deletion dispatch are the core's
// (createHilosI18nNamesTable and its neighbors); this view owns the buttons it
// hands the table — a plus where nothing is stored (null), a pencil over a stored
// value even when it is empty ('') — and the opening of the delete confirmation
// over the row it takes into focus. One row is in focus at a time: opening one
// window lets the other go first, and closing a window lets go only of its own.
// The confirmation is opened only over a fresh row of the language the address
// still names, with the value it removes fixed from that row. The table and the
// card are bound to one project context and follow the address's language
// themselves — another language is a new window of the same table. The windows
// are bound to that context and to the language the address names now: either
// changing replaces them whole, with their tracked actions, so an answer to the
// former subject reaches nothing; the single-pass @for over each binding,
// tracked by what it made, is what React writes as a key. Bootstrap classes
// only (styling-rules.md).
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
  HILOS_I18N_LANGUAGE_NAMES,
  HILOS_I18N_LANGUAGE_NAME_COPY,
  HILOS_I18N_LANGUAGE_NAME_DELETE_COPY,
  HILOS_I18N_LANGUAGE_MARKS,
  createHilosI18nLanguageCard,
  createHilosI18nLanguageNameWindow,
  createHilosI18nNamesTable,
} from '@hilos/core/admin/i18n'
import {
  HilosPages,
  bindPageInstanceName,
  computedSignal,
} from '@hilos/core/base'
import type {
  HilosI18nLanguageCard,
  HilosI18nLanguageContext,
  HilosI18nLanguageNameDeleteTarget,
  HilosI18nNameRow,
} from '@hilos/core/admin/i18n'

import {
  HilosAdminPage,
  LoadingButton,
  HILOS_ROUTER,
  hilosSignal,
  mirrorHilosSignal,
} from '@hilos/angular/base'
import { HilosI18nLanguageNameDelete } from './HilosI18nLanguageNameDelete.js'
import { HilosI18nLanguageNameWindow } from './HilosI18nLanguageNameWindow.js'
import { HilosI18nNamesTable } from './HilosI18nNamesTable.js'

/** The names of one language with their add/edit and delete windows. */
@Component({
  selector: 'hilos-i18n-language-names-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosI18nLanguageNameDelete,
    HilosI18nLanguageNameWindow,
    HilosI18nNamesTable,
    LoadingButton,
  ],
  template: `
    <hilos-admin-page
      [page]="page"
      headKind="entity"
      tabsLabel="Language pages"
    >
      @for (subject of [binding()]; track subject.names) {
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
          <hilos-i18n-names-table
            [controller]="subject.names.controller"
            [nameActions]="nameActions"
            [correctionActions]="correctionActions"
          />
          <ng-template #nameActions let-row>
            <button
              type="button"
              [class]="
                'btn btn-sm ' +
                (row.name === null ? 'btn-primary' : 'btn-outline-primary')
              "
              [attr.aria-label]="
                nameCopy.open(
                  row.name === null,
                  current.nativeName,
                  row.nativeName
                )
              "
              [attr.data-id]="
                'i18n-language-name-' +
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
                [class]="
                  'btn btn-sm ' +
                  (correction.name === null
                    ? 'btn-primary'
                    : 'btn-outline-primary')
                "
                [attr.aria-label]="
                  nameCopy.open(
                    correction.name === null,
                    current.nativeName,
                    row.nativeName,
                    correction.localeCode
                  )
                "
                [attr.data-id]="
                  'i18n-language-name-locale-' +
                  (correction.name === null ? 'add' : 'edit') +
                  '-' +
                  correction.localeCode
                "
                (click)="openName(row, correction.localeId)"
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
                (click)="openDelete(row, correction.localeId)"
              >
                <i class="bi bi-trash" aria-hidden="true"></i>
              </button>
            }
          </ng-template>
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
        @for (address of [windows()]; track address.window) {
          <hilos-i18n-language-name-delete
            [open]="deleteOpen()"
            [context]="subject.context"
            [target]="target()"
            [source]="source()"
            (closed)="closeDelete()"
          />
          <hilos-i18n-language-name-window [window]="address.window" />
        }
      }
    </hilos-admin-page>
  `,
})
export class HilosI18nLanguageNamesPage {
  /** The project context: connection, scope stores, and the action lifecycle. */
  readonly context = input.required<HilosI18nLanguageContext>()

  protected readonly page = HilosPages.I18N_LANGUAGE_NAMES
  protected readonly nameCopy = HILOS_I18N_LANGUAGE_NAME_COPY
  protected readonly copy = HILOS_I18N_LANGUAGE_NAME_DELETE_COPY
  protected readonly marks = HILOS_I18N_LANGUAGE_MARKS

  private readonly router = inject(HILOS_ROUTER)
  protected readonly loading = hilosSignal(this.router.pageLoading)
  // The language the address names now — read live, never a snapshot of the
  // first mount: navigating to another language is a new subject.
  private readonly language = computedSignal(() =>
    String(this.router.currentRoute.get().params['languageCode'] ?? ''),
  )
  private readonly languageCode = hilosSignal(this.language)

  /** The card and the names table, bound to one context. */
  protected readonly binding = computed(() => {
    const context = this.context()
    const card = createHilosI18nLanguageCard(context)

    return {
      context,
      card,
      names: createHilosI18nNamesTable(
        context,
        HILOS_I18N_LANGUAGE_NAMES,
        computedSignal(() =>
          card.get()?.code === this.language.get()
            ? (card.get()?.languageId ?? null)
            : null,
        ),
      ),
    }
  })
  /** The name window, bound to that context and to one language. */
  protected readonly windows = computed(() => {
    const { context, card, names } = this.binding()

    return {
      languageCode: this.languageCode(),
      names,
      window: createHilosI18nLanguageNameWindow(
        names.controller,
        card,
        this.language,
        context,
      ),
    }
  })
  protected readonly card = signal<HilosI18nLanguageCard | null>(null)
  // The row the table holds in focus for whichever window is open.
  private readonly focusedRow = signal<HilosI18nNameRow | undefined>(undefined)
  protected readonly deleteOpen = signal(false)
  protected readonly target = signal<HilosI18nLanguageNameDeleteTarget | null>(
    null,
  )
  /** The live row the confirmation follows, while it is still the one it opened over. */
  protected readonly source = computed(() => {
    const target = this.target()
    const row = this.focusedRow()

    return target !== null &&
      this.card()?.languageId === target.languageId &&
      this.languageCode() === target.languageCode &&
      row?.inLanguageId === target.inLanguageId
      ? row
      : null
  })
  // Every opening of the confirmation and every closing of it counts once, so
  // an opening that a close overtook never shows a target.
  private opening = 0

  constructor() {
    // Bind the table to the connection and mirror the card and the focused row;
    // a new binding, or the end of this page, unbinds it and lets its row go.
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
        mirrorHilosSignal(binding.names.controller.focusedRow, this.focusedRow),
      ]
      binding.names.start()
      onCleanup(() => {
        unbindName()
        for (const stop of stops) stop()
        binding.names.controller.releaseFocus()
        binding.names.dispose()
      })
    })
    // Another language, another context, or the end of this page drops the
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

  // Opening the name window closes the confirmation first, so the window takes
  // the focus the confirmation let go and keeps it.
  protected openName(
    row: HilosI18nNameRow,
    localeId: number | null = null,
  ): void {
    const { window } = this.windows()
    if (window.saving.get()) return
    this.closeDelete()
    window.open(row.rowKey, localeId)
  }

  // Take the row into focus — applying what waits on it first — and open the
  // confirmation only over a fresh row of the language the address still
  // names, fixing the value it removes from that row.
  protected openDelete(row: HilosI18nNameRow, localeId?: number): void {
    const binding = this.binding()
    const { window } = this.windows()
    if (this.deleteOpen() || window.saving.get()) return
    window.close()
    const attempt = ++this.opening
    const languageCode = this.language.get()
    const resolved = binding.names.controller.focusRow(row.rowKey)
    if (attempt !== this.opening) return
    const fresh = binding.names.controller.focusedRow.get()
    const subject = binding.card.get()
    if (
      resolved === null ||
      this.language.get() !== languageCode ||
      subject?.code !== languageCode ||
      fresh?.inLanguageId !== row.inLanguageId ||
      fresh.name === null
    ) {
      this.closeDelete()
      return
    }
    const correction =
      localeId === undefined
        ? null
        : fresh.corrections.find((item) => item.localeId === localeId)
    const expectedName = localeId === undefined ? fresh.name : correction?.name
    const nameId = localeId === undefined ? fresh.nameId : correction?.nameId
    if (expectedName == null || nameId == null) {
      this.closeDelete()
      return
    }
    const coordinates = {
      languageId: subject.languageId,
      inLanguageId: fresh.inLanguageId,
      nameId,
      languageCode,
      languageNativeName: subject.nativeName,
      inLanguageCode: fresh.code,
      inLanguageNativeName: fresh.nativeName,
      expectedName,
    }
    this.target.set(
      localeId === undefined
        ? { ...coordinates, kind: 'base' }
        : {
            ...coordinates,
            kind: 'override',
            localeId,
            localeCode: correction!.localeCode,
          },
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
