import { useContext, useMemo, useState } from 'react'
import {
  createHilosI18nLanguageCard,
  createHilosI18nLanguageCardWindow,
  HILOS_I18N_LANGUAGE_WINDOW_COPY,
  HILOS_I18N_LANGUAGE_DELETE_ACTION,
  HILOS_I18N_LANGUAGE_DELETE_COPY,
  HILOS_I18N_LANGUAGE_MARKS,
  hilosI18nLanguageDelete,
  type HilosI18nLanguageContext,
} from '@hilos/core/admin/i18n'
import { computedSignal, HilosPages, hilosTabLinks } from '@hilos/core/base'
import { HilosAdminPage } from '../../../HilosAdminPage.js'
import { HilosLink } from '../../../HilosLink.js'
import { HilosRouterContext } from '../../../hilosRouterContext.js'
import { usePageInstanceName } from '../../../usePageInstanceName.js'
import { useSignal } from '../../../useSignal.js'
import { HilosI18nLanguageDelete } from './HilosI18nLanguageDelete.js'
import { HilosI18nLanguageSwitchOff } from './HilosI18nLanguageSwitchOff.js'
import { HilosI18nLanguageSwitchOn } from './HilosI18nLanguageSwitchOn.js'
import { HilosI18nLanguageWindow } from './HilosI18nLanguageWindow.js'

/** The project binds the card to its page-scoped language datum and actions. */
export interface HilosI18nLanguagePageProps {
  context: HilosI18nLanguageContext
}

/** A code-addressed card: a new subject releases every window of the former subject. */
export function HilosI18nLanguagePage({ context }: HilosI18nLanguagePageProps) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error('HilosI18nLanguagePage requires a provided Hilos router.')
  const route = useSignal(router.currentRoute)
  const [binding, setBinding] = useState({ context, revision: 0 })
  if (binding.context !== context)
    setBinding({ context, revision: binding.revision + 1 })
  return (
    <LanguageCard
      key={`${binding.revision}:${route.params.languageCode}`}
      context={context}
    />
  )
}

