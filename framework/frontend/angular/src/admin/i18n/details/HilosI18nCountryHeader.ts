// HilosI18nCountryHeader — the header every page of one country shares: the
// country's name in the default language with its code beside it — or the code
// alone, in capitals, while no name is written — the custom mark, the currency
// and default-locale line, and the tabs to the country's pages, addressed by the
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
import type { HilosI18nCountryCard } from '@hilos/core/admin/i18n'

import { HilosLink, HILOS_ROUTER } from '@hilos/angular/base'

/** The pages of one country, in tab order. */
const COUNTRY_TABS = [
  { page: HilosPages.I18N_COUNTRY, label: 'Main' },
  { page: HilosPages.I18N_COUNTRY_NAMES, label: 'Names' },
] as const

/** The shared header and tabs of a country's pages. */
@Component({
  selector: 'hilos-i18n-country-header',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLink],
  template: `
    <div class="border rounded-3 p-3 mb-3" data-id="country-card-header">
      <div class="d-flex flex-wrap align-items-center gap-2">
        <span
          [class]="
            'h5 mb-0' + (card().summary.name === null ? ' text-uppercase' : '')
          "
          data-id="country-card-title"
          >{{ card().summary.name ?? card().code }}</span
        >
        @if (card().summary.name !== null) {
          <span
            class="text-body-secondary text-uppercase"
            data-id="country-card-code-badge"
          >
            {{ card().code }}
          </span>
        }
        @if (card().summary.isOwn) {
          <span class="badge text-bg-secondary" data-id="country-card-own">
            Custom country
          </span>
        }
      </div>
      <p class="small text-body-secondary mb-0" data-id="country-card-line">
        Currency {{ card().currencySymbol }} {{ card().currencyCode }} · Default
        locale {{ card().summary.defaultLocaleCode ?? 'not chosen' }}
      </p>
    </div>

    <nav aria-label="Country pages">
      <ul class="nav nav-underline mb-3" data-id="country-card-tabs">
        @for (tab of tabs(); track tab.page) {
          <li class="nav-item">
            @if (tab.path; as path) {
              <a
                [hilosLink]="path"
                [class]="
                  'nav-link' + (tab.page === activePage() ? ' active' : '')
                "
                [attr.data-id]="'country-card-tab-' + tab.page"
              >
                {{ tab.label }}
              </a>
            } @else {
              <span
                class="nav-link text-body-secondary"
                [attr.data-id]="'country-card-tab-' + tab.page"
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
export class HilosI18nCountryHeader {
  /** The live country card the header describes. */
  readonly card = input.required<HilosI18nCountryCard>()
  /** The page of the country this header stands on. */
  readonly activePage = input.required<string>()

  private readonly router = inject(HILOS_ROUTER)
  protected readonly tabs = computed(() =>
    COUNTRY_TABS.map((tab) => ({
      ...tab,
      path: this.router.resolvePath(tab.page, {
        countryCode: this.card().code,
      }),
    })),
  )
}
