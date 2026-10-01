// HilosTermsPage — the tier-2 public /terms page (HIL-501): the text of the
// Terms revision in force, read from the project's legal catalog, with the
// project's own prose as an optional introduction (the children) above it.
//
// Top to bottom: the heading, the reader's line, the introduction, the text and
// the revision history. The reader's line says where the person stands and,
// while a decision is due, carries the deadline plate, "Accept the new revision"
// and "What changed". Only the Terms are accepted here; the line turns green on
// the server's answer, never ahead of it. The history is everybody's, a guest's
// included: every revision opens through the page's own read.
//
// Nothing here touches the browser when it renders: the store starts in an
// effect, so the prerendered file carries the heading, the introduction and the
// loading line under it. Bootstrap classes only, no CSS of its own
// (styling-rules.md).
import { useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import {
  createHilosLegalRevisionReader,
  createHilosLegalTermsStore,
  describeHilosLegalRevision,
  describeHilosLegalTermsReader,
  formatHilosLegalDate,
  hilosLegalReconsentPerson,
  LEGAL_RECONSENT_COPY,
  LEGAL_TERMS_COPY,
  sessionAccountStanding,
  TERMS_REVISION_TEXT_ACTION,
  type HilosLegalContext,
} from '@hilos/core'
import { HilosFormError } from '../HilosFormError.js'
import { HilosModal } from '../HilosModal.js'
import { HilosStaticPage } from '../HilosStaticPage.js'
import { LoadingButton } from '../LoadingButton.js'
import { HilosLegalChanges } from '../legal/HilosLegalChanges.js'
import { HilosLegalRevisionText } from '../legal/HilosLegalRevisionText.js'
import { useSignal } from '../useSignal.js'

const COPY = LEGAL_TERMS_COPY

export interface HilosTermsPageProps {
  /** The connection, scopes and action lifecycle the page reads and accepts through. */
  context: HilosLegalContext
  /** The project's introduction, drawn above the text. */
  children?: ReactNode
}

/** The public Terms page: the reader's standing, the text in force and the history. */
export function HilosTermsPage({ context, children }: HilosTermsPageProps) {
  const store = useMemo(() => createHilosLegalTermsStore(context), [context])
  const reader = useMemo(
    () =>
      createHilosLegalRevisionReader(context, {
        textAction: TERMS_REVISION_TEXT_ACTION,
      }),
    [context],
  )
  const standingSignal = useMemo(
    () => sessionAccountStanding(context.scopes),
    [context],
  )
  useEffect(() => {
    const stop = store.start()
    return () => {
      stop()
      reader.close()
    }
  }, [store, reader])
  const terms = useSignal(store.terms)
  const agreement = useSignal(store.agreement)
  const accepting = useSignal(store.accepting)
  const refusal = useSignal(store.refusal)
  const person = useSignal(hilosLegalReconsentPerson)
  const standing = useSignal(standingSignal)
  const dialog = useSignal(reader.dialog)
  const [comparing, setComparing] = useState(false)

  const line = describeHilosLegalTermsReader(terms, agreement, {
    person,
    frozen: standing?.frozen === true,
    now: Date.now(),
  })
  const green = line.state === 'covered' || line.state === 'reworded'
  const accepted = new Set(
    person === null
      ? []
      : (agreement?.accepted.map((item) => item.revisionId) ?? []),
  )
  const changes = comparing && line.canCompare ? (terms?.changes ?? null) : null
  // A comparison that has nothing left to show - accepted, or the revision moved - is closed, not parked.
  useEffect(() => {
    if (changes === null) setComparing(false)
  }, [changes])

  /**
   * The publication day of a revision the page lists, or its id when it lists none.
   *
   * @param revisionId The revision.
   */
  const publishedOf = (revisionId: string): string => {
    const revision = terms?.revisions.find(
      (item) => item.revisionId === revisionId,
    )

    return revision ? formatHilosLegalDate(revision.publishedOn) : revisionId
  }
  const onAccept = async (): Promise<void> => {
    if (await store.accept()) setComparing(false)
  }
  const hidden = line.state === 'loading' && terms === undefined
  const compareButton = line.canCompare && (
    <button
      type="button"
      className="btn btn-sm btn-outline-secondary"
      data-id="terms-changes-open"
      onClick={() => setComparing(true)}
    >
      {COPY.whatChanged}
    </button>
  )

  return (
    <HilosStaticPage title="Terms">
      {line.state !== 'unpublished' && (
        <div className="mb-4" data-id="terms-reader" data-state={line.state}>
          {line.state === 'due' ? (
            <div className="alert alert-warning mb-0">
              <p className="mb-2" data-id="terms-reader-line">
                <i
                  className="bi bi-exclamation-triangle-fill me-1"
                  aria-hidden="true"
                />
                {line.line}
              </p>
              {line.plate !== null && (
                <div
                  className={`d-flex flex-wrap align-items-center gap-2 rounded px-3 py-2 mb-2 small bg-${line.plate.tone}-subtle text-${line.plate.tone}-emphasis`}
                  data-id="terms-reader-plate"
                  data-tone={line.plate.tone}
                >
                  <i className={`bi ${line.plate.icon}`} aria-hidden="true" />
                  <strong>{line.plate.text}</strong>
                  {line.plate.detail !== null && (
                    <span>{line.plate.detail}</span>
                  )}
                </div>
              )}
              {line.onlyPerson !== null && (
                <p className="small mb-2" data-id="terms-reader-impersonated">
                  {line.onlyPerson}
                </p>
              )}
              <div className="d-flex flex-wrap gap-2">
                {line.canAccept && (
                  <LoadingButton
                    className="btn-sm btn-primary"
                    data-id="terms-accept"
                    loading={accepting}
                    onClick={() => void onAccept()}
                  >
                    {COPY.accept}
                  </LoadingButton>
                )}
                {compareButton}
              </div>
              {line.canAccept && (
                <HilosFormError
                  message={comparing ? null : refusal}
                  dataId="terms-accept-refusal"
                  announce
                />
              )}
            </div>
          ) : (
            <div className="d-flex flex-wrap align-items-center gap-2">
              <p
                className={`mb-0${hidden ? ' invisible' : ''}`}
                data-id="terms-reader-line"
                aria-hidden={hidden}
              >
                {green && (
                  <i
                    className="bi bi-check-circle-fill text-success me-1"
                    aria-hidden="true"
                  />
                )}
                {line.line}
              </p>
              {compareButton}
            </div>
          )}
        </div>
      )}

      {children}

      {terms === undefined ? (
        <p className="text-body-secondary" data-id="terms-loading">
          {COPY.loading}
        </p>
      ) : terms === null ? (
        <p className="text-body-secondary" data-id="terms-none">
          {COPY.nonePublished}
        </p>
      ) : (
        <div data-id="terms-text">
          <HilosLegalRevisionText clauses={terms.clauses} />
        </div>
      )}

      {terms && (
        <>
          <h2
            className="h6 text-uppercase text-body-secondary mb-2 mt-4"
            data-id="terms-history"
          >
            {COPY.history}
          </h2>
          {terms.revisions
            .slice()
            .reverse()
            .map((revision) => (
              <div
                key={revision.revisionId}
                className="d-flex flex-wrap align-items-center gap-3 py-3 border-bottom"
                data-id="terms-history-revision"
                data-revision={revision.revisionId}
              >
                <i
                  className="bi bi-file-earmark-text fs-5 text-body-secondary"
                  aria-hidden="true"
                />
                <div className="flex-grow-1 text-break">
                  <div className="fw-semibold small">
                    {formatHilosLegalDate(revision.publishedOn)}
                    {revision.revisionId === terms.current.revisionId && (
                      <span
                        className="badge text-bg-primary ms-1"
                        data-id="terms-history-current"
                      >
                        {COPY.inForce}
                      </span>
                    )}
                    {accepted.has(revision.revisionId) && (
                      <span
                        className="badge text-bg-success ms-1"
                        data-id="terms-history-accepted"
                      >
                        {COPY.youAccepted}
                      </span>
                    )}
                  </div>
                  <div className="small text-body-secondary">
                    {describeHilosLegalRevision(revision)}
                  </div>
                </div>
                <button
                  type="button"
                  className="btn btn-sm btn-outline-secondary"
                  data-id="terms-history-open"
                  onClick={() => void reader.open('terms', revision.revisionId)}
                >
                  {COPY.open}
                </button>
              </div>
            ))}
        </>
      )}

      <HilosModal
        open={dialog !== null}
        title={
          dialog === null
            ? ''
            : COPY.revisionTitle.replace(
                '{date}',
                publishedOf(dialog.revisionId),
              )
        }
        initialFocus="dialog"
        size="wide"
        onClose={reader.close}
        actions={({ requestClose }) => (
          <button
            type="button"
            className="btn btn-secondary"
            data-id="terms-revision-close"
            onClick={requestClose}
          >
            {LEGAL_RECONSENT_COPY.close}
          </button>
        )}
      >
        {dialog && (
          <div data-id="terms-revision-modal">
            <div className="visually-hidden" role="status" aria-live="polite">
              {dialog.busy ? COPY.revisionLoading : ''}
            </div>
            <p
              className={`small text-body-secondary${dialog.busy ? '' : ' invisible'}`}
              data-id="legal-dialog-loading"
              aria-hidden={!dialog.busy}
            >
              {COPY.revisionLoading}
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

      <HilosModal
        open={changes !== null}
        title={
          changes === null
            ? ''
            : COPY.changesTitle
                .replace('{from}', publishedOf(changes.fromRevisionId))
                .replace('{to}', publishedOf(changes.toRevisionId))
        }
        initialFocus="dialog"
        size="wide"
        onClose={() => setComparing(false)}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              data-id="terms-changes-close"
              onClick={requestClose}
            >
              {LEGAL_RECONSENT_COPY.close}
            </button>
            {line.canAccept && (
              <LoadingButton
                className="btn-primary"
                data-id="terms-changes-accept"
                loading={accepting}
                onClick={() => void onAccept()}
              >
                {LEGAL_RECONSENT_COPY.accept}
              </LoadingButton>
            )}
          </>
        )}
      >
        {changes && (
          <div data-id="terms-changes-modal">
            <HilosLegalChanges
              changes={changes.changes}
              fromLabel={publishedOf(changes.fromRevisionId)}
              toLabel={publishedOf(changes.toRevisionId)}
            />
            {line.canAccept && (
              <div className="mt-3">
                <HilosFormError
                  message={refusal}
                  dataId="terms-changes-refusal"
                  announce
                />
              </div>
            )}
          </div>
        )}
      </HilosModal>
    </HilosStaticPage>
  )
}