/** The windows belong to this mounted route subject and its page data. */
function LanguageCard({ context }: HilosI18nLanguagePageProps) {
  const router = useContext(HilosRouterContext)!
  const cardSignal = useMemo(
    () => createHilosI18nLanguageCard(context),
    [context],
  )
  const name = useMemo(
    () =>
      computedSignal(() => {
        const page = context.scopes.page()?.key
        if (
          page !== HilosPages.I18N_LANGUAGE &&
          page !== HilosPages.I18N_LANGUAGE_NAMES &&
          page !== HilosPages.I18N_LANGUAGE_LOCALES
        )
          return undefined
        return cardSignal.get()?.nativeName
      }),
    [context, cardSignal],
  )
  usePageInstanceName(context.scopes, HilosPages.I18N_LANGUAGE, name)
  const card = useSignal(cardSignal)
  const identity = useSignal(router.pageIdentity)
  const tabs =
    card === null
      ? []
      : hilosTabLinks(
          identity?.pageTabs ?? [],
          { languageCode: card.code },
          router.resolvePath,
        )
  const namesPath = tabs.find(
    (tab) => tab.page === HilosPages.I18N_LANGUAGE_NAMES,
  )?.to
  const localesPath = tabs.find(
    (tab) => tab.page === HilosPages.I18N_LANGUAGE_LOCALES,
  )?.to
  const countrylessState =
    card?.summary.countrylessLocale === 'on'
      ? 'Switched on'
      : card?.summary.countrylessLocale === 'off'
        ? 'Switched off'
        : 'Not created'
  const languageWindow = useMemo(
    () => createHilosI18nLanguageCardWindow(cardSignal, context),
    [cardSignal, context],
  )
  const copy = HILOS_I18N_LANGUAGE_WINDOW_COPY,
    deleteCopy = HILOS_I18N_LANGUAGE_DELETE_COPY
  const [deleteOpen, setDeleteOpen] = useState(false)
  const verdict = hilosI18nLanguageDelete(card?.summary ?? null)
  const source =
    card === null
      ? null
      : {
          languageId: card.languageId,
          code: card.code,
          nativeName: card.nativeName,
          canDelete: card.summary.canDelete,
          deleteReason: card.summary.deleteReason,
        }
  const loading = useSignal(router.pageLoading)
  return (
    <HilosAdminPage
      page={HilosPages.I18N_LANGUAGE}
      headKind="entity"
      tabsLabel="Language pages"
      headVisual={
        card ? (
          <span
            className="hilos-avatar-md rounded-3 bg-secondary-subtle text-body-emphasis d-inline-flex align-items-center justify-content-center flex-shrink-0 fw-semibold text-uppercase"
            aria-hidden="true"
            data-id="language-head-tile"
          >
            {card.code}
          </span>
        ) : undefined
      }
      headBadges={
        card ? (
          <>
            {card.summary.isDefault && (
              <span
                className={`badge ${HILOS_I18N_LANGUAGE_MARKS.default.tone}`}
                title={HILOS_I18N_LANGUAGE_MARKS.default.label}
                data-id="language-head-default"
              >
                <i
                  className={`bi ${HILOS_I18N_LANGUAGE_MARKS.default.icon}`}
                  aria-hidden="true"
                />
                <span className="visually-hidden">
                  {HILOS_I18N_LANGUAGE_MARKS.default.label}
                </span>
              </span>
            )}
            {card.summary.isOwn && (
              <span
                className={`badge ${HILOS_I18N_LANGUAGE_MARKS.own.tone}`}
                title={HILOS_I18N_LANGUAGE_MARKS.own.label}
                data-id="language-head-own"
              >
                <i
                  className={`bi ${HILOS_I18N_LANGUAGE_MARKS.own.icon}`}
                  aria-hidden="true"
                />
                <span className="visually-hidden">
                  {HILOS_I18N_LANGUAGE_MARKS.own.label}
                </span>
              </span>
            )}
          </>
        ) : undefined
      }
      headDescription={
        card ? (
          <p
            className="text-body-secondary mb-0"
            data-id="language-head-direction"
          >
            {card.rtl ? 'Right to left' : 'Left to right'}
          </p>
        ) : undefined
      }
    >
      {card ? (
        <>
          <div className="row row-cols-1 row-cols-md-3 g-3 mb-4">
            <div className="col">
              <section
                className="border rounded-3 p-3 h-100"
                data-id="language-overview-names"
              >
                <h2 className="small text-body-secondary">Names</h2>
                <div className="fs-4 fw-semibold lh-sm my-1">
                  {namesPath !== undefined ? (
                    <HilosLink
                      to={namesPath}
                      aria-label={`Names: ${card.summary.nameCount}`}
                      data-id="language-overview-name-count"
                    >
                      {card.summary.nameCount}
                    </HilosLink>
                  ) : (
                    <span data-id="language-overview-name-count">
                      {card.summary.nameCount}
                    </span>
                  )}
                </div>
                <p className="small text-body-secondary mb-0">
                  Non-empty base names in other languages; corrections do not
                  count.
                </p>
              </section>
            </div>
            <div className="col">
              <section
                className="border rounded-3 p-3 h-100"
                data-id="language-overview-locales"
              >
                <h2 className="small text-body-secondary">Locales</h2>
                <div className="fs-4 fw-semibold lh-sm my-1">
                  {localesPath !== undefined ? (
                    <HilosLink
                      to={localesPath}
                      aria-label={`Locales: ${card.summary.localeCount}`}
                      data-id="language-overview-locale-count"
                    >
                      {card.summary.localeCount}
                    </HilosLink>
                  ) : (
                    <span data-id="language-overview-locale-count">
                      {card.summary.localeCount}
                    </span>
                  )}
                </div>
                <p className="small text-body-secondary mb-0">
                  <span data-id="language-overview-enabled-locale-count">
                    {card.summary.enabledLocaleCount}
                  </span>{' '}
                  switched on, including the locale without a country.
                  {card.summary.localeCount === 0 && ' None created.'}
                </p>
              </section>
            </div>
            <div className="col">
              <section
                className="border rounded-3 p-3 h-100"
                data-id="language-overview-countryless"
              >
                <h2 className="small text-body-secondary">
                  Locale without a country
                </h2>
                <div className="fs-4 fw-semibold lh-sm my-1">
                  {localesPath !== undefined ? (
                    <HilosLink
                      to={localesPath}
                      aria-label={`Locale without a country: ${countrylessState}`}
                      data-id="language-overview-countryless-state"
                    >
                      {countrylessState}
                    </HilosLink>
                  ) : (
                    <span data-id="language-overview-countryless-state">
                      {countrylessState}
                    </span>
                  )}
                </div>
                <p className="small text-body-secondary mb-0">
                  Dates and numbers when the country is unknown.
                </p>
              </section>
            </div>
          </div>
          <section className="mb-4" data-id="language-card-main">
            <div className="d-flex align-items-center gap-2 mb-2">
              <h2 className="h5 mb-0 flex-grow-1">Main</h2>
              {card.enabled && (
                <p
                  className={`badge mb-0 ${HILOS_I18N_LANGUAGE_MARKS.frozen.tone}`}
                  data-id="language-card-frozen"
                >
                  <i
                    className={`bi me-1 ${HILOS_I18N_LANGUAGE_MARKS.frozen.icon}`}
                    aria-hidden="true"
                  />
                  Language enabled — frozen
                </p>
              )}
              {!card.enabled && (
                <p
                  className={`badge mb-0 ${HILOS_I18N_LANGUAGE_MARKS.editable.tone}`}
                  data-id="language-card-editable"
                >
                  <i
                    className={`bi me-1 ${HILOS_I18N_LANGUAGE_MARKS.editable.icon}`}
                    aria-hidden="true"
                  />
                  Language switched off — edit freely
                </p>
              )}
              {card.enabled ? (
                <button
                  type="button"
                  className="btn btn-sm btn-outline-secondary"
                  title={copy.open.view}
                  aria-label={copy.open.view}
                  data-id="language-card-view"
                  onClick={() => languageWindow.open()}
                >
                  <i className="bi bi-eye" aria-hidden="true" />
                </button>
              ) : (
                <button
                  type="button"
                  className="btn btn-sm btn-outline-primary"
                  title={copy.open.edit}
                  aria-label={copy.open.edit}
                  data-id="language-card-edit"
                  onClick={() => languageWindow.open()}
                >
                  <i className="bi bi-pencil" aria-hidden="true" />
                </button>
              )}
            </div>
            <dl className="row mb-0 border rounded-3 p-3">
              <dt className="col-sm-4">Code</dt>
              <dd className="col-sm-8">
                <span className="text-uppercase" data-id="language-card-code">
                  {card.code}
                </span>
                <span className="d-block small text-body-secondary">
                  Unique language code. Never changes: locale and name addresses
                  refer to it.
                </span>
              </dd>
              <dt className="col-sm-4">Native name</dt>
              <dd className="col-sm-8">
                <span data-id="language-card-name">{card.nativeName}</span>
                <span className="d-block small text-body-secondary">
                  How the language calls itself.
                </span>
              </dd>
              <dt className="col-sm-4">
                <label htmlFor="language-card-rtl">Writing direction</label>
              </dt>
              <dd className="col-sm-8">
                <input
                  id="language-card-rtl"
                  className="form-check-input me-2"
                  type="checkbox"
                  disabled
                  checked={card.rtl}
                  aria-label="Right to left"
                  aria-describedby="language-card-rtl-hint"
                  readOnly
                />
                <span data-id="language-card-direction">
                  {card.rtl ? 'Right to left' : 'Left to right'}
                </span>
                <span
                  id="language-card-rtl-hint"
                  className="d-block small text-body-secondary"
                >
                  Right-to-left writing reverses the product layout.
                </span>
              </dd>
            </dl>
          </section>
          <section className="mb-4" data-id="language-card-state">
            <h2 className="h5">State</h2>
            <div className="border rounded-3 p-3">
              <dl className="row mb-0">
                <dt className="col-sm-4">Availability</dt>
                <dd className="col-sm-8">
                  <span data-id="language-card-enabled">
                    {card.enabled
                      ? 'Language switched on'
                      : 'Language switched off'}
                  </span>
                  <div className="mt-2">
                    <HilosI18nLanguageSwitchOff context={context} card={card} />
                    <HilosI18nLanguageSwitchOn context={context} card={card} />
                  </div>
                </dd>
                <dt className="col-sm-4">Delete language</dt>
                <dd className="col-sm-8" data-id="language-card-delete-verdict">
                  <button
                    type="button"
                    className="btn btn-sm btn-outline-danger"
                    disabled={verdict.disabled}
                    title={deleteCopy.open}
                    aria-label={deleteCopy.open}
                    aria-describedby={
                      verdict.reason ? 'language-card-delete-reason' : undefined
                    }
                    data-id="language-card-delete"
                    onClick={() => setDeleteOpen(true)}
                  >
                    <i className="bi bi-trash" aria-hidden="true" />
                  </button>
                  {verdict.reason && (
                    <p
                      id="language-card-delete-reason"
                      className="small text-body-secondary mt-1 mb-0"
                      data-id="language-card-delete-reason"
                    >
                      {verdict.reason}
                    </p>
                  )}
                </dd>
              </dl>
            </div>
          </section>
        </>
      ) : loading ? (
        <div
          className="placeholder-glow"
          role="status"
          aria-label="Loading language"
        >
          <span className="placeholder col-6 d-block mb-2 rounded" />
          <span className="placeholder col-4 d-block rounded" />
        </div>
      ) : (
        !deleteOpen && (
          <p
            className="alert alert-secondary"
            role="status"
            data-id="language-card-unavailable"
          >
            Language details are no longer available.
          </p>
        )
      )}
      <HilosI18nLanguageWindow window={languageWindow} />
      <HilosI18nLanguageDelete
        open={deleteOpen}
        onClose={() => setDeleteOpen(false)}
        context={context}
        source={source}
        actionName={HILOS_I18N_LANGUAGE_DELETE_ACTION}
        onDeleted={() => {
          const path = router.resolvePath(HilosPages.I18N_LANGUAGES, {})
          if (path) router.navigate(path)
        }}
      />
    </HilosAdminPage>
  )
}
