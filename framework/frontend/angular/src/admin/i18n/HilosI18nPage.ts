// HilosI18nPage — the Internationalization overview. The current page's
// complete summary supplies both tiles; the shared head owns its title and
// tabs. Tiles follow the same resolved tabs as that head. Bootstrap classes
// only.
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
  createHilosI18nOverview,
  type HilosI18nOverview,
  type HilosI18nOverviewContext,
} from '@hilos/core/admin/i18n'
import { HilosPages, hilosTabLinks } from '@hilos/core/base'

import {
  HilosAdminPage,
  HilosLink,
  HilosSkeleton,
  HILOS_ROUTER,
  hilosSignal,
  mirrorHilosSignal,
} from '@hilos/angular/base'

/** Both tiles from the current page summary, under the shared section head. */
@Component({
  selector: 'hilos-i18n-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosLink, HilosSkeleton],
  template: `
    <hilos-admin-page
      [page]="page"
      headKind="section"
      tabsLabel="Internationalization pages"
    >
      <div data-id="i18n-overview">
        @if (overview(); as current) {
          <div class="row row-cols-2 row-cols-md-3 row-cols-lg-5 g-3 mb-4">
            @if (languagesPath(); as path) {
              <div class="col">
                <div
                  class="border rounded-3 p-3 h-100"
                  data-id="i18n-overview-languages"
                >
                  <div class="small text-body-secondary">Languages</div>
                  <div class="fs-4 fw-semibold lh-sm my-1">
                    <a
                      [hilosLink]="path"
                      class="text-decoration-none"
                      [attr.aria-label]="
                        'Languages: ' +
                        current.languages.enabledCount +
                        ' of ' +
                        current.languages.totalCount +
                        ' enabled'
                      "
                      data-id="i18n-overview-languages-count"
                    >
                      {{ current.languages.enabledCount }} of
                      {{ current.languages.totalCount }}
                    </a>
                  </div>
                  <div class="small text-body-secondary">
                    Enabled · default
                    {{ current.languages.defaultLanguageName }}
                  </div>
                </div>
              </div>
            }
            @if (countriesPath(); as path) {
              <div class="col">
                <div
                  class="border rounded-3 p-3 h-100"
                  data-id="i18n-overview-countries"
                >
                  <div class="small text-body-secondary">Countries</div>
                  <div class="fs-4 fw-semibold lh-sm my-1">
                    <a
                      [hilosLink]="path"
                      class="text-decoration-none"
                      [attr.aria-label]="
                        'Countries: ' +
                        current.countries.enabledCount +
                        ' of ' +
                        current.countries.totalCount +
                        ' enabled'
                      "
                      data-id="i18n-overview-countries-count"
                    >
                      {{ current.countries.enabledCount }} of
                      {{ current.countries.totalCount }}
                    </a>
                  </div>
                  <div class="small text-body-secondary">
                    Enabled · {{ current.countries.builtInCount }} built-in,
                    {{ current.countries.ownCount }} custom
                  </div>
                </div>
              </div>
            }
          </div>
        } @else {
          <hilos-skeleton />
        }
      </div>
    </hilos-admin-page>
  `,
})
export class HilosI18nPage {
  /** The project context: page scopes. */
  readonly context = input.required<HilosI18nOverviewContext>()

  protected readonly page = HilosPages.I18N

  private readonly router = inject(HILOS_ROUTER)
  private readonly source = computed(() =>
    createHilosI18nOverview(this.context()),
  )
  protected readonly overview = signal<HilosI18nOverview | null>(null)
  private readonly identity = hilosSignal(this.router.pageIdentity)
  private readonly route = hilosSignal(this.router.currentRoute)
  private readonly tabs = computed(() =>
    hilosTabLinks(
      this.identity()?.pageTabs ?? [],
      this.route().params,
      this.router.resolvePath,
    ),
  )
  protected readonly languagesPath = computed(
    () => this.tabs().find((tab) => tab.page === HilosPages.I18N_LANGUAGES)?.to,
  )
  protected readonly countriesPath = computed(
    () => this.tabs().find((tab) => tab.page === HilosPages.I18N_COUNTRIES)?.to,
  )

  constructor() {
    effect((onCleanup) => {
      const stop = mirrorHilosSignal(this.source(), this.overview)
      onCleanup(stop)
    })
  }
}
