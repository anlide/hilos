import { useContext, useMemo, useState } from 'react'
import {
  createHilosI18nCountryCard,
  createHilosI18nCountryCardWindow,
  createHilosI18nCountryDelete,
  HILOS_I18N_COUNTRY_WINDOW_COPY,
  HILOS_I18N_COUNTRY_DELETE_COPY,
  HILOS_I18N_COUNTRY_MARKS,
  hilosI18nCountryDeleteFromCard,
  hilosI18nCountryDeleteReason,
  hilosI18nCountryDisplayName,
  type HilosI18nCountryContext,
  type HilosI18nCountryDeleteTarget,
} from '@hilos/core/admin/i18n'
import { computedSignal, HilosPages } from '@hilos/core/base'
import { HilosAdminPage } from '../../../HilosAdminPage.js'
import { HilosLink } from '../../../HilosLink.js'
import { HilosRouterContext } from '../../../hilosRouterContext.js'
import { usePageInstanceName } from '../../../usePageInstanceName.js'
import { useSignal } from '../../../useSignal.js'
import { HilosI18nCountryDelete } from './HilosI18nCountryDelete.js'
import { HilosI18nCountryWindow } from './HilosI18nCountryWindow.js'
import { HilosI18nCountrySwitchOn } from './HilosI18nCountrySwitchOn.js'
import { HilosI18nCountrySwitchOff } from './HilosI18nCountrySwitchOff.js'

/** The project binds its connection, page scopes and action lifecycle. */
export interface HilosI18nCountryPageProps {
  context: HilosI18nCountryContext
}

/** Each route subject owns its head, windows and pending action runners. */
export function HilosI18nCountryPage({ context }: HilosI18nCountryPageProps) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error('HilosI18nCountryPage requires a provided Hilos router.')
  const route = useSignal(router.currentRoute)
  const [binding, setBinding] = useState({ context, revision: 0 })
  if (binding.context !== context)
    setBinding({ context, revision: binding.revision + 1 })
  return (
    <CountryCard
      key={`${binding.revision}:${route.params.countryId}`}
      context={context}
    />
  )
}

