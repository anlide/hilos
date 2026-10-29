import { useEffect, useState } from 'react'
import {
  createHilosLegalHistory,
  LEGAL_HISTORY_PUBLISHED_ON_FIELD,
  LEGAL_HISTORY_ORIGIN_FIELD,
  LEGAL_RECONSENT_COPY,
  type HilosLegalContext,
  type HilosLegalDocumentKey,
  type HilosLegalHistory,
  type HilosLegalHistoryRow,
  type HilosStaticPageNavigation,
} from '@hilos/core/base'
import { HilosFormError } from '../HilosFormError.js'
import { HilosModal } from '../HilosModal.js'
import { HilosSkeleton } from '../HilosSkeleton.js'
import { HilosStaticPage } from '../HilosStaticPage.js'
import { HilosViewportTable } from '../HilosViewportTable.js'
import { HilosLegalRevisionText } from '../legal/HilosLegalRevisionText.js'
import { useSignal } from '../useSignal.js'

/** Inputs of the public, read-only revision history. */
export interface HilosLegalHistoryPageProps {
  context: HilosLegalContext
  document: HilosLegalDocumentKey
  navigation?: HilosStaticPageNavigation
}

/** The public document's own table and lazy revision reader. */
export function HilosLegalHistoryPage({
  context,
  document,
  navigation,
}: HilosLegalHistoryPageProps) {
  const [history, setHistory] = useState<HilosLegalHistory | null>(null)
  useEffect(() => {
    // Each effect setup gets its own instance: cleanup is terminal, including
    // the development StrictMode setup/cleanup/setup cycle. SSR starts none.
    const current = createHilosLegalHistory(context, document)
    setHistory(current)
    return current.start()
  }, [context, document])
  const terms = document === 'terms'
  return (
    <HilosStaticPage
      title={terms ? 'Terms' : 'Privacy'}
      icon={terms ? 'bi-file-earmark-text' : 'bi-shield-lock'}
      subtitle={
        terms
          ? 'The terms of using this project: the revision in force and every one before it.'
          : 'What this project keeps about you: the revision in force, every one before it, and wiping this browser.'
      }
      navigation={navigation}
    >
      {history === null ? (
        <HilosSkeleton />
      ) : (
        <HistoryBody history={history} document={document} />
      )}
    </HilosStaticPage>
  )
}

/** Only the mount's dialog title is local; row labels and marks belong to core. */
function HistoryBody({
  history,
  document,
}: {
  history: HilosLegalHistory
  document: HilosLegalDocumentKey
}) {
  const dialog = useSignal(history.reader.dialog)
  const [title, setTitle] = useState('')
  const copy = history.copy
  const open = (row: HilosLegalHistoryRow): void => {
    setTitle(row.revisionTitle)
    void history.reader.open(row.revisionId)
  }
  return (
    <>
      <div data-id="public-legal-history" data-document={document}>
        <HilosViewportTable
          controller={history.controller}
          cells={{
            [LEGAL_HISTORY_PUBLISHED_ON_FIELD]: (row) => (
              <>
                <span className="fw-semibold">{row.publishedLabel}</span>
                {row.inForce && (
                  <span
                    className="badge text-bg-primary ms-1"
                    data-id="public-legal-history-current"
                    data-revision={row.revisionId}
                  >
                    {copy.inForce}
                  </span>
                )}
                {row.accepted && (
                  <span
                    className="badge text-bg-success ms-1"
                    data-id="public-legal-history-accepted"
                    data-revision={row.revisionId}
                  >
                    {copy.youAccepted}
                  </span>
                )}
              </>
            ),
            [LEGAL_HISTORY_ORIGIN_FIELD]: (row) => (
              <span className="text-body-secondary">{row.changeLabel}</span>
            ),
            actions: (row) => (
              <button
                type="button"
                className="btn btn-sm btn-outline-secondary"
                data-id="public-legal-history-open"
                data-hilos-focus-return="public-legal-history-open"
                data-document={document}
                data-revision={row.revisionId}
                onClick={() => open(row)}
              >
                {copy.open}
              </button>
            ),
          }}
        />
      </div>
      <HilosModal
        open={dialog !== null}
        title={title}
        initialFocus="dialog"
        size="wide"
        onClose={history.reader.close}
        actions={({ requestClose }) => (
          <button
            type="button"
            className="btn btn-secondary"
            data-id="public-legal-history-close"
            onClick={requestClose}
          >
            {LEGAL_RECONSENT_COPY.close}
          </button>
        )}
      >
        {dialog && (
          <div
            data-id="public-legal-history-modal"
            data-document={document}
            data-revision={dialog.revisionId}
          >
            <div className="visually-hidden" role="status" aria-live="polite">
              {dialog.busy ? copy.revisionLoading : ''}
            </div>
            <p
              className={`small text-body-secondary${dialog.busy ? '' : ' invisible'}`}
              data-id="legal-dialog-loading"
              aria-hidden={!dialog.busy}
            >
              {copy.revisionLoading}
            </p>
            <HilosFormError
              message={dialog.refusal}
              dataId="legal-dialog-refusal"
              announce
            />
            {dialog.text && (
              <HilosLegalRevisionText clauses={dialog.text.clauses} />
            )}
          </div>
        )}
      </HilosModal>
    </>
  )
}
