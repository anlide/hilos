import { useContext, useEffect, useMemo, useRef, useState } from 'react'
import { computedSignal, HilosPages } from '@hilos/core/base'
import {
  createHilosI18nLanguageCard,
  createHilosI18nLanguageNameWindow,
  createHilosI18nNamesTable,
  HILOS_I18N_LANGUAGE_NAMES,
  HILOS_I18N_LANGUAGE_NAME_COPY,
  HILOS_I18N_LANGUAGE_NAME_DELETE_COPY,
  type HilosI18nLanguageContext,
  type HilosI18nLanguageNameDeleteTarget,
  type HilosI18nNameRow,
} from '@hilos/core/admin/i18n'
import { HilosAdminPage } from '../../../HilosAdminPage.js'
import { LoadingButton } from '../../../LoadingButton.js'
import { HilosRouterContext } from '../../../hilosRouterContext.js'
import { useSignal } from '../../../useSignal.js'
import { HilosI18nLanguageHeader } from './HilosI18nLanguageHeader.js'
import { HilosI18nLanguageNameDelete } from './HilosI18nLanguageNameDelete.js'
import { HilosI18nLanguageNameWindow } from './HilosI18nLanguageNameWindow.js'
import { HilosI18nNamesTable } from './HilosI18nNamesTable.js'

/** The project supplies the page's existing language context. */
export interface HilosI18nLanguageNamesPageProps {
  context: HilosI18nLanguageContext
}

/** Names and corrections of the code-addressed language, over the shared core. */
export function HilosI18nLanguageNamesPage({
  context,
}: HilosI18nLanguageNamesPageProps) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error(
      'HilosI18nLanguageNamesPage requires a provided Hilos router.',
    )
  const route = useSignal(router.currentRoute)
  const [binding, setBinding] = useState({ context, revision: 0 })
  if (binding.context !== context)
    setBinding({ context, revision: binding.revision + 1 })
  const code = (route.params.languageCode as string | undefined) ?? ''
  return (
    <HilosAdminPage page={HilosPages.I18N_LANGUAGE_NAMES}>
      <LanguageNames
        key={`${binding.revision}:${code}`}
        context={context}
        code={code}
      />
    </HilosAdminPage>
  )
}

function LanguageNames({
  context,
  code,
}: HilosI18nLanguageNamesPageProps & { code: string }) {
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
  const card = useSignal(liveCard),
    loading = useSignal(router.pageLoading)
  const names = useMemo(
    () =>
      createHilosI18nNamesTable(
        context,
        HILOS_I18N_LANGUAGE_NAMES,
        computedSignal(() =>
          liveCard.get()?.code === language.get()
            ? (liveCard.get()?.languageId ?? null)
            : null,
        ),
      ),
    [context, language, liveCard],
  )
  const nameWindow = useMemo(
    () =>
      createHilosI18nLanguageNameWindow(
        names.controller,
        liveCard,
        language,
        context,
      ),
    [names, liveCard, language, context],
  )
  const focused = useSignal(names.controller.focusedRow)
  const [target, setTarget] =
    useState<HilosI18nLanguageNameDeleteTarget | null>(null)
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
    card?.languageId === target?.languageId &&
    focused?.inLanguageId === target?.inLanguageId
      ? (focused ?? null)
      : null
  const nameCopy = HILOS_I18N_LANGUAGE_NAME_COPY,
    copy = HILOS_I18N_LANGUAGE_NAME_DELETE_COPY
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
      languageId: subject.languageId,
      inLanguageId: fresh.inLanguageId,
      nameId,
      languageCode: code,
      languageNativeName: subject.nativeName,
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
    <>
      {card ? (
        <>
          <HilosI18nLanguageHeader
            card={card}
            activePage={HilosPages.I18N_LANGUAGE_NAMES}
          />
          <HilosI18nNamesTable
            controller={names.controller}
            nameActions={(row) => (
              <>
                <button
                  type="button"
                  className="btn btn-sm btn-outline-secondary"
                  aria-label={nameCopy.open(
                    row.name === null,
                    card.nativeName,
                    row.nativeName,
                  )}
                  data-id={`i18n-language-name-${row.name === null ? 'add' : 'edit'}-${row.code}`}
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
                      card.nativeName,
                      row.nativeName,
                      correction.localeCode,
                    )}
                    data-id={`i18n-language-name-locale-${correction.name === null ? 'add' : 'edit'}-${correction.localeCode}`}
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
      <HilosI18nLanguageNameDelete
        open={target !== null}
        onClose={closeDelete}
        context={context}
        target={target}
        source={source}
      />
      <HilosI18nLanguageNameWindow window={nameWindow} />
    </>
  )
}
