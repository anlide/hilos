// HilosI18nCountryPage — the main card of one country (HilosPages.I18N_COUNTRY):
// the country's block in the shared page head, the main fields with the
// view/edit window, and the state — switching on and off, and deleting. The card
// is one page-scoped datum the page answer brings and its live updates refresh
// (createHilosI18nCountryCard); the switches and the delete read their verdicts
// from it, and the same card fills the block the shell hands the head — the code
// tile, the code, the custom-country mark and the currency and default-locale
// line — while the name in the head is the owner label bound here, not drawn
// here. The server checks every verdict again. The same card names the country
// in the page owner and its breadcrumb entry while a country tab stands
// (bindPageInstanceName), bound with the card. The windows belong to one
// binding: the project context and the country the address names now, read
// live from the navigator. A new context or another country replaces the
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
  HILOS_I18N_COUNTRY_DELETE_COPY,
  HILOS_I18N_COUNTRY_MARKS,
  HILOS_I18N_COUNTRY_WINDOW_COPY,
  createHilosI18nCountryCard,
  createHilosI18nCountryCardWindow,
  createHilosI18nCountryDelete,
  hilosI18nCountryDeleteFromCard,
  hilosI18nCountryDeleteReason,
  hilosI18nCountryDisplayName,
} from '@hilos/core/admin/i18n'
import {
  HilosPages,
  bindPageInstanceName,
  computedSignal,
} from '@hilos/core/base'
import type {
  HilosI18nCountryCard,
  HilosI18nCountryContext,
  HilosI18nCountryDeleteTarget,
} from '@hilos/core/admin/i18n'

import {
  HilosAdminPage,
  HILOS_ROUTER,
  hilosSignal,
  mirrorHilosSignal,
} from '@hilos/angular/base'
import { HilosI18nCountryDelete } from './HilosI18nCountryDelete.js'
import { HilosI18nCountrySwitchOff } from './HilosI18nCountrySwitchOff.js'
import { HilosI18nCountrySwitchOn } from './HilosI18nCountrySwitchOn.js'
import { HilosI18nCountryWindow } from './HilosI18nCountryWindow.js'

/** The country main card with its view/edit, switch and delete windows. */
@Component({
  selector: 'hilos-i18n-country-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosI18nCountryDelete,
    HilosI18nCountrySwitchOff,
    HilosI18nCountrySwitchOn,
    HilosI18nCountryWindow,
  ],
  template: `
    <hilos-admin-page [page]="page" headKind="entity" tabsLabel="Country pages">
      @for (subject of [binding()]; track subject.window) {
        @if (card(); as current) {
          <ng-template #headVisual>
            <span
              class="hilos-avatar-md rounded-3 bg-secondary-subtle text-body-emphasis d-inline-flex align-items-center justify-content-center flex-shrink-0 fw-semibold text-uppercase"
              aria-hidden="true"
              data-id="country-head-tile"
              >{{ current.code }}</span
            >
          </ng-template>
          <ng-template #headMeta>
            <span
              class="font-monospace small text-body-secondary text-uppercase"
              data-id="country-head-code"
              >{{ current.code }}</span
            >
          </ng-template>
          @if (current.summary.isOwn) {
            <ng-template #headBadges>
              <span
                [class]="'badge ' + marks.own.tone"
                title="This country is absent from the catalog and source."
                data-id="country-head-own"
              >
                <i [class]="'bi ' + marks.own.icon" aria-hidden="true"></i>
                <span class="visually-hidden"
                  >{{ marks.own.label }}. This country is absent from the
                  catalog and source.</span
                >
              </span>
            </ng-template>
          }
          <ng-template #headDescription>
            <p
              class="text-body-secondary mb-0"
              data-id="country-head-description"
            >
              Currency {{ current.currencySymbol }} {{ current.currencyCode }} ·
              Default locale
              {{ current.summary.defaultLocaleCode ?? 'not chosen' }}
            </p>
          </ng-template>

          <section class="mb-4" data-id="country-card-main">
            <div class="d-flex align-items-center gap-2 mb-2">
              <h2 class="h5 mb-0">Main</h2>
              @if (current.enabled) {
                <button
                  type="button"
                  class="btn btn-sm btn-outline-secondary ms-auto"
                  [title]="copy.open.view"
                  [attr.aria-label]="copy.open.view"
                  data-id="country-card-view"
                  (click)="subject.window.open('view')"
                >
                  <i class="bi bi-eye" aria-hidden="true"></i>
                </button>
              } @else {
                <button
                  type="button"
                  class="btn btn-sm btn-outline-primary ms-auto"
                  [title]="copy.open.edit"
                  [attr.aria-label]="copy.open.edit"
                  data-id="country-card-edit"
                  (click)="subject.window.open('edit')"
                >
                  <i class="bi bi-pencil" aria-hidden="true"></i>
                </button>
              }
            </div>
            <div class="border rounded-3 p-3">
              @if (current.enabled) {
                <p
                  class="badge text-bg-warning mb-3"
                  data-id="country-card-frozen"
                >
                  Country enabled — frozen
                </p>
              }
              <dl class="row mb-0">
                <dt class="col-sm-4">Code</dt>
                <dd class="col-sm-8">
                  <span class="text-uppercase" data-id="country-card-code">{{
                    current.code
                  }}</span>
                  <span class="d-block small text-body-secondary">
                    ISO 3166-1 alpha-2, unique. Never changes: locales and names
                    refer to it.
                  </span>
                </dd>
                <dt class="col-sm-4">Currency</dt>
                <dd class="col-sm-8">
                  <span data-id="country-card-currency"
                    >{{ current.currencySymbol }}
                    {{ current.currencyCode }}</span
                  >
                  <span class="d-block small text-body-secondary">
                    The symbol is for display, the ISO 4217 code is for
                    calculations.
                  </span>
                </dd>
                <dt class="col-sm-4">Default locale</dt>
                <dd class="col-sm-8 mb-0">
                  <span data-id="country-card-default-locale">{{
                    current.summary.defaultLocaleCode ?? 'not chosen'
                  }}</span>
                  <span class="d-block small text-body-secondary">
                    Formats used when the country is known but the language is
                    not. Empty takes the formats from the language.
                  </span>
                </dd>
              </dl>
            </div>
          </section>

          <section class="mb-4" data-id="country-card-state">
            <h2 class="h5">State</h2>
            <dl class="row mb-0 border rounded-3 p-3">
              <dt class="col-sm-4">Availability</dt>
              <dd class="col-sm-8">
                <span data-id="country-card-enabled">{{
                  current.enabled ? 'Enabled' : 'Disabled'
                }}</span>
                <span class="d-block small text-body-secondary">
                  @if (current.enabled) {
                    Values are frozen; switch the country off to edit them.
                  } @else {
                    Values can be edited; the code never changes.
                  }
                </span>
                <div class="mt-2">
                  <hilos-i18n-country-switch-on
                    [context]="subject.context"
                    [card]="current"
                  />
                  <hilos-i18n-country-switch-off
                    [context]="subject.context"
                    [card]="current"
                  />
                </div>
              </dd>
              <dt class="col-sm-4">Delete country</dt>
              <dd class="col-sm-8 mb-0" data-id="country-card-delete-verdict">
                <span id="country-card-delete-reason">{{
                  deleteReason()
                }}</span>
                <div class="mt-2">
                  <button
                    type="button"
                    [class]="
                      'btn btn-sm ' +
                      (current.summary.canDelete
                        ? 'btn-outline-danger'
                        : 'border border-secondary text-body-secondary')
                    "
                    [attr.aria-disabled]="
                      current.summary.canDelete ? null : 'true'
                    "
                    aria-describedby="country-card-delete-reason"
                    data-id="country-card-delete"
                    (click)="openDelete()"
                  >
                    {{ deleteCopy.open }}
                  </button>
                </div>
              </dd>
            </dl>
          </section>
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
            data-id="country-card-unavailable"
          >
            Country details are no longer available.
          </p>
        }
        <hilos-i18n-country-window [window]="subject.window" />
        <hilos-i18n-country-delete
          [open]="deleteTarget() !== null"
          [target]="deleteTarget()"
          [source]="deleteSource()"
          [deleteCountry]="subject.deleteCountry"
          (closed)="deleteTarget.set(null)"
          (deleted)="returnToList()"
        />
      }
    </hilos-admin-page>
  `,
})
export class HilosI18nCountryPage {
  /** The project context: connection, scope stores, and the action lifecycle. */
  readonly context = input.required<HilosI18nCountryContext>()

