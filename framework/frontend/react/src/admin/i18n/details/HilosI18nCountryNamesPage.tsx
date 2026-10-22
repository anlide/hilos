import { useContext, useEffect, useMemo, useRef, useState } from 'react'
import { computedSignal, HilosPages } from '@hilos/core/base'
import {
  createHilosI18nCountryCard,
  createHilosI18nCountryNameWindow,
  createHilosI18nNamesTable,
  HILOS_I18N_COUNTRY_NAMES,
  HILOS_I18N_COUNTRY_NAME_COPY,
  HILOS_I18N_COUNTRY_NAME_DELETE_COPY,
  HILOS_I18N_COUNTRY_MARKS,
  hilosI18nCountryDisplayName,
  type HilosI18nCountryContext,
  type HilosI18nCountryNameDeleteTarget,
  type HilosI18nNameRow,
} from '@hilos/core/admin/i18n'
import { HilosAdminPage } from '../../../HilosAdminPage.js'
import { LoadingButton } from '../../../LoadingButton.js'
import { HilosRouterContext } from '../../../hilosRouterContext.js'
import { usePageInstanceName } from '../../../usePageInstanceName.js'
import { useSignal } from '../../../useSignal.js'
import { HilosI18nCountryNameDelete } from './HilosI18nCountryNameDelete.js'
import { HilosI18nCountryNameWindow } from './HilosI18nCountryNameWindow.js'
import { HilosI18nNamesTable } from './HilosI18nNamesTable.js'

/** The project supplies the page's existing country context. */
export interface HilosI18nCountryNamesPageProps {
  context: HilosI18nCountryContext
}

/** Names and corrections of the code-addressed country, over the shared core. */
export function HilosI18nCountryNamesPage({
  context,
}: HilosI18nCountryNamesPageProps) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error(
      'HilosI18nCountryNamesPage requires a provided Hilos router.',
    )
  const route = useSignal(router.currentRoute)
  const [binding, setBinding] = useState({ context, revision: 0 })
  if (binding.context !== context)
    setBinding({ context, revision: binding.revision + 1 })
  const code = (route.params.countryCode as string | undefined) ?? ''
  return (
    <CountryNames
      key={`${binding.revision}:${code}`}
      context={context}
      code={code}
    />
  )
}

