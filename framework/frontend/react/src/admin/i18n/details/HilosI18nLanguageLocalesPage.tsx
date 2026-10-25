import { useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { computedSignal, HilosPages } from '@hilos/core/base'
import {
  createHilosI18nLanguageCard,
  createHilosI18nLanguageLocalesTable,
  createHilosI18nLocaleDelete,
  createHilosI18nLocaleTemplates,
  hilosI18nLocaleCountryLabel,
  hilosI18nLocaleWindowMode,
  HILOS_I18N_LOCALE_COPY,
  HILOS_I18N_LANGUAGE_MARKS,
  HilosI18nLocaleRowKey,
  type HilosI18nLanguageContext,
  type HilosI18nLocaleDeleteTarget,
  type HilosI18nLocaleRow,
  type HilosI18nLocaleWindowMode,
} from '@hilos/core/admin/i18n'
import { HilosAdminPage } from '../../../HilosAdminPage.js'
import { HilosViewportTable } from '../../../HilosViewportTable.js'
import { HilosRouterContext } from '../../../hilosRouterContext.js'
import { usePageInstanceName } from '../../../usePageInstanceName.js'
import { useSignal } from '../../../useSignal.js'
import { HilosI18nLocaleDelete } from './HilosI18nLocaleDelete.js'
import { HilosI18nLocaleWindow } from './HilosI18nLocaleWindow.js'

/** The project binds the page to its existing language context. */
export interface HilosI18nLanguageLocalesPageProps {
  context: HilosI18nLanguageContext
}
const PLATE = 'badge bg-body-tertiary text-body-secondary border fw-normal'

/** The pair table of one code-addressed language, including its countryless row. */
export function HilosI18nLanguageLocalesPage({
  context,
}: HilosI18nLanguageLocalesPageProps) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error(
      'HilosI18nLanguageLocalesPage requires a provided Hilos router.',
    )
  const route = useSignal(router.currentRoute)
  const [binding, setBinding] = useState({ context, revision: 0 })
  if (binding.context !== context)
    setBinding({ context, revision: binding.revision + 1 })
  const code = (route.params.languageCode as string | undefined) ?? ''
  return (
    <LanguageLocales
      key={`${binding.revision}:${code}`}
      context={context}
      code={code}
    />
  )
}

