import { useContext, useEffect, useMemo, useState } from 'react'
import {
  createHilosI18nBuiltInCatalog,
  createHilosI18nBuiltInCountryCodes,
  createHilosI18nCountryAdd,
  createHilosI18nCountryRowWindow,
  createHilosI18nCountriesTable,
  createHilosI18nCountryRowDelete,
  hilosI18nCountryDeleteFromRow,
  HILOS_I18N_COUNTRY_WINDOW_COPY,
  type HilosI18nCountryDeleteTarget,
  HILOS_I18N_COUNTRY_MARKS,
  HILOS_I18N_COUNTRIES_LEGEND,
  HILOS_TABLE_ACTIONS_KEY,
  HilosI18nCountryRowKey,
  HilosPages,
  type HilosI18nCountryContext,
} from '@hilos/core'
import { HilosAdminPage } from '../../../HilosAdminPage.js'
import { HilosLink } from '../../../HilosLink.js'
import { HilosViewportTable } from '../../../HilosViewportTable.js'
import { HilosRouterContext } from '../../../hilosRouterContext.js'
import { useSignal } from '../../../useSignal.js'
import { HilosI18nCountryDelete } from '../details/HilosI18nCountryDelete.js'
import { HilosI18nCountryWindow } from '../details/HilosI18nCountryWindow.js'
import { HilosI18nCatalogNote } from './HilosI18nCatalogNote.js'
import { HilosI18nCountryAdd } from './HilosI18nCountryAdd.js'
import { HilosI18nCountryRowActions } from './HilosI18nCountryRowActions.js'
import { HilosI18nMarksLegend } from './HilosI18nMarksLegend.js'

/** The project binds the country list to its connection, scopes and actions. */
export interface HilosI18nCountriesPageProps {
  context: HilosI18nCountryContext
}

/** The country list over the shared viewport table, catalog and focused row windows. */
export function HilosI18nCountriesPage({
  context,
}: HilosI18nCountriesPageProps) {
  const [binding, setBinding] = useState({ context, revision: 0 })
  if (binding.context !== context)
    setBinding({ context, revision: binding.revision + 1 })
  return (
    <HilosAdminPage page={HilosPages.I18N_COUNTRIES}>
      <CountriesList key={binding.revision} context={context} />
    </HilosAdminPage>
  )
}