function CountryNames({
  context,
  code,
}: HilosI18nCountryNamesPageProps & { code: string }) {
  const router = useContext(HilosRouterContext)!
  const country = useMemo(
    () =>
      computedSignal(
        () =>
          (router.currentRoute.get().params.countryCode as
            string | undefined) ?? '',
      ),
    [router],
  )
  const liveCard = useMemo(() => createHilosI18nCountryCard(context), [context])
  // The shell is drawn here, under the hook's own re-render, so the head shows
  // the bound name in its first paint. The name belongs to the country.
  const name = useMemo(
    () =>
      computedSignal(() => {
        const page = context.scopes.page()?.key
        if (
          page !== HilosPages.I18N_COUNTRY &&
          page !== HilosPages.I18N_COUNTRY_NAMES
        )
          return undefined
        const current = liveCard.get()
        return current === null
          ? undefined
          : hilosI18nCountryDisplayName(current)
      }),
    [context, liveCard],
  )
  usePageInstanceName(context.scopes, HilosPages.I18N_COUNTRY, name)
  const card = useSignal(liveCard),
    loading = useSignal(router.pageLoading)
  const names = useMemo(
    () =>
      createHilosI18nNamesTable(
        context,
        HILOS_I18N_COUNTRY_NAMES,
        computedSignal(() =>
          liveCard.get()?.code === country.get()
            ? (liveCard.get()?.countryId ?? null)
            : null,
        ),
      ),
    [context, country, liveCard],
  )
  const nameWindow = useMemo(
    () =>
      createHilosI18nCountryNameWindow(
        names.controller,
        liveCard,
        country,
        context,
      ),
    [names, liveCard, country, context],
  )
  const focused = useSignal(names.controller.focusedRow)
  const [target, setTarget] = useState<HilosI18nCountryNameDeleteTarget | null>(
    null,
  )
  const opening = useRef(0),
    mounted = useRef(false)
  useEffect(() => {
    mounted.current = true
    names.start()
    return () => {
      mounted.current = false
      opening.current++
      names.controller.releaseFocus()
      names.dispose()
    }
  }, [names])
  const source =
    card?.countryId === target?.countryId &&
    focused?.inLanguageId === target?.inLanguageId
      ? (focused ?? null)
      : null
  const nameCopy = HILOS_I18N_COUNTRY_NAME_COPY,
    copy = HILOS_I18N_COUNTRY_NAME_DELETE_COPY
  function closeDelete(): void {
    opening.current++
    setTarget(null)
    names.controller.releaseFocus()
  }
  function openName(
    row: HilosI18nNameRow,
    localeId: number | null = null,
  ): void {
    if (nameWindow.saving.get()) return
    closeDelete()
    nameWindow.open(row.rowKey, localeId)
  }
  async function openDelete(
    row: HilosI18nNameRow,
    localeId?: number,
  ): Promise<void> {
    if (target || nameWindow.saving.get()) return
    nameWindow.close()
    const attempt = ++opening.current
    const resolved = await names.controller.focusRow(row.rowKey)
    if (!mounted.current || attempt !== opening.current) return
    const fresh = names.controller.focusedRow.get(),
      subject = liveCard.get()
    if (
      resolved === null ||
      subject?.code !== code ||
      fresh?.inLanguageId !== row.inLanguageId ||
      fresh.name === null
    ) {
      closeDelete()
      return
    }
    const correction =
      localeId === undefined
        ? null
        : fresh.corrections.find((item) => item.localeId === localeId)
    const expectedName = localeId === undefined ? fresh.name : correction?.name
    const nameId = localeId === undefined ? fresh.nameId : correction?.nameId
    if (expectedName == null || nameId == null) {
      closeDelete()
      return
    }
    const coordinates = {
      countryId: subject.countryId,
      inLanguageId: fresh.inLanguageId,
      nameId,
      countryCode: code,
      countryName: subject.summary.name ?? subject.code.toUpperCase(),
      inLanguageCode: fresh.code,
      inLanguageNativeName: fresh.nativeName,
      expectedName,
    }
    setTarget(
      localeId === undefined
        ? { ...coordinates, kind: 'base' }
        : {
            ...coordinates,
            kind: 'override',
            localeId,
            localeCode: correction!.localeCode,
          },
    )
  }
  return (
    <HilosAdminPage
      page={HilosPages.I18N_COUNTRY_NAMES}
      headKind="entity"
      tabsLabel="Country pages"
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
          <section className="mb-4" data-id="country-names">
            <h2 className="h5 d-flex flex-wrap align-items-center gap-2">
              Names in different languages
              <span className="badge bg-body-tertiary text-body-secondary border fw-normal">
                A row for every language
              </span>
            </h2>
            <HilosI18nNamesTable
              controller={names.controller}
              nameActions={(row) => (
                <>
                  <button
                    type="button"
                    className="btn btn-sm btn-outline-secondary"
                    aria-label={nameCopy.open(
                      row.name === null,
                      card.summary.name ?? card.code.toUpperCase(),
                      row.nativeName,
                    )}
                    data-id={`i18n-country-name-${row.name === null ? 'add' : 'edit'}-${row.code}`}
                    onClick={() => openName(row)}
                  >
                    <i
                      className={`bi ${row.name === null ? 'bi-plus-lg' : 'bi-pencil'}`}
                      aria-hidden="true"
                    />
                  </button>
                  {row.name !== null && (
                    <LoadingButton
                      className="btn-sm btn-outline-danger"
                      aria-label={copy.baseLabel(row.nativeName)}
                      data-id={`i18n-names-delete-${row.code}`}
                      onClick={() => void openDelete(row)}
                    >
                      <i className="bi bi-trash" aria-hidden="true" />
                    </LoadingButton>
                  )}
                </>
              )}
              correctionActions={(row, correction) => (
                <>
                  {row.name !== null && (
                    <button
                      type="button"
                      className="btn btn-sm btn-outline-secondary"
                      aria-label={nameCopy.open(
                        correction.name === null,
                        card.summary.name ?? card.code.toUpperCase(),
                        row.nativeName,
                        correction.localeCode,
                      )}
                      data-id={`i18n-country-name-locale-${correction.name === null ? 'add' : 'edit'}-${correction.localeCode}`}
                      onClick={() => openName(row, correction.localeId)}
                    >
                      <i
                        className={`bi ${correction.name === null ? 'bi-plus-lg' : 'bi-pencil'}`}
                        aria-hidden="true"
                      />
                    </button>
                  )}
                  {correction.name !== null && (
                    <LoadingButton
                      className="btn-sm btn-outline-danger"
                      aria-label={copy.overrideLabel(correction.localeCode)}
                      data-id={`i18n-names-delete-${correction.localeCode}`}
                      onClick={() => void openDelete(row, correction.localeId)}
                    >
                      <i className="bi bi-trash" aria-hidden="true" />
                    </LoadingButton>
                  )}
                </>
              )}
            />
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
        <p
          className="alert alert-secondary"
          role="status"
          data-id="country-names-unavailable"
        >
          Country details are no longer available.
        </p>
      )}
      <HilosI18nCountryNameDelete
        open={target !== null}
        onClose={closeDelete}
        context={context}
        target={target}
        source={source}
      />
      <HilosI18nCountryNameWindow window={nameWindow} />
    </HilosAdminPage>
  )
}
