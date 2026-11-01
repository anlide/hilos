// HilosI18nPage — the Internationalization overview. The current page's
// complete summary supplies both tiles; the shared head owns its title and
// tabs. Tiles follow the same resolved tabs as that head. Bootstrap classes
// only.
import { useContext, useMemo } from 'react'
import {
  createHilosI18nOverview,
  type HilosI18nOverviewContext,
} from '@hilos/core/admin/i18n'
import { HilosPages, hilosTabLinks } from '@hilos/core/base'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosSkeleton } from '../../HilosSkeleton.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'

/** The project binds the overview to its current page scopes. */
export interface HilosI18nPageProps {
  /** The project's current page scopes. */
  context: HilosI18nOverviewContext
}

/** Both tiles from the current page summary, under the shared section head. */
export function HilosI18nPage({ context }: HilosI18nPageProps) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error('HilosI18nPage requires a provided Hilos router.')
  const overviewSignal = useMemo(
    () => createHilosI18nOverview(context),
    [context],
  )
  const overview = useSignal(overviewSignal)
  const identity = useSignal(router.pageIdentity)
  const route = useSignal(router.currentRoute)
  const tabs = hilosTabLinks(
    identity?.pageTabs ?? [],
    route.params,
    router.resolvePath,
  )
  const languagesPath = tabs.find(
    (tab) => tab.page === HilosPages.I18N_LANGUAGES,
  )?.to
  const countriesPath = tabs.find(
    (tab) => tab.page === HilosPages.I18N_COUNTRIES,
  )?.to
  return (
    <HilosAdminPage
      page={HilosPages.I18N}
      headKind="section"
      tabsLabel="Internationalization pages"
    >
      <div data-id="i18n-overview">
        {overview ? (
          <div className="row row-cols-2 row-cols-md-3 row-cols-lg-5 g-3 mb-4">
            {languagesPath !== undefined ? (
              <div className="col">
                <div
                  className="border rounded-3 p-3 h-100"
                  data-id="i18n-overview-languages"
                >
                  <div className="small text-body-secondary">Languages</div>
                  <div className="fs-4 fw-semibold lh-sm my-1">
                    <HilosLink
                      to={languagesPath}
                      className="text-decoration-none"
                      aria-label={`Languages: ${overview.languages.enabledCount} of ${overview.languages.totalCount} enabled`}
                      data-id="i18n-overview-languages-count"
                    >
                      {`${overview.languages.enabledCount} of ${overview.languages.totalCount}`}
                    </HilosLink>
                  </div>
                  <div className="small text-body-secondary">
                    {`Enabled · default ${overview.languages.defaultLanguageName}`}
                  </div>
                </div>
              </div>
            ) : null}
            {countriesPath !== undefined ? (
              <div className="col">
                <div
                  className="border rounded-3 p-3 h-100"
                  data-id="i18n-overview-countries"
                >
                  <div className="small text-body-secondary">Countries</div>
                  <div className="fs-4 fw-semibold lh-sm my-1">
                    <HilosLink
                      to={countriesPath}
                      className="text-decoration-none"
                      aria-label={`Countries: ${overview.countries.enabledCount} of ${overview.countries.totalCount} enabled`}
                      data-id="i18n-overview-countries-count"
                    >
                      {`${overview.countries.enabledCount} of ${overview.countries.totalCount}`}
                    </HilosLink>
                  </div>
                  <div className="small text-body-secondary">
                    {`Enabled · ${overview.countries.builtInCount} built-in, ${overview.countries.ownCount} custom`}
                  </div>
                </div>
              </div>
            ) : null}
          </div>
        ) : (
          <HilosSkeleton />
        )}
      </div>
    </HilosAdminPage>
  )
}
