// HilosI18nLanguagePage — the main card of one language (HilosPages.I18N_LANGUAGE):
// the shared entity head, the main fields with the view/edit window, and the
// state — switching on and off, and deleting. The card is one page-scoped datum
// the page answer brings and its live updates refresh
// (createHilosI18nLanguageCard); the switches and the delete read their verdicts
// from it, and the server checks every one of them again. The windows belong to
// one binding: the project context and the language the address names now, read
// live from the navigator. A new context or another language replaces the
// binding whole — its windows, their tracked actions and their sessions — so an
// answer to the former subject reaches nothing; the single-pass @for over the
// binding, tracked by the session it made, is what React writes as a key. A
// deletion returns to the list only after its `::success`; until then the
// window stands over the card its own echo took away. Bootstrap classes only
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
  HILOS_I18N_LANGUAGE_DELETE_ACTION,
  HILOS_I18N_LANGUAGE_DELETE_COPY,
  HILOS_I18N_LANGUAGE_MARKS,
  HILOS_I18N_LANGUAGE_WINDOW_COPY,
  createHilosI18nLanguageCard,
  createHilosI18nLanguageCardWindow,
  hilosI18nLanguageDelete,
} from '@hilos/core/admin/i18n'
import {
  HilosPages,
  bindPageInstanceName,
  computedSignal,
  hilosTabLinks,
} from '@hilos/core/base'
import type {
  HilosI18nLanguageCard,
  HilosI18nLanguageContext,
} from '@hilos/core/admin/i18n'

import {
  HilosAdminPage,
  HilosLink,
  HILOS_ROUTER,
  hilosSignal,
  mirrorHilosSignal,
} from '@hilos/angular/base'
import { HilosI18nLanguageDelete } from './HilosI18nLanguageDelete.js'
import type { HilosI18nLanguageDeleteSource } from './HilosI18nLanguageDelete.js'
import { HilosI18nLanguageSwitchOff } from './HilosI18nLanguageSwitchOff.js'
import { HilosI18nLanguageSwitchOn } from './HilosI18nLanguageSwitchOn.js'
import { HilosI18nLanguageWindow } from './HilosI18nLanguageWindow.js'