function CountryCard({ context }: HilosI18nCountryPageProps) {
  const router = useContext(HilosRouterContext)!
  const rawCard = useMemo(() => createHilosI18nCountryCard(context), [context])
  const cardSignal = useMemo(
    () =>
      computedSignal(() => {
        const current = rawCard.get()
        return current !== null &&
          String(current.countryId) ===
            String(router.currentRoute.get().params.countryId)
          ? current
          : null
      }),
    [rawCard, router],
  )
  // The shell is drawn here, under the hook's own re-render, so the head shows
  // the bound name in its first paint.
  const name = useMemo(
    () =>
      computedSignal(() => {
        const page = context.scopes.page()?.key
        if (
          page !== HilosPages.I18N_COUNTRY &&
          page !== HilosPages.I18N_COUNTRY_NAMES
        )
          return undefined
        const current = cardSignal.get()
        return current === null
          ? undefined
          : hilosI18nCountryDisplayName(current)
      }),
    [context, cardSignal],
  )
  usePageInstanceName(context.scopes, HilosPages.I18N_COUNTRY, name)
  const card = useSignal(cardSignal),
    loading = useSignal(router.pageLoading)
  const countryWindow = useMemo(
    () => createHilosI18nCountryCardWindow(cardSignal, context),
    [cardSignal, context],
  )
  const { deleteCountry } = useMemo(
    () => createHilosI18nCountryDelete(context),
    [context],
  )
  const [target, setTarget] = useState<HilosI18nCountryDeleteTarget | null>(
    null,
  )
  const source = hilosI18nCountryDeleteFromCard(card),
    copy = HILOS_I18N_COUNTRY_WINDOW_COPY
  const namesPath =
    card === null
      ? undefined
      : router.resolvePath(HilosPages.I18N_COUNTRY_NAMES, {
          countryCode: card.code,
          countryId: String(card.countryId),
        })
  const enabledLocaleCount =
    card?.summary.locales.filter((locale) => locale.enabled).length ?? 0
  // A country deleted elsewhere under the open card turns it into the shell's
  // 404 (HIL-1603): head and body go, and the edit window stays in modals with
  // its draft, its live source now empty, so it says Deleted.
  return (
    <HilosAdminPage
      page={HilosPages.I18N_COUNTRY}
      headKind="entity"
      tabsLabel="Country pages"
      preserveModalDraftsOnDelete
      modals={<HilosI18nCountryWindow window={countryWindow} />}
      headVisual={
        card ? (
          <span
            className="hilos-avatar-md rounded-3 bg-secondary-subtle text-body-emphasis d-inline-flex align-items-center justify-content-center flex-shrink-0 fw-semibold text-uppercase"
            aria-hidden="true"
            data-id="country-head-tile"
          >
            {card.code}
          </span>
        ) : undefined
      }
      headMeta={
        card ? (
          <span
            className="font-monospace small text-body-secondary text-uppercase"
            data-id="country-head-code"
          >
            {card.code}
          </span>
        ) : undefined
      }
      headBadges={
        card?.summary.isOwn ? (
          <span
            className={`badge ${HILOS_I18N_COUNTRY_MARKS.own.tone}`}
            title="This country is absent from the catalog and source."
            data-id="country-head-own"
          >
            <i
              className={`bi ${HILOS_I18N_COUNTRY_MARKS.own.icon}`}
              aria-hidden="true"
            />
            <span className="visually-hidden">
              {HILOS_I18N_COUNTRY_MARKS.own.label}. This country is absent from
              the catalog and source.
            </span>
          </span>
        ) : undefined
      }
      headDescription={
        card ? (
          <p
            className="text-body-secondary mb-0"
            data-id="country-head-description"
          >
            Currency {card.currencySymbol} {card.currencyCode} · Default locale{' '}
            {card.summary.defaultLocaleCode ?? 'not chosen'}
          </p>
        ) : undefined
      }
    >
      {card ? (
        <>
          <div className="row row-cols-1 row-cols-sm-2 g-3 mb-4">
            <div className="col">
              <section
                className="border rounded-3 p-3 h-100"
                data-id="country-overview-names"
              >
                <h2 className="small text-body-secondary">Names</h2>
                <div className="fs-4 fw-semibold lh-sm my-1">
                  {namesPath !== undefined ? (
                    <HilosLink
                      to={namesPath}
                      aria-label={`Names: ${card.summary.nameCount}`}
                      data-id="country-overview-name-count"
                    >
                      {card.summary.nameCount}
                    </HilosLink>
                  ) : (
                    <span data-id="country-overview-name-count">
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
                data-id="country-overview-locales"
              >
                <h2 className="small text-body-secondary">Locales</h2>
                <div
                  className="fs-4 fw-semibold lh-sm my-1"
                  data-id="country-overview-locale-count"
                >
                  {card.summary.localeCount}
                </div>
                <p className="small text-body-secondary mb-0">
                  Switched on:{' '}
                  <span data-id="country-overview-enabled-locale-count">
                    {enabledLocaleCount}
                  </span>{' '}
                  · Edited in their language cards.
                </p>
              </section>
            </div>
          </div>

          <section className="mb-4" data-id="country-card-main">
            <div className="d-flex flex-wrap align-items-center gap-2 mb-2">
              <h2 className="h5 mb-0 me-auto">Main</h2>
              {card.enabled ? (
                <span
                  className="badge bg-warning-subtle text-warning-emphasis"
                  data-id="country-card-frozen"
                >
                  <i className="bi bi-lock me-1" aria-hidden="true" />
                  Country switched on — frozen
                </span>
              ) : (
                <span
                  className="badge bg-info-subtle text-info-emphasis"
                  data-id="country-card-editable"
                >
                  <i className="bi bi-pencil me-1" aria-hidden="true" />
                  Country switched off — edit freely
                </span>
              )}
              <button
                type="button"
                className={`btn btn-sm ${card.enabled ? 'btn-outline-secondary' : 'btn-outline-primary'}`}
                title={card.enabled ? copy.open.view : copy.open.edit}
                aria-label={card.enabled ? copy.open.view : copy.open.edit}
                data-id={
                  card.enabled ? 'country-card-view' : 'country-card-edit'
                }
                onClick={() =>
                  countryWindow.open(card.enabled ? 'view' : 'edit')
                }
              >
                <i
                  className={`bi ${card.enabled ? 'bi-eye' : 'bi-pencil'}`}
                  aria-hidden="true"
                />
              </button>
            </div>
            <dl className="border rounded-3 p-3 mb-0">
              <div className="row align-items-baseline g-2 py-2 border-bottom">
                <dt className="col-sm-3 small text-body-secondary fw-normal">
                  Code
                </dt>
                <dd className="col-sm-3 small fw-semibold mb-0">
                  <span
                    className="font-monospace text-uppercase"
                    data-id="country-card-code"
                  >
                    {card.code}
                  </span>
                </dd>
                <dd className="col-sm-6 small text-body-secondary mb-0">
                  ISO 3166-1 alpha-2, unique. Never changes: locales and names
                  refer to it.
                </dd>
              </div>
              <div className="row align-items-baseline g-2 py-2 border-bottom">
                <dt className="col-sm-3 small text-body-secondary fw-normal">
                  Currency
                </dt>
                <dd
                  className="col-sm-3 small fw-semibold mb-0"
                  data-id="country-card-currency"
                >
                  {card.currencySymbol}{' '}
                  <span className="font-monospace">{card.currencyCode}</span>
                </dd>
                <dd className="col-sm-6 small text-body-secondary mb-0">
                  The symbol is for display, the ISO 4217 code is for
                  calculations.
                </dd>
              </div>
              <div className="row align-items-baseline g-2 py-2">
                <dt className="col-sm-3 small text-body-secondary fw-normal">
                  Default locale
                </dt>
                <dd
                  className="col-sm-3 small fw-semibold mb-0"
                  data-id="country-card-default-locale"
                >
                  {card.summary.defaultLocaleCode ?? 'not chosen'}
                </dd>
                <dd className="col-sm-6 small text-body-secondary mb-0">
                  Formats used when the country is known but the language is
                  not. You choose the locale.
                </dd>
              </div>
            </dl>
          </section>

          <section className="mb-4" data-id="country-card-state">
            <h2 className="h5">State</h2>
            <div className="border rounded-3 p-3">
              <div className="d-flex flex-wrap align-items-center gap-2 py-2 border-bottom">
                <div className="flex-grow-1">
                  <div
                    className="fw-semibold small"
                    data-id="country-card-enabled"
                  >
                    {card.enabled
                      ? 'Country switched on'
                      : 'Country switched off'}
                  </div>
                  <p className="small text-body-secondary mb-0">
                    {card.enabled
                      ? 'Values are frozen; switch the country off to edit them.'
                      : 'Values can be edited; the code never changes.'}
                  </p>
                </div>
                <div className="text-nowrap">
                  <HilosI18nCountrySwitchOn context={context} card={card} />
                  <HilosI18nCountrySwitchOff context={context} card={card} />
                </div>
              </div>
              <div
                className="d-flex flex-wrap align-items-center gap-2 py-2"
                data-id="country-card-delete-verdict"
              >
                <div className="flex-grow-1">
                  <div className="fw-semibold small">Delete country</div>
                  <p
                    id="country-card-delete-reason"
                    className="small text-body-secondary mb-0"
                    data-id="country-card-delete-reason"
                  >
                    {hilosI18nCountryDeleteReason(source)}
                  </p>
                </div>
                <button
                  type="button"
                  className={`btn btn-sm ${card.summary.canDelete ? 'btn-outline-danger' : 'border border-secondary text-body-secondary'}`}
                  aria-label={HILOS_I18N_COUNTRY_DELETE_COPY.open}
                  aria-disabled={!card.summary.canDelete || undefined}
                  aria-describedby="country-card-delete-reason"
                  data-id="country-card-delete"
                  onClick={() => {
                    if (source?.canDelete) setTarget(source)
                  }}
                >
                  <i className="bi bi-trash" aria-hidden="true" />
                </button>
              </div>
            </div>
          </section>
        </>
      ) : loading ? (
        <div
          className="placeholder-glow"
          role="status"
          aria-label="Loading country"
        >
          <span className="placeholder col-6 d-block mb-2 rounded" />
          <span className="placeholder col-4 d-block rounded" />
        </div>
      ) : (
        !target && (
          <p
            className="alert alert-secondary"
            role="status"
            data-id="country-card-unavailable"
          >
            Country details are no longer available.
          </p>
        )
      )}
      <HilosI18nCountryDelete
        open={target !== null}
        target={target}
        source={source}
        deleteCountry={deleteCountry}
        onClose={() => setTarget(null)}
        onDeleted={() => {
          const path = router.resolvePath(HilosPages.I18N_COUNTRIES, {})
          if (path) router.navigate(path)
        }}
      />
    </HilosAdminPage>
  )
}
