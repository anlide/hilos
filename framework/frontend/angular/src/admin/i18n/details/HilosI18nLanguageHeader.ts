// HilosI18nLanguageHeader — the header every page of one language shares: the
// code tile, the native name with its default and custom marks, the counts of
// locales and names, and the tabs to the language's pages, addressed by the
// card's code. A tab whose page has no address in this application is drawn as
// plain text, never as a dead link. Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
} from '@angular/core'
import { HilosPages } from '@hilos/core/base'
import type { HilosI18nLanguageCard } from '@hilos/core/admin/i18n'

import { HilosLink, HILOS_ROUTER } from '@hilos/angular/base'

/** The pages of one language, in tab order. */
const LANGUAGE_TABS = [
  { page: HilosPages.I18N_LANGUAGE, label: 'Main' },
  { page: HilosPages.I18N_LANGUAGE_NAMES, label: 'Names' },
  { page: HilosPages.I18N_LANGUAGE_LOCALES, label: 'Locales' },
] as const

/** The shared header and tabs of a language's pages. */
@Component({
  selector: 'hilos-i18n-language-header',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLink],
  template: `
    <div
      class="border rounded-3 p-3 mb-3 d-flex flex-wrap align-items-center gap-3"
      data-id="language-card-header"
    >
      <span
        class="rounded-3 bg-body-tertiary d-inline-flex align-items-center justify-content-center fw-semibold text-uppercase p-3"
        data-id="language-card-code-tile"
      >
        {{ card().code }}
      </span>
      <div class="flex-grow-1">
        <div class="d-flex flex-wrap align-items-center gap-2">
          <span class="h5 mb-0" data-id="language-card-native-name">{{
            card().nativeName
          }}</span>
          @if (card().summary.isDefault) {
            <span class="badge text-bg-info" data-id="language-card-default">
              Default language
            </span>
          }
          @if (card().summary.isOwn) {
            <span class="badge text-bg-secondary" data-id="language-card-own">
              Custom language
            </span>
          }
        </div>
        <p
          class="small text-body-secondary mb-0"
          data-id="language-card-counts"
        >
          Locales: {{ card().summary.localeCount }} · Names:
          {{ card().summary.nameCount }}
        </p>
      </div>
    </div>

    <nav aria-label="Language pages">
      <ul class="nav nav-underline mb-3" data-id="language-card-tabs">
        @for (tab of tabs(); track tab.page) {
          <li class="nav-item">
            @if (tab.path; as path) {
              <a
                [hilosLink]="path"
                [class]="
                  'nav-link' + (tab.page === activePage() ? ' active' : '')
                "
                [attr.data-id]="'language-card-tab-' + tab.page"
              >
                {{ tab.label }}
              </a>
            } @else {
              <span
                class="nav-link text-body-secondary"
                [attr.data-id]="'language-card-tab-' + tab.page"
              >
                {{ tab.label }}
              </span>
            }
          </li>
        }
      </ul>
    </nav>
  `,
})
export class HilosI18nLanguageHeader {
  /** The live language card the header describes. */
  readonly card = input.required<HilosI18nLanguageCard>()
  /** The page of the language this header stands on. */
  readonly activePage = input.required<string>()

  private readonly router = inject(HILOS_ROUTER)
  protected readonly tabs = computed(() =>
    LANGUAGE_TABS.map((tab) => ({
      ...tab,
      path: this.router.resolvePath(tab.page, {
        languageCode: this.card().code,
      }),
    })),
  )
}
