import { useContext, useMemo, useState } from 'react'
import {
  createHilosI18nLanguageCard,
  createHilosI18nLanguageCardWindow,
  HILOS_I18N_LANGUAGE_WINDOW_COPY,
  HILOS_I18N_LANGUAGE_DELETE_ACTION,
  HILOS_I18N_LANGUAGE_DELETE_COPY,
  hilosI18nLanguageDelete,
  HilosPages,
  type HilosI18nLanguageContext,
} from '@hilos/core'
import { HilosAdminPage } from '../../../HilosAdminPage.js'
import { HilosRouterContext } from '../../../hilosRouterContext.js'
import { useSignal } from '../../../useSignal.js'
import { HilosI18nLanguageDelete } from './HilosI18nLanguageDelete.js'
import { HilosI18nLanguageHeader } from './HilosI18nLanguageHeader.js'
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
    <HilosAdminPage page={HilosPages.I18N_LANGUAGE}>
      <LanguageCard
        key={`${binding.revision}:${route.params.languageCode}`}
        context={context}
      />
    </HilosAdminPage>
  )
}

/** The windows belong to this mounted route subject and its page data. */
function LanguageCard({ context }: HilosI18nLanguagePageProps) {
  const router = useContext(HilosRouterContext)!
  const cardSignal = useMemo(
    () => createHilosI18nLanguageCard(context),
    [context],
  )
  const card = useSignal(cardSignal)
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
          code: card.code,
          nativeName: card.nativeName,
          canDelete: card.summary.canDelete,
          deleteReason: card.summary.deleteReason,
        }
  const loading = useSignal(router.pageLoading)
  return (
    <>
      {card ? (
        <>
          <HilosI18nLanguageHeader
            card={card}
            activePage={HilosPages.I18N_LANGUAGE}
          />
          <section className="mb-4" data-id="language-card-main">
            <div className="d-flex align-items-center gap-2 mb-2">
              <h2 className="h5 mb-0 flex-grow-1">Main</h2>
              {card.enabled && (
                <p
                  className="badge text-bg-warning mb-0"
                  data-id="language-card-frozen"
                >
                  Language enabled — frozen
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
              <dd
                className="col-sm-8 text-uppercase"
                data-id="language-card-code"
              >
                {card.code}
              </dd>
              <dt className="col-sm-4">Native name</dt>
              <dd className="col-sm-8" data-id="language-card-name">
                {card.nativeName}
              </dd>
              <dt className="col-sm-4">Writing direction</dt>
              <dd className="col-sm-8" data-id="language-card-direction">
                {card.rtl ? 'Right to left' : 'Left to right'}
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
                    {card.enabled ? 'Enabled' : 'Disabled'}
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
                    aria-describedby={
                      verdict.reason ? 'language-card-delete-reason' : undefined
                    }
                    data-id="language-card-delete"
                    onClick={() => setDeleteOpen(true)}
                  >
                    {deleteCopy.open}
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
    </>
  )
}
