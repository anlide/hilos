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
      key={`${binding.revision}:${route.params.countryCode}`}
      context={context}
    />
  )
}

function CountryCard({ context }: HilosI18nCountryPageProps) {
  const router = useContext(HilosRouterContext)!
  const cardSignal = useMemo(
    () => createHilosI18nCountryCard(context),
    [context],
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
          <section className="mb-4" data-id="country-card-main">
            <div className="d-flex align-items-center gap-2 mb-2">
              <h2 className="h5 mb-0">Main</h2>
              <button
                type="button"
                className={`btn btn-sm ms-auto ${card.enabled ? 'btn-outline-secondary' : 'btn-outline-primary'}`}
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
            <div className="border rounded-3 p-3">
              {card.enabled && (
                <p
                  className="badge text-bg-warning mb-3"
                  data-id="country-card-frozen"
                >
                  Country enabled — frozen
                </p>
              )}
              <dl className="row mb-0">
                <dt className="col-sm-4">Code</dt>
                <dd className="col-sm-8">
                  <span className="text-uppercase" data-id="country-card-code">
                    {card.code}
                  </span>
                  <span className="d-block small text-body-secondary">
                    ISO 3166-1 alpha-2, unique. Never changes: locales and names
                    refer to it.
                  </span>
                </dd>
                <dt className="col-sm-4">Currency</dt>
                <dd className="col-sm-8">
                  <span data-id="country-card-currency">
                    {card.currencySymbol} {card.currencyCode}
                  </span>
                  <span className="d-block small text-body-secondary">
                    The symbol is for display, the ISO 4217 code is for
                    calculations.
                  </span>
                </dd>
                <dt className="col-sm-4">Default locale</dt>
                <dd className="col-sm-8 mb-0">
                  <span data-id="country-card-default-locale">
                    {card.summary.defaultLocaleCode ?? 'not chosen'}
                  </span>
                  <span className="d-block small text-body-secondary">
                    Formats used when the country is known but the language is
                    not. Empty takes the formats from the language.
                  </span>
                </dd>
              </dl>
            </div>
          </section>
          <section className="mb-4" data-id="country-card-state">
            <h2 className="h5">State</h2>
            <dl className="row mb-0 border rounded-3 p-3">
              <dt className="col-sm-4">Availability</dt>
              <dd className="col-sm-8">
                <span data-id="country-card-enabled">
                  {card.enabled ? 'Enabled' : 'Disabled'}
                </span>
                <span className="d-block small text-body-secondary">
                  {card.enabled
                    ? 'Values are frozen; switch the country off to edit them.'
                    : 'Values can be edited; the code never changes.'}
                </span>
                <div className="mt-2">
                  <HilosI18nCountrySwitchOn context={context} card={card} />
                  <HilosI18nCountrySwitchOff context={context} card={card} />
                </div>
              </dd>
              <dt className="col-sm-4">Delete country</dt>
              <dd
                className="col-sm-8 mb-0"
                data-id="country-card-delete-verdict"
              >
                <span
                  id="country-card-delete-reason"
                  data-id="country-card-delete-reason"
                >
                  {hilosI18nCountryDeleteReason(source)}
                </span>
                <div className="mt-2">
                  <button
                    type="button"
                    className={`btn btn-sm ${card.summary.canDelete ? 'btn-outline-danger' : 'border border-secondary text-body-secondary'}`}
                    aria-disabled={!card.summary.canDelete || undefined}
                    aria-describedby="country-card-delete-reason"
                    data-id="country-card-delete"
                    onClick={() => {
                      if (source?.canDelete) setTarget(source)
                    }}
                  >
                    {HILOS_I18N_COUNTRY_DELETE_COPY.open}
                  </button>
                </div>
              </dd>
            </dl>
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