  protected readonly page = HilosPages.I18N_COUNTRY
  protected readonly copy = HILOS_I18N_COUNTRY_WINDOW_COPY
  protected readonly deleteCopy = HILOS_I18N_COUNTRY_DELETE_COPY
  protected readonly marks = HILOS_I18N_COUNTRY_MARKS

  private readonly router = inject(HILOS_ROUTER)
  protected readonly loading = hilosSignal(this.router.pageLoading)
  // The country the address names now — read live, never a snapshot of the
  // first mount: navigating to another country is a new subject.
  private readonly countryCode = hilosSignal(
    computedSignal(() =>
      String(this.router.currentRoute.get().params['countryCode'] ?? ''),
    ),
  )

  /** The card and its edit session, bound to one context and one country. */
  protected readonly binding = computed(() => {
    const context = this.context()
    const card = createHilosI18nCountryCard(context)

    return {
      context,
      countryCode: this.countryCode(),
      card,
      window: createHilosI18nCountryCardWindow(card, context),
      deleteCountry: createHilosI18nCountryDelete(context).deleteCountry,
    }
  })
  protected readonly card = signal<HilosI18nCountryCard | null>(null)
  /** The card's own deletion verdict, the same projection the window follows. */
  protected readonly deleteSource = computed(() =>
    hilosI18nCountryDeleteFromCard(this.card()),
  )
  protected readonly deleteReason = computed(() =>
    hilosI18nCountryDeleteReason(this.deleteSource()),
  )
  /** The country the confirmation opened on; null while it is closed. */
  protected readonly deleteTarget = signal<HilosI18nCountryDeleteTarget | null>(
    null,
  )

  constructor() {
    // Mirror the bound card and name the country in the head from that same
    // core card, never from the mirror, while a country tab stands; a new
    // binding starts with its delete closed and lets the former name go.
    effect((onCleanup) => {
      const { context, card } = this.binding()
      const stop = mirrorHilosSignal(card, this.card)
      const unbindName = bindPageInstanceName(
        context.scopes,
        HilosPages.I18N_COUNTRY,
        computedSignal(() => {
          const page = context.scopes.page()?.key
          if (
            page !== HilosPages.I18N_COUNTRY &&
            page !== HilosPages.I18N_COUNTRY_NAMES
          ) {
            return undefined
          }
          const current = card.get()
          return current === null
            ? undefined
            : hilosI18nCountryDisplayName(current)
        }),
      )
      onCleanup(() => {
        unbindName()
        stop()
        this.deleteTarget.set(null)
      })
    })
  }

  // Open the confirmation only while the card's hint lets the country go.
  protected openDelete(): void {
    const source = this.deleteSource()
    if (source?.canDelete === true) {
      this.deleteTarget.set(source)
    }
  }

  protected returnToList(): void {
    const path = this.router.resolvePath(HilosPages.I18N_COUNTRIES, {})
    if (path !== undefined) {
      this.router.navigate(path)
    }
  }
}
