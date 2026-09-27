import { useEffect, useMemo } from 'react'
import {
  createHilosLegalAgreementsStore,
  createHilosLegalRevisionReader,
  describeHilosLegalRevision,
  formatHilosLegalAcceptanceDate,
  formatHilosLegalDate,
  hilosLegalDocumentLabel,
  hilosLegalRevisionHistory,
  type HilosLegalContext,
} from '@hilos/core'
import { HilosFormError } from '../HilosFormError.js'
import { HilosModal } from '../HilosModal.js'
import { HilosPageHeading } from '../HilosPageHeading.js'
import { HilosLegalChanges } from '../legal/HilosLegalChanges.js'
import { HilosLegalRevisionText } from '../legal/HilosLegalRevisionText.js'
import { useSignal } from '../useSignal.js'

export interface HilosProfileAgreementsHistoryPageProps {
  context: HilosLegalContext
}

/** All declared revisions; only an opened dialog asks for text or comparison. */
export function HilosProfileAgreementsHistoryPage({
  context,
}: HilosProfileAgreementsHistoryPageProps) {
  const store = useMemo(
    () => createHilosLegalAgreementsStore(context),
    [context],
  )
  const reader = useMemo(
    () => createHilosLegalRevisionReader(context),
    [context],
  )
  const historySignal = useMemo(
    () => hilosLegalRevisionHistory(context),
    [context],
  )
  useEffect(() => {
    const stop = store.start()
    return () => {
      stop()
      reader.close()
    }
  }, [store, reader])
  const state = useSignal(store.state)
  const history = useSignal(historySignal)
  const dialog = useSignal(reader.dialog)
  const title = dialog
    ? `${hilosLegalDocumentLabel(dialog.document)} — ${dialog.kind === 'text' ? 'revision text' : 'what changed'}`
    : ''
  return (
    <div data-id="profile-agreements-history-view">
      <HilosPageHeading />
      {state ? (
        <>
          {history.length === 0 && (
            <p className="text-body-secondary">
              This project publishes no legal documents
            </p>
          )}
          {history.map((document) => (
            <section
              key={document.document}
              className="mb-4"
              data-id="legal-history-document"
              data-document={document.document}
            >
              <h2 className="h6 text-uppercase text-body-secondary mb-2">
                {hilosLegalDocumentLabel(document.document)}
              </h2>
              {document.revisions
                .slice()
                .reverse()
                .map((revision) => {
                  const agreement = state.documents.find(
                    (item) => item.document === document.document,
                  )
                  const accepted = agreement?.accepted.find(
                    (item) => item.revisionId === revision.revisionId,
                  )
                  return (
                    <div
                      key={revision.revisionId}
                      className="d-flex flex-wrap align-items-center gap-3 py-3 border-bottom"
                      data-id="legal-history-revision"
                      data-revision={revision.revisionId}
                    >
                      <i
                        className="bi bi-file-earmark-text fs-5 text-body-secondary"
                        aria-hidden="true"
                      />
                      <div className="flex-grow-1 text-break">
                        <div className="fw-semibold small">
                          {formatHilosLegalDate(revision.publishedOn)}
                          {agreement?.current.revisionId ===
                            revision.revisionId && (
                            <span
                              className="badge text-bg-primary ms-1"
                              data-id="legal-history-current"
                            >
                              current
                            </span>
                          )}
                          {accepted && (
                            <span
                              className="badge text-bg-success ms-1"
                              data-id="legal-history-accepted"
                            >
                              you accepted ·{' '}
                              {formatHilosLegalAcceptanceDate(
                                accepted.acceptedAt,
                              )}
                            </span>
                          )}
                        </div>
                        <div className="small text-body-secondary">
                          {describeHilosLegalRevision(revision)}
                        </div>
                      </div>
                      <div className="d-flex gap-2">
                        <button
                          type="button"
                          className="btn btn-sm btn-outline-secondary"
                          data-id="legal-history-open"
                          onClick={() =>
                            void reader.open(
                              document.document,
                              revision.revisionId,
                            )
                          }
                        >
                          Open
                        </button>
                        {revision.origin !== 'first' && (
                          <button
                            type="button"
                            className="btn btn-sm btn-outline-secondary"
                            data-id="legal-history-compare"
                            onClick={() =>
                              void reader.compare(
                                document.document,
                                revision.revisionId,
                              )
                            }
                          >
                            Compare
                          </button>
                        )}
                      </div>
                    </div>
                  )
                })}
            </section>
          ))}
        </>
      ) : (
        <p className="text-body-secondary">Loading revision history…</p>
      )}
      <HilosModal
        open={dialog !== null}
        title={title}
        initialFocus="dialog"
        size="wide"
        onClose={reader.close}
        actions={({ requestClose }) => (
          <button
            type="button"
            className="btn btn-secondary"
            data-id="legal-history-close"
            onClick={requestClose}
          >
            Close
          </button>
        )}
      >
        {dialog && (
          <div
            data-id={
              dialog.kind === 'text'
                ? 'legal-revision-text-modal'
                : 'legal-changes-modal'
            }
          >
            <div className="visually-hidden" role="status" aria-live="polite">
              {dialog.busy ? 'Loading revision…' : ''}
            </div>
            <p
              className={`small text-body-secondary${dialog.busy ? '' : ' invisible'}`}
              data-id="legal-dialog-loading"
              aria-hidden={!dialog.busy}
            >
              Loading revision…
            </p>
            <HilosFormError
              message={dialog.refusal}
              dataId="legal-dialog-refusal"
              announce
            />
            {dialog.text && (
              <HilosLegalRevisionText clauses={dialog.text.clauses} />
            )}
            {dialog.changes && (
              <HilosLegalChanges
                changes={dialog.changes.changes}
                fromLabel={formatHilosLegalDate(dialog.changes.fromRevisionId)}
                toLabel={formatHilosLegalDate(dialog.changes.toRevisionId)}
              />
            )}
          </div>
        )}
      </HilosModal>
    </div>
  )
}