/** The language main card with its view/edit, switch and delete windows. */
@Component({
  selector: 'hilos-i18n-language-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosLink,
    HilosI18nLanguageDelete,
    HilosI18nLanguageSwitchOff,
    HilosI18nLanguageSwitchOn,
    HilosI18nLanguageWindow,
  ],
  template: `
    <hilos-admin-page
      [page]="page"
      headKind="entity"
      tabsLabel="Language pages"
    >
      @for (subject of [binding()]; track subject.window) {
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

          <div class="row row-cols-1 row-cols-md-3 g-3 mb-3">
            <div class="col">
              <section
                class="border rounded-3 p-3 h-100"
                data-id="language-overview-names"
              >
                <h2 class="small text-body-secondary">Names</h2>
                <div class="fs-4 fw-semibold lh-sm my-1">
                  @if (namesPath(); as path) {
                    <a
                      [hilosLink]="path"
                      [attr.aria-label]="'Names: ' + current.summary.nameCount"
                      data-id="language-overview-name-count"
                      >{{ current.summary.nameCount }}</a
                    >
                  } @else {
                    <span data-id="language-overview-name-count">{{
                      current.summary.nameCount
                    }}</span>
                  }
                </div>
                <p class="small text-body-secondary mb-0">
                  Non-empty base names in other languages; corrections do not
                  count.
                </p>
              </section>
            </div>
            <div class="col">
              <section
                class="border rounded-3 p-3 h-100"
                data-id="language-overview-locales"
              >
                <h2 class="small text-body-secondary">Locales</h2>
                <div class="fs-4 fw-semibold lh-sm my-1">
                  @if (localesPath(); as path) {
                    <a
                      [hilosLink]="path"
                      [attr.aria-label]="
                        'Locales: ' + current.summary.localeCount
                      "
                      data-id="language-overview-locale-count"
                      >{{ current.summary.localeCount }}</a
                    >
                  } @else {
                    <span data-id="language-overview-locale-count">{{
                      current.summary.localeCount
                    }}</span>
                  }
                </div>
                <p class="small text-body-secondary mb-0">
                  <span data-id="language-overview-enabled-locale-count">{{
                    current.summary.enabledLocaleCount
                  }}</span>
                  switched on, including the locale without a country.
                  @if (current.summary.localeCount === 0) {
                    <span>None created.</span>
                  }
                </p>
              </section>
            </div>
            <div class="col">
              <section
                class="border rounded-3 p-3 h-100"
                data-id="language-overview-countryless"
              >
                <h2 class="small text-body-secondary">
                  Locale without a country
                </h2>
                <div class="fs-4 fw-semibold lh-sm my-1">
                  @if (localesPath(); as path) {
                    <a
                      [hilosLink]="path"
                      [attr.aria-label]="
                        'Locale without a country: ' + countrylessState()
                      "
                      data-id="language-overview-countryless-state"
                      >{{ countrylessState() }}</a
                    >
                  } @else {
                    <span data-id="language-overview-countryless-state">{{
                      countrylessState()
                    }}</span>
                  }
                </div>
                <p class="small text-body-secondary mb-0">
                  Dates and numbers when the country is unknown.
                </p>
              </section>
            </div>
          </div>

          <section class="border rounded-3 mb-4" data-id="language-card-main">
            <div
              class="d-flex flex-wrap align-items-center gap-2 border-bottom bg-body-tertiary rounded-top-3 p-3"
            >
              <h2 class="h5 mb-0 flex-grow-1">Main</h2>
              @if (current.enabled) {
                <p
                  class="badge bg-warning-subtle text-warning-emphasis mb-0"
                  data-id="language-card-frozen"
                >
                  <i class="bi bi-lock me-1" aria-hidden="true"></i>
                  Language enabled — frozen
                </p>
                <button
                  type="button"
                  class="btn btn-sm btn-outline-secondary"
                  [title]="copy.open.view"
                  [attr.aria-label]="copy.open.view"
                  data-id="language-card-view"
                  (click)="subject.window.open()"
                >
                  <i class="bi bi-eye" aria-hidden="true"></i>
                </button>
              } @else {
                <p
                  class="badge bg-info-subtle text-info-emphasis mb-0"
                  data-id="language-card-editable"
                >
                  <i class="bi bi-pencil me-1" aria-hidden="true"></i>
                  Language switched off — edit freely
                </p>
                <button
                  type="button"
                  class="btn btn-sm btn-outline-primary"
                  [title]="copy.open.edit"
                  [attr.aria-label]="copy.open.edit"
                  data-id="language-card-edit"
                  (click)="subject.window.open()"
                >
                  <i class="bi bi-pencil" aria-hidden="true"></i>
                </button>
              }
            </div>
            <dl class="mb-0 p-3">
              <div class="row align-items-baseline g-2 py-2 border-bottom">
                <dt class="col-md-3 small text-body-secondary">Code</dt>
                <dd
                  class="col-md-3 small fw-semibold text-uppercase mb-0"
                  data-id="language-card-code"
                >
                  {{ current.code }}
                </dd>
                <dd class="col-md-6 small text-body-secondary mb-0">
                  Unique language code. Never changes: locale and name addresses
                  refer to it.
                </dd>
              </div>
              <div class="row align-items-baseline g-2 py-2 border-bottom">
                <dt class="col-md-3 small text-body-secondary">Native name</dt>
                <dd
                  class="col-md-3 small fw-semibold text-break mb-0"
                  data-id="language-card-name"
                >
                  {{ current.nativeName }}
                </dd>
                <dd class="col-md-6 small text-body-secondary mb-0">
                  How the language calls itself.
                </dd>
              </div>
              <div class="row align-items-baseline g-2 py-2">
                <dt class="col-md-3 small text-body-secondary">
                  <label for="language-card-rtl">Writing direction</label>
                </dt>
                <dd class="col-md-3 small fw-semibold mb-0">
                  <input
                    id="language-card-rtl"
                    data-id="language-card-rtl"
                    class="form-check-input me-2"
                    type="checkbox"
                    disabled
                    [checked]="current.rtl"
                    aria-label="Right to left"
                    aria-describedby="language-card-rtl-hint"
                  />
                  <span data-id="language-card-direction">{{
                    current.rtl ? 'Right to left' : 'Left to right'
                  }}</span>
                </dd>
                <dd
                  id="language-card-rtl-hint"
                  class="col-md-6 small text-body-secondary mb-0"
                >
                  Right-to-left writing reverses the product layout.
                </dd>
              </div>
            </dl>
          </section>

          <section class="border rounded-3 mb-4" data-id="language-card-state">
            <div class="border-bottom bg-body-tertiary rounded-top-3 p-3">
              <h2 class="h5 mb-0">State</h2>
            </div>
            <div class="px-3">
              <div
                class="d-flex flex-wrap align-items-center gap-3 py-3 border-bottom"
              >
                <div
                  class="flex-grow-1 small fw-semibold"
                  data-id="language-card-enabled"
                >
                  {{
                    current.enabled
                      ? 'Language switched on'
                      : 'Language switched off'
                  }}
                </div>
                <div>
                  <hilos-i18n-language-switch-off
                    [context]="subject.context"
                    [card]="current"
                  />
                  <hilos-i18n-language-switch-on
                    [context]="subject.context"
                    [card]="current"
                  />
                </div>
              </div>
              <div
                class="d-flex flex-wrap align-items-center gap-3 py-3"
                data-id="language-card-delete-verdict"
              >
                <div class="flex-grow-1 flex-shrink-1 w-75">
                  <div class="small fw-semibold">Delete language</div>
                  @if (deleteVerdict().reason; as reason) {
                    <p
                      id="language-card-delete-reason"
                      class="small text-body-secondary mb-0"
                      data-id="language-card-delete-reason"
                    >
                      {{ reason }}
                    </p>
                  } @else {
                    <p class="small text-body-secondary mb-0">
                      {{ deleteCopy.body }}
                    </p>
                    <p class="small text-body-secondary mb-0">
                      {{ deleteCopy.note }}
                    </p>
                  }
                </div>
                <button
                  type="button"
                  class="btn btn-sm"
                  [class.btn-outline-secondary]="deleteVerdict().disabled"
                  [class.btn-outline-danger]="!deleteVerdict().disabled"
                  [disabled]="deleteVerdict().disabled"
                  [attr.aria-label]="deleteCopy.open"
                  [title]="deleteCopy.open"
                  [attr.aria-describedby]="
                    deleteVerdict().reason
                      ? 'language-card-delete-reason'
                      : null
                  "
                  data-id="language-card-delete"
                  (click)="deleteOpen.set(true)"
                >
                  <i class="bi bi-trash" aria-hidden="true"></i>
                </button>
              </div>
            </div>
          </section>
        } @else if (loading()) {
          <div
            class="placeholder-glow"
            role="status"
            aria-label="Loading language"
          >
            <span class="placeholder col-6 d-block mb-2 rounded"></span>
            <span class="placeholder col-4 d-block rounded"></span>
          </div>
        } @else if (!deleteOpen()) {
          <p
            class="alert alert-secondary"
            role="status"
            data-id="language-card-unavailable"
          >
            Language details are no longer available.
          </p>
        }
        <hilos-i18n-language-window [window]="subject.window" />
        <hilos-i18n-language-delete
          [open]="deleteOpen()"
          [context]="subject.context"
          [source]="deleteSource()"
          [actionName]="deleteAction"
          (closed)="deleteOpen.set(false)"
          (deleted)="returnToList()"
        />
      }
    </hilos-admin-page>
  `,
})
export class HilosI18nLanguagePage {
  /** The project context: connection, scope stores, and the action lifecycle. */
  readonly context = input.required<HilosI18nLanguageContext>()

