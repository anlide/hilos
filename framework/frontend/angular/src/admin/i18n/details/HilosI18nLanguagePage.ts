// HilosI18nLanguagePage — the main card of one language (HilosPages.I18N_LANGUAGE):
// the shared header and tabs, the main fields with the view/edit window, and the
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
  HILOS_I18N_LANGUAGE_WINDOW_COPY,
  HilosPages,
  computedSignal,
  createHilosI18nLanguageCard,
  createHilosI18nLanguageCardWindow,
  hilosI18nLanguageDelete,
} from '@hilos/core'
import type {
  HilosI18nLanguageCard,
  HilosI18nLanguageContext,
} from '@hilos/core'

import { HilosAdminPage } from '../../../HilosAdminPage.js'
import { HILOS_ROUTER } from '../../../hilosRouterToken.js'
import { hilosSignal, mirrorHilosSignal } from '../../../hilosSignal.js'
import { HilosI18nLanguageDelete } from './HilosI18nLanguageDelete.js'
import type { HilosI18nLanguageDeleteSource } from './HilosI18nLanguageDelete.js'
import { HilosI18nLanguageHeader } from './HilosI18nLanguageHeader.js'
import { HilosI18nLanguageSwitchOff } from './HilosI18nLanguageSwitchOff.js'
import { HilosI18nLanguageSwitchOn } from './HilosI18nLanguageSwitchOn.js'
import { HilosI18nLanguageWindow } from './HilosI18nLanguageWindow.js'

/** The language main card with its view/edit, switch and delete windows. */
@Component({
  selector: 'hilos-i18n-language-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosI18nLanguageDelete,
    HilosI18nLanguageHeader,
    HilosI18nLanguageSwitchOff,
    HilosI18nLanguageSwitchOn,
    HilosI18nLanguageWindow,
  ],
  template: `
    <hilos-admin-page [page]="page">
      @for (subject of [binding()]; track subject.window) {
        @if (card(); as current) {
          <hilos-i18n-language-header [card]="current" [activePage]="page" />

          <section class="mb-4" data-id="language-card-main">
            <div class="d-flex align-items-center gap-2 mb-2">
              <h2 class="h5 mb-0 flex-grow-1">Main</h2>
              @if (current.enabled) {
                <p
                  class="badge text-bg-warning mb-0"
                  data-id="language-card-frozen"
                >
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
            <dl class="row mb-0 border rounded-3 p-3">
              <dt class="col-sm-4">Code</dt>
              <dd class="col-sm-8 text-uppercase" data-id="language-card-code">
                {{ current.code }}
              </dd>
              <dt class="col-sm-4">Native name</dt>
              <dd class="col-sm-8" data-id="language-card-name">
                {{ current.nativeName }}
              </dd>
              <dt class="col-sm-4">Writing direction</dt>
              <dd class="col-sm-8" data-id="language-card-direction">
                {{ current.rtl ? 'Right to left' : 'Left to right' }}
              </dd>
            </dl>
          </section>

          <section class="mb-4" data-id="language-card-state">
            <h2 class="h5">State</h2>
            <div class="border rounded-3 p-3">
              <dl class="row mb-0">
                <dt class="col-sm-4">Availability</dt>
                <dd class="col-sm-8">
                  <span data-id="language-card-enabled">
                    {{ current.enabled ? 'Enabled' : 'Disabled' }}
                  </span>
                  <div class="mt-2">
                    <hilos-i18n-language-switch-off
                      [context]="subject.context"
                      [card]="current"
                    />
                    <hilos-i18n-language-switch-on
                      [context]="subject.context"
                      [card]="current"
                    />
                  </div>
                </dd>
                <dt class="col-sm-4">Delete language</dt>
                <dd class="col-sm-8" data-id="language-card-delete-verdict">
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-danger"
                    [disabled]="deleteVerdict().disabled"
                    [attr.aria-describedby]="
                      deleteVerdict().reason
                        ? 'language-card-delete-reason'
                        : null
                    "
                    data-id="language-card-delete"
                    (click)="deleteOpen.set(true)"
                  >
                    {{ deleteCopy.open }}
                  </button>
                  @if (deleteVerdict().reason; as reason) {
                    <p
                      id="language-card-delete-reason"
                      class="small text-body-secondary mt-1 mb-0"
                      data-id="language-card-delete-reason"
                    >
                      {{ reason }}
                    </p>
                  }
                </dd>
              </dl>
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

  private readonly router = inject(HILOS_ROUTER)
  protected readonly loading = hilosSignal(this.router.pageLoading)
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
  protected readonly deleteVerdict = computed(() =>
    hilosI18nLanguageDelete(this.card()?.summary ?? null),
  )
  protected readonly deleteSource = computed(
    (): HilosI18nLanguageDeleteSource | null => {
      const card = this.card()

      return card === null
        ? null
        : {
            code: card.code,
            nativeName: card.nativeName,
            canDelete: card.summary.canDelete,
            deleteReason: card.summary.deleteReason,
          }
    },
  )

  constructor() {
    // Mirror the bound card; a new binding starts with its delete closed.
    effect((onCleanup) => {
      const stop = mirrorHilosSignal(this.binding().card, this.card)
      onCleanup(() => {
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
