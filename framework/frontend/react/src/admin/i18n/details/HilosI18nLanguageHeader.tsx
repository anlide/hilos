import { useContext } from 'react'
import { HilosPages } from '@hilos/core/base'
import { type HilosI18nLanguageCard } from '@hilos/core/admin/i18n'
import { HilosLink } from '../../../HilosLink.js'
import { HilosRouterContext } from '../../../hilosRouterContext.js'

/** The live language identity and code-addressed tabs shared by its three pages. */
export function HilosI18nLanguageHeader({
  card,
  activePage,
}: {
  card: HilosI18nLanguageCard
  activePage: string
}) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error('HilosI18nLanguageHeader requires a provided Hilos router.')
  const tabs = [
    { page: HilosPages.I18N_LANGUAGE, label: 'Main' },
    { page: HilosPages.I18N_LANGUAGE_NAMES, label: 'Names' },
    { page: HilosPages.I18N_LANGUAGE_LOCALES, label: 'Locales' },
  ]
  return (
    <>
      <div
        className="border rounded-3 p-3 mb-3 d-flex flex-wrap align-items-center gap-3"
        data-id="language-card-header"
      >
        <span
          className="rounded-3 bg-body-tertiary d-inline-flex align-items-center justify-content-center fw-semibold text-uppercase p-3"
          data-id="language-card-code-tile"
        >
          {card.code}
        </span>
        <div className="flex-grow-1">
          <div className="d-flex flex-wrap align-items-center gap-2">
            <span className="h5 mb-0" data-id="language-card-native-name">
              {card.nativeName}
            </span>
            {card.summary.isDefault && (
              <span
                className="badge text-bg-info"
                data-id="language-card-default"
              >
                Default language
              </span>
            )}
            {card.summary.isOwn && (
              <span
                className="badge text-bg-secondary"
                data-id="language-card-own"
              >
                Custom language
              </span>
            )}
          </div>
          <p
            className="small text-body-secondary mb-0"
            data-id="language-card-counts"
          >
            Locales: {card.summary.localeCount} · Names:{' '}
            {card.summary.nameCount}
          </p>
        </div>
      </div>
      <nav aria-label="Language pages">
        <ul className="nav nav-underline mb-3" data-id="language-card-tabs">
          {tabs.map((tab) => {
            const path = router.resolvePath(tab.page, {
              languageCode: card.code,
            })
            return (
              <li key={tab.page} className="nav-item">
                {path ? (
                  <HilosLink
                    to={path}
                    className={`nav-link${tab.page === activePage ? ' active' : ''}`}
                    aria-current={tab.page === activePage ? 'page' : undefined}
                    data-id={`language-card-tab-${tab.page}`}
                  >
                    {tab.label}
                  </HilosLink>
                ) : (
                  <span
                    className="nav-link text-body-secondary"
                    data-id={`language-card-tab-${tab.page}`}
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