  protected readonly page = HilosPages.I18N_LANGUAGE
  protected readonly copy = HILOS_I18N_LANGUAGE_WINDOW_COPY
  protected readonly deleteCopy = HILOS_I18N_LANGUAGE_DELETE_COPY
  protected readonly deleteAction = HILOS_I18N_LANGUAGE_DELETE_ACTION
  protected readonly marks = HILOS_I18N_LANGUAGE_MARKS

  private readonly router = inject(HILOS_ROUTER)
  protected readonly loading = hilosSignal(this.router.pageLoading)
  private readonly identity = hilosSignal(this.router.pageIdentity)
  // The language the address names now — read live, never a snapshot of the
  // first mount: navigating to another language is a new subject.
  private readonly languageCode = hilosSignal(
    computedSignal(() =>
      String(this.router.currentRoute.get().params['languageCode'] ?? ''),
    ),
  )

  /** The card and its edit session, bound to one context and one language. */
  protected readonly binding = computed(() => {
    const context = this.context()
    const card = createHilosI18nLanguageCard(context)

    return {
      context,
      languageCode: this.languageCode(),
      card,
      window: createHilosI18nLanguageCardWindow(card, context),
    }
  })
  protected readonly card = signal<HilosI18nLanguageCard | null>(null)
  protected readonly deleteOpen = signal(false)
  private readonly tabs = computed(() => {
    const current = this.card()
    return current === null
      ? []
      : hilosTabLinks(
          this.identity()?.pageTabs ?? [],
          { languageCode: current.code },
          this.router.resolvePath,
        )
  })
  protected readonly namesPath = computed(
    () =>
      this.tabs().find((tab) => tab.page === HilosPages.I18N_LANGUAGE_NAMES)
        ?.to,
  )
  protected readonly localesPath = computed(
    () =>
      this.tabs().find((tab) => tab.page === HilosPages.I18N_LANGUAGE_LOCALES)
        ?.to,
  )
  protected readonly countrylessState = computed(() => {
    switch (this.card()?.summary.countrylessLocale) {
      case 'on':
        return 'Switched on'
      case 'off':
        return 'Switched off'
      default:
        return 'Not created'
    }
  })
  protected readonly deleteVerdict = computed(() =>
    hilosI18nLanguageDelete(this.card()?.summary ?? null),
  )
  protected readonly deleteSource = computed(
    (): HilosI18nLanguageDeleteSource | null => {
      const card = this.card()

      return card === null
        ? null
        : {
            languageId: card.languageId,
            code: card.code,
            nativeName: card.nativeName,
            canDelete: card.summary.canDelete,
            deleteReason: card.summary.deleteReason,
          }
    },
  )

  constructor() {
    // Mirror the bound card and name it in the shared head from that same core
    // card while a language tab stands. A new binding starts with delete closed.
    effect((onCleanup) => {
      const { context, card } = this.binding()
      const stop = mirrorHilosSignal(card, this.card)
      const unbindName = bindPageInstanceName(
        context.scopes,
        HilosPages.I18N_LANGUAGE,
        computedSignal(() => {
          const page = context.scopes.page()?.key
          if (
            page !== HilosPages.I18N_LANGUAGE &&
            page !== HilosPages.I18N_LANGUAGE_NAMES &&
            page !== HilosPages.I18N_LANGUAGE_LOCALES
          ) {
            return undefined
          }
          return card.get()?.nativeName
        }),
      )
      onCleanup(() => {
        unbindName()
        stop()
        this.deleteOpen.set(false)
      })
    })
  }

  protected returnToList(): void {
    const path = this.router.resolvePath(HilosPages.I18N_LANGUAGES, {})
    if (path !== undefined) {
      this.router.navigate(path)
    }
  }
}