function LanguageLocales({
  context,
  code,
}: HilosI18nLanguageLocalesPageProps & { code: string }) {
  const router = useContext(HilosRouterContext)!
  const language = useMemo(
    () =>
      computedSignal(
        () =>
          (router.currentRoute.get().params.languageCode as
            string | undefined) ?? '',
      ),
    [router],
  )
  const liveCard = useMemo(
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
        return liveCard.get()?.nativeName
      }),
    [context, liveCard],
  )
  usePageInstanceName(context.scopes, HilosPages.I18N_LANGUAGE, name)
  const liveTemplates = useMemo(
    () => createHilosI18nLocaleTemplates(context),
    [context],
  )
  const card = useSignal(liveCard),
    templates = useSignal(liveTemplates),
    loading = useSignal(router.pageLoading)
  const locales = useMemo(
    () =>
      createHilosI18nLanguageLocalesTable(
        context,
        computedSignal(() =>
          liveCard.get()?.code === language.get()
            ? (liveCard.get()?.languageId ?? null)
            : null,
        ),
      ),
    [context, language, liveCard],
  )
  const { deleteLocale } = useMemo(
    () => createHilosI18nLocaleDelete(context),
    [context],
  )
  const [opened, setOpened] = useState<{
    rowKey: string
    mode: HilosI18nLocaleWindowMode
  } | null>(null)
  const [deleteTarget, setDeleteTarget] =
    useState<HilosI18nLocaleDeleteTarget | null>(null)
  const focused = useSignal(locales.controller.focusedRow)
  useEffect(() => {
    locales.start()
    return () => {
      locales.controller.releaseFocus()
      locales.dispose()
    }
  }, [locales])
  const closeWindow = useCallback(() => setOpened(null), [])
  const closeDelete = useCallback(() => {
    setDeleteTarget(null)
    locales.controller.releaseFocus()
  }, [locales])
  const deleteSource: HilosI18nLocaleDeleteTarget | null =
    deleteTarget &&
    card?.languageId === deleteTarget.languageId &&
    focused?.localeId === deleteTarget.localeId &&
    focused.localeCode !== null &&
    focused.formats !== null
      ? {
          languageId: focused.languageId,
          countryId: focused.countryId,
          localeId: focused.localeId!,
          languageCode: card.code,
          countryCode: focused.countryCode,
          localeCode: focused.localeCode,
          nativeName: card.nativeName,
          countryLabel: hilosI18nLocaleCountryLabel(focused),
        }
      : null
  function pairCode(row: HilosI18nLocaleRow): string {
    return (
      row.localeCode ??
      `${card?.code ?? ''}${row.countryCode === null ? '' : '-' + row.countryCode.toUpperCase()}`
    )
  }

  function openWindow(row: HilosI18nLocaleRow): void {
    if (opened || deleteTarget || liveCard.get()?.code !== code) return
    const fresh = locales.controller.focusRow(row.rowKey)
    if (!fresh) {
      locales.controller.releaseFocus()
      return
    }
    setOpened({ rowKey: fresh.rowKey, mode: hilosI18nLocaleWindowMode(fresh) })
  }
  function openDelete(row: HilosI18nLocaleRow): void {
    if (opened || deleteTarget) return
    const fresh = locales.controller.focusRow(row.rowKey),
      subject = liveCard.get()
    if (
      !fresh ||
      fresh.localeCode === null ||
      fresh.formats === null ||
      subject?.code !== code
    ) {
      locales.controller.releaseFocus()
      return
    }
    setDeleteTarget({
      languageId: fresh.languageId,
      countryId: fresh.countryId,
      localeId: fresh.localeId!,
      languageCode: code,
      countryCode: fresh.countryCode,
      localeCode: fresh.localeCode,
      nativeName: subject.nativeName,
      countryLabel: hilosI18nLocaleCountryLabel(fresh),
    })
  }
  return (
    <HilosAdminPage
      page={HilosPages.I18N_LANGUAGE_LOCALES}
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
          <HilosViewportTable
            controller={locales.controller}
            cells={{
              [HilosI18nLocaleRowKey.countryCode]: (row) =>
                row.countryCode === null ? (
                  <span
                    className="fst-italic text-body-secondary"
                    data-id={`i18n-locales-country-${pairCode(row)}`}
                  >
                    — no country
                  </span>
                ) : (
                  <span
                    className={
                      row.enabled === false ? 'text-body-secondary' : undefined
                    }
                    data-id={`i18n-locales-country-${pairCode(row)}`}
                  >
                    <span className="text-uppercase text-body-secondary me-1">
                      {row.countryCode}
                    </span>
                    {row.countryName}
                  </span>
                ),
              [HilosI18nLocaleRowKey.localeCode]: (row) =>
                row.localeCode === null ? (
                  <span
                    className={PLATE}
                    data-id={`i18n-locales-none-${pairCode(row)}`}
                  >
                    <i className="bi bi-dash-circle me-1" aria-hidden="true" />
                    No locale
                  </span>
                ) : (
                  <span
                    className={
                      row.enabled === false ? 'text-body-secondary' : undefined
                    }
                    data-id={`i18n-locales-code-${pairCode(row)}`}
                  >
                    {row.localeCode}
                  </span>
                ),
              [HilosI18nLocaleRowKey.enabled]: (row) =>
                row.localeCode !== null ? (
                  <input
                    className="form-check-input"
                    type="checkbox"
                    disabled
                    checked={row.enabled === true}
                    aria-label="Enabled"
                    data-id={`i18n-locales-enabled-${pairCode(row)}`}
                  />
                ) : null,
              actions: (row) => {
                const mode = hilosI18nLocaleWindowMode(row),
                  label = HILOS_I18N_LOCALE_COPY.open[mode]
                return (
                  <div className="hilos-button-row">
                    {mode === 'add' ? (
                      <button
                        type="button"
                        className="btn btn-sm btn-primary"
                        title={label}
                        aria-label={label}
                        data-id={`i18n-locales-add-${pairCode(row)}`}
                        onClick={() => openWindow(row)}
                      >
                        <i className="bi bi-plus-lg" aria-hidden="true" />
                      </button>
                    ) : mode === 'edit' ? (
                      <button
                        type="button"
                        className="btn btn-sm btn-outline-primary"
                        title={label}
                        aria-label={label}
                        data-id={`i18n-locales-edit-${pairCode(row)}`}
                        onClick={() => openWindow(row)}
                      >
                        <i className="bi bi-pencil" aria-hidden="true" />
                      </button>
                    ) : (
                      <button
                        type="button"
                        className="btn btn-sm btn-outline-secondary"
                        title={label}
                        aria-label={label}
                        data-id={`i18n-locales-view-${pairCode(row)}`}
                        onClick={() => openWindow(row)}
                      >
                        <i className="bi bi-eye" aria-hidden="true" />
                      </button>
                    )}
                    {row.localeCode !== null && (
                      <button
                        type="button"
                        className="btn btn-sm btn-outline-danger"
                        title={`Delete locale ${row.localeCode}`}
                        aria-label={`Delete locale ${row.localeCode}`}
                        data-id={`i18n-locales-delete-${pairCode(row)}`}
                        onClick={() => openDelete(row)}
                      >
                        <i className="bi bi-trash" aria-hidden="true" />
                      </button>
                    )}
                  </div>
                )
              },
            }}
          />
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
        <p
          className="alert alert-secondary"
          role="status"
          data-id="language-card-unavailable"
        >
          Language details are no longer available.
        </p>
      )}
      <HilosI18nLocaleWindow
        context={context}
        controller={locales.controller}
        card={card}
        templates={templates}
        opened={opened}
        onClose={closeWindow}
      />
      <HilosI18nLocaleDelete
        open={deleteTarget !== null}
        onClose={closeDelete}
        target={deleteTarget}
        source={deleteSource}
        deleteLocale={deleteLocale}
      />
    </HilosAdminPage>
  )
}