/** A context replacement unmounts the old tracked requests along with their windows. */
function CountriesList({ context }: HilosI18nCountriesPageProps) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error('HilosI18nCountriesPage requires a provided Hilos router.')
  const catalogSignal = useMemo(
    () => createHilosI18nBuiltInCatalog(context),
    [context],
  )
  const catalog = useMemo(
    () => createHilosI18nBuiltInCountryCodes(context),
    [context],
  )
  const builtInCatalog = useSignal(catalogSignal)
  const add = useMemo(
    () => createHilosI18nCountryAdd(context, catalog),
    [context, catalog],
  )
  const countries = useMemo(
    () => createHilosI18nCountriesTable(context, { openAdd: add.open }),
    [context, add],
  )
  const countryWindow = useMemo(
    () => createHilosI18nCountryRowWindow(countries.controller, context),
    [countries, context],
  )
  const [target, setTarget] = useState<HilosI18nCountryDeleteTarget | null>(
    null,
  )
  const { deleteCountry } = useMemo(
    () => createHilosI18nCountryRowDelete(context),
    [context],
  )
  const copy = HILOS_I18N_COUNTRY_WINDOW_COPY
  const focused = useSignal(countries.controller.focusedRow)
  const deleteSource =
    focused?.code === target?.code
      ? hilosI18nCountryDeleteFromRow(focused)
      : null
  useEffect(() => {
    countries.start()
    return () => {
      countries.controller.releaseFocus()
      countries.dispose()
      add.dispose()
    }
  }, [countries, add])
  function closeDelete(): void {
    setTarget(null)
    countries.controller.releaseFocus()
  }
  function openDelete(code: string): void {
    const fresh = countries.controller.focusRow(code)
    if (!fresh || fresh.code !== code || !fresh.canDelete) {
      countries.controller.releaseFocus()
      return
    }
    setTarget(hilosI18nCountryDeleteFromRow(fresh))
  }
  return (
    <>
      {builtInCatalog && (
        <HilosI18nCatalogNote
          catalog={builtInCatalog}
          dataId="i18n-countries-catalog"
        >
          The framework's built-in catalog holds {builtInCatalog.countryCount}{' '}
          countries with their currency and their names in its{' '}
          {builtInCatalog.languageCount} languages; each of them arrives on its
          own, switched off. Whenever a framework update changes the catalog, a
          country's currency is refreshed while that country is switched off,
          and its names wherever nobody has edited them. A code that is not in
          the catalog can still be added as a country of your own, but keeping
          it up is on you.
        </HilosI18nCatalogNote>
      )}
      <HilosViewportTable
        controller={countries.controller}
        cells={{
          [HilosI18nCountryRowKey.code]: (row) => {
            const path = router.resolvePath(HilosPages.I18N_COUNTRY, {
              countryCode: row.code,
            })
            return path ? (
              <HilosLink
                to={path}
                className={`text-uppercase${row.enabled ? '' : ' link-secondary'}`}
                data-id={`i18n-countries-link-${row.code}`}
              >
                {row.code}
              </HilosLink>
            ) : (
              <span
                className={`text-uppercase${row.enabled ? '' : ' text-body-secondary'}`}
                data-id={`i18n-countries-link-${row.code}`}
              >
                {row.code}
              </span>
            )
          },
          [HilosI18nCountryRowKey.name]: (row) => (
            <>
              <span className={row.enabled ? undefined : 'text-body-secondary'}>
                {row.name ?? row.code.toUpperCase()}
              </span>
              {row.isOwn && (
                <span
                  className={`badge ms-1 ${HILOS_I18N_COUNTRY_MARKS.own.tone}`}
                  title={HILOS_I18N_COUNTRY_MARKS.own.label}
                  data-id={`i18n-countries-own-${row.code}`}
                >
                  <i
                    className={`bi ${HILOS_I18N_COUNTRY_MARKS.own.icon}`}
                    aria-hidden="true"
                  />
                  <span className="visually-hidden">
                    {HILOS_I18N_COUNTRY_MARKS.own.label}
                  </span>
                </span>
              )}
            </>
          ),
          [HilosI18nCountryRowKey.currencyCode]: (row) => (
            <>
              <span
                className={`fw-semibold${row.enabled ? '' : ' text-body-secondary'}`}
              >
                {row.currencySymbol}
              </span>
              <span className="font-monospace text-body-secondary ms-1">
                {row.currencyCode}
              </span>
            </>
          ),
          [HilosI18nCountryRowKey.defaultLocaleCode]: (row) =>
            row.defaultLocaleCode ? (
              <span
                className={`font-monospace${row.enabled ? '' : ' text-body-secondary'}`}
              >
                {row.defaultLocaleCode}
              </span>
            ) : (
              <span
                className="badge bg-body-tertiary border text-body-secondary fw-normal"
                data-id={`i18n-countries-locale-none-${row.code}`}
              >
                <i className="bi bi-dash-circle me-1" aria-hidden="true" />
                Not chosen
              </span>
            ),
          [HilosI18nCountryRowKey.enabled]: (row) => (
            <input
              className="form-check-input"
              type="checkbox"
              disabled
              checked={row.enabled}
              aria-label="Enabled"
              data-id={`i18n-countries-enabled-${row.code}`}
            />
          ),
          [HILOS_TABLE_ACTIONS_KEY]: (row) => (
            <div className="d-flex align-items-start gap-2">
              <button
                type="button"
                className={`btn btn-sm ${row.enabled ? 'btn-outline-secondary' : 'btn-outline-primary'}`}
                title={row.enabled ? copy.open.view : copy.open.edit}
                aria-label={row.enabled ? copy.open.view : copy.open.edit}
                data-id={`i18n-countries-${row.enabled ? 'view' : 'edit'}-${row.code}`}
                onClick={() =>
                  countryWindow.open(row.enabled ? 'view' : 'edit', row.code)
                }
              >
                <i
                  className={`bi ${row.enabled ? 'bi-eye' : 'bi-pencil'}`}
                  aria-hidden="true"
                />
              </button>
              <HilosI18nCountryRowActions
                row={row}
                onDelete={() => openDelete(row.code)}
              />
            </div>
          ),
        }}
      />
      <HilosI18nMarksLegend
        rows={HILOS_I18N_COUNTRIES_LEGEND}
        dataId="i18n-countries-legend"
        className="mt-4"
      />
      <HilosI18nCountryAdd add={add} />
      <HilosI18nCountryWindow window={countryWindow} />
      <HilosI18nCountryDelete
        open={target !== null}
        target={target}
        onClose={closeDelete}
        source={deleteSource}
        deleteCountry={deleteCountry}
      />
    </>
  )
}
