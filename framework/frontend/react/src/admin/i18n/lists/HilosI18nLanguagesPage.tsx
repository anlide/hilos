import { useContext, useEffect, useMemo, useState } from 'react'
import {
  createHilosI18nBuiltInCatalog,
  createHilosI18nBuiltInLanguages,
  createHilosI18nLanguageAdd,
  createHilosI18nLanguageRowWindow,
  createHilosI18nLanguagesTable,
  HILOS_I18N_LANGUAGE_ROW_DELETE_ACTION,
  hilosI18nLanguageDelete,
  HILOS_I18N_LANGUAGE_MARKS,
  HILOS_I18N_LANGUAGES_LEGEND,
  HILOS_TABLE_ACTIONS_KEY,
  HilosI18nLanguageRowKey,
  HilosPages,
  type HilosI18nLanguageContext,
} from '@hilos/core'
import { HilosAdminPage } from '../../../HilosAdminPage.js'
import { HilosLink } from '../../../HilosLink.js'
import { HilosViewportTable } from '../../../HilosViewportTable.js'
import { HilosRouterContext } from '../../../hilosRouterContext.js'
import { useSignal } from '../../../useSignal.js'
import { HilosI18nLanguageDelete } from '../details/HilosI18nLanguageDelete.js'
import { HilosI18nLanguageWindow } from '../details/HilosI18nLanguageWindow.js'
import { HilosI18nCatalogNote } from './HilosI18nCatalogNote.js'
import { HilosI18nLanguageAdd } from './HilosI18nLanguageAdd.js'
import { HilosI18nLanguageRowActions } from './HilosI18nLanguageRowActions.js'
import { HilosI18nMarksLegend } from './HilosI18nMarksLegend.js'

/** The project binds the language list to its connection, scopes and actions. */
export interface HilosI18nLanguagesPageProps {
  context: HilosI18nLanguageContext
}

/** The language list over the shared viewport table, catalog and focused row windows. */
export function HilosI18nLanguagesPage({
  context,
}: HilosI18nLanguagesPageProps) {
  const [binding, setBinding] = useState({ context, revision: 0 })
  if (binding.context !== context)
    setBinding({ context, revision: binding.revision + 1 })
  return (
    <HilosAdminPage page={HilosPages.I18N_LANGUAGES}>
      <LanguagesList key={binding.revision} context={context} />
    </HilosAdminPage>
  )
}

/** A context replacement unmounts the old tracked requests along with their windows. */
function LanguagesList({ context }: HilosI18nLanguagesPageProps) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error('HilosI18nLanguagesPage requires a provided Hilos router.')
  const catalogSignal = useMemo(
    () => createHilosI18nBuiltInCatalog(context),
    [context],
  )
  const catalog = useMemo(
    () => createHilosI18nBuiltInLanguages(context),
    [context],
  )
  const builtInCatalog = useSignal(catalogSignal),
    builtInLanguages = useSignal(catalog)
  const add = useMemo(
    () => createHilosI18nLanguageAdd(context, catalog),
    [context, catalog],
  )
  const languages = useMemo(
    () => createHilosI18nLanguagesTable(context, { openAdd: add.open }),
    [context, add],
  )
  const languageWindow = useMemo(
    () => createHilosI18nLanguageRowWindow(languages.controller, context),
    [languages, context],
  )
  const [deleteCode, setDeleteCode] = useState<string | null>(null)
  const focused = useSignal(languages.controller.focusedRow)
  const deleteSource = focused?.code === deleteCode ? focused : null
  useEffect(() => {
    languages.start()
    return () => {
      languages.controller.releaseFocus()
      languages.dispose()
      add.dispose()
    }
  }, [languages, add])
  function closeDelete(): void {
    setDeleteCode(null)
    languages.controller.releaseFocus()
  }
  function openDelete(code: string): void {
    const fresh = languages.controller.focusRow(code)
    if (
      !fresh ||
      fresh.code !== code ||
      hilosI18nLanguageDelete(fresh).disabled
    ) {
      languages.controller.releaseFocus()
      return
    }
    setDeleteCode(fresh.code)
  }
  return (
    <>
      {builtInCatalog && (
        <HilosI18nCatalogNote
          catalog={builtInCatalog}
          dataId="i18n-languages-catalog"
        >
          The framework's built-in catalog holds{' '}
          {builtInLanguages?.length ?? builtInCatalog.languageCount} ISO 639-1
          codes with their own names and writing direction. A language from it
          is added by its code alone — the rest is filled in — and is refreshed
          whenever a framework update changes the catalog, but only while that
          language is switched off. A code that is not in the catalog can still
          be added, but keeping that language up is on you.
        </HilosI18nCatalogNote>
      )}
      <HilosViewportTable
        controller={languages.controller}
        cells={{
          [HilosI18nLanguageRowKey.code]: (row) => {
            const path = router.resolvePath(HilosPages.I18N_LANGUAGE, {
              languageCode: row.code,
            })
            return path ? (
              <HilosLink
                to={path}
                className={`text-uppercase${row.enabled ? '' : ' link-secondary'}`}
                data-id={`i18n-languages-link-${row.code}`}
              >
                {row.code}
              </HilosLink>
            ) : (
              <span
                className={`text-uppercase${row.enabled ? '' : ' text-body-secondary'}`}
                data-id={`i18n-languages-link-${row.code}`}
              >
                {row.code}
              </span>
            )
          },
          [HilosI18nLanguageRowKey.nativeName]: (row) => (
            <>
              <span className={row.enabled ? undefined : 'text-body-secondary'}>
                {row.nativeName}
              </span>
              {row.isDefault && (
                <span
                  className={`badge ms-1 ${HILOS_I18N_LANGUAGE_MARKS.default.tone}`}
                  title={HILOS_I18N_LANGUAGE_MARKS.default.label}
                  data-id={`i18n-languages-default-${row.code}`}
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
              {row.isOwn && (
                <span
                  className={`badge ms-1 ${HILOS_I18N_LANGUAGE_MARKS.own.tone}`}
                  title={HILOS_I18N_LANGUAGE_MARKS.own.label}
                  data-id={`i18n-languages-own-${row.code}`}
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
          ),
          [HilosI18nLanguageRowKey.rtl]: (row) => (
            <input
              className="form-check-input"
              type="checkbox"
              disabled
              checked={row.rtl}
              aria-label="Right to left"
              data-id={`i18n-languages-rtl-${row.code}`}
            />
          ),
          [HilosI18nLanguageRowKey.enabled]: (row) => (
            <input
              className="form-check-input"
              type="checkbox"
              disabled
              checked={row.enabled}
              aria-label="Enabled"
              data-id={`i18n-languages-enabled-${row.code}`}
            />
          ),
          [HILOS_TABLE_ACTIONS_KEY]: (row) => (
            <HilosI18nLanguageRowActions
              row={row}
              onEdit={() => languageWindow.open(row.code)}
              onDelete={() => openDelete(row.code)}
            />
          ),
        }}
      />
      <HilosI18nMarksLegend
        rows={HILOS_I18N_LANGUAGES_LEGEND}
        dataId="i18n-languages-legend"
        className="mt-4"
      />
      <HilosI18nLanguageAdd add={add} context={context} />
      <HilosI18nLanguageWindow window={languageWindow} />
      <HilosI18nLanguageDelete
        open={deleteCode !== null}
        onClose={closeDelete}
        context={context}
        source={deleteSource}
        actionName={HILOS_I18N_LANGUAGE_ROW_DELETE_ACTION}
      />
    </>
  )
}
