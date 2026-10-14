import { useContext } from 'react'
import { HilosPages, type HilosI18nCountryCard } from '@hilos/core'
import { HilosLink } from '../../../HilosLink.js'
import { HilosRouterContext } from '../../../hilosRouterContext.js'

/** The live country identity and code-addressed tabs shared by both pages. */
export function HilosI18nCountryHeader({
  card,
  activePage,
}: {
  card: HilosI18nCountryCard
  activePage: string
}) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error('HilosI18nCountryHeader requires a provided Hilos router.')
  const tabs = [
    { page: HilosPages.I18N_COUNTRY, label: 'Main' },
    { page: HilosPages.I18N_COUNTRY_NAMES, label: 'Names' },
  ]
  return (
    <>
      <div className="border rounded-3 p-3 mb-3" data-id="country-card-header">
        <div className="d-flex flex-wrap align-items-center gap-2">
          <span
            className={`h5 mb-0${card.summary.name === null ? ' text-uppercase' : ''}`}
            data-id="country-card-title"
          >
            {card.summary.name ?? card.code}
          </span>
          {card.summary.name !== null && (
            <span
              className="text-body-secondary text-uppercase"
              data-id="country-card-code-badge"
            >
              {card.code}
            </span>
          )}
          {card.summary.isOwn && (
            <span
              className="badge text-bg-secondary"
              data-id="country-card-own"
            >
              Custom country
            </span>
          )}
        </div>
        <p
          className="small text-body-secondary mb-0"
          data-id="country-card-line"
        >
          Currency {card.currencySymbol} {card.currencyCode} · Default locale{' '}
          {card.summary.defaultLocaleCode ?? 'not chosen'}
        </p>
      </div>
      <nav aria-label="Country pages">
        <ul className="nav nav-underline mb-3" data-id="country-card-tabs">
          {tabs.map((tab) => {
            const path = router.resolvePath(tab.page, {
              countryCode: card.code,
            })
            return (
              <li key={tab.page} className="nav-item">
                {path ? (
                  <HilosLink
                    to={path}
                    className={`nav-link${tab.page === activePage ? ' active' : ''}`}
                    aria-current={tab.page === activePage ? 'page' : undefined}
                    data-id={`country-card-tab-${tab.page}`}
                  >
                    {tab.label}
                  </HilosLink>
                ) : (
                  <span
                    className="nav-link text-body-secondary"
                    data-id={`country-card-tab-${tab.page}`}
                  >
                    {tab.label}
                  </span>
                )}
              </li>
            )
          })}
        </ul>
      </nav>
    </>
  )
}
