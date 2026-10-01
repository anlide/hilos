import { useContext, useEffect, useMemo, useState } from 'react'
import {
  computedSignal,
  createHilosLegalRevisionsTable,
  createHilosLegalConsentPreview,
  createHilosLegalReconsentPreview,
  hilosLegalDocumentLabel,
  LEGAL_TERMS_UNPUBLISHED_MESSAGE,
  HilosPages,
  HilosLegalRowKey,
  HILOS_TABLE_ACTIONS_KEY,
  LEGAL_CATALOG_REFUSAL_SECTION,
  LEGAL_DOCUMENT_SECTION,
  LEGAL_RECONSENT_COPY,
  legalAdminDocumentSchema,
  resolveHilosPath,
  type HilosLegalContext,
  type HilosLegalDocumentKey,
} from '@hilos/core'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosFormError } from '../../HilosFormError.js'
import { HilosLegalConsent } from '../../legal/HilosLegalConsent.js'
import { HilosLegalReconsent } from '../../legal/HilosLegalReconsent.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'

/** A document's adopted standard, deviations and revision history. */
export function HilosLegalDocumentPage({
  context,
}: {
  context: HilosLegalContext
}) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error('HilosLegalDocumentPage requires a provided Hilos router.')
  const document = useMemo(
    () =>
      computedSignal(() =>
        String(router.currentRoute.get().params.documentKey ?? ''),
      ),
    [router],
  )
  const revisions = useMemo(
    () => createHilosLegalRevisionsTable(context, document),
    [context, document],
  )
  const preview = useMemo(
    () => createHilosLegalConsentPreview(context),
    [context],
  )
  const previewOpen = useSignal(preview.opened)
  const previewTerms = useSignal(preview.terms)
  const previewLoading = useSignal(preview.loading)
  const previewError = useSignal(preview.error)
  const [accepted, setAccepted] = useState(false)
  const [reading, setReading] = useState<HilosLegalDocumentKey | null>(null)
  useEffect(() => () => preview.close(), [preview])
  function openPreview(): void {
    setAccepted(false)
    setReading(null)
    void preview.open()
  }
  const raw = useSignal(context.scopes.pageDataSignal(LEGAL_DOCUMENT_SECTION))
  const refusal = useSignal(
    context.scopes.pageDataSignal(LEGAL_CATALOG_REFUSAL_SECTION),
  )
  const parsed = legalAdminDocumentSchema.safeParse(raw)
  const details = parsed.success ? parsed.data : null
  // The "the terms have changed" screen as whoever held the previous revision
  // sees it today (HIL-500): the same component in a read-only modal.
  const reconsent = useMemo(
    () => createHilosLegalReconsentPreview(context),
    [context],
  )
  const reconsentOpen = useSignal(reconsent.opened)
  const reconsentPreview = useSignal(reconsent.preview)
  const reconsentLoading = useSignal(reconsent.loading)
  const reconsentError = useSignal(reconsent.error)
  const reconsentView = useSignal(reconsent.view)
  useEffect(() => () => reconsent.close(), [reconsent])
  function openReconsent(): void {
    if (details) void reconsent.open(details.document)
  }
  useEffect(() => {
    revisions.start()
    return () => revisions.dispose()
  }, [revisions])
  return (
    <HilosAdminPage page={HilosPages.LEGAL_DOCUMENT}>
      {typeof refusal === 'string' ? (
        <div
          className="alert alert-danger text-break"
          role="alert"
          data-id="legal-catalog-refusal"
        >
          <h2 className="h6">The legal document catalog could not be loaded</h2>
          <p className="mb-0">{refusal}</p>
        </div>
      ) : (
        details && (
          <>
            <div className="alert alert-secondary small">
              Document texts live in code. A new revision is published by a
              deployment.
            </div>
            {details.set && (
              <section data-id="legal-set" className="mb-4">
                <h2 className="h5">Hilos standard set {details.set.version}</h2>
                <p className="small text-body-secondary">
                  {details.set.publishedOn} · {details.set.significance} ·{' '}
                  {details.set.clauses.length} clauses
                </p>
                <ol className="list-group list-group-numbered">
                  {details.set.clauses.map((clause) => (
                    <li
                      key={clause.clauseKey}
                      className="list-group-item text-break"
                    >
                      <code className="small me-2">{clause.clauseKey}</code>
                      {clause.statement}
                    </li>
                  ))}
                </ol>
              </section>
            )}
            {details.newerSet && (
              <section
                className="alert alert-warning"
                data-id="legal-set-newer"
              >
                <h2 className="h6">
                  Hilos has released set {details.newerSet.version} for this
                  document
                </h2>
                <p className="small">
                  {details.newerSet.publishedOn} ·{' '}
                  {details.newerSet.significance}. The project keeps its
                  declared set until it publishes a new revision.
                </p>
                <ul className="mb-0 small">
                  {details.newerSet.changes.map((change) => (
                    <li key={change.clauseKey}>
                      {change.kind}: {change.title}{' '}
                      <code>{change.clauseKey}</code>
                    </li>
                  ))}
                </ul>
              </section>
            )}
            <section className="mb-4">
              <h2 className="h5">Different in this project</h2>
              {details.deviations.length === 0 && (
                <p className="text-body-secondary">
                  No project deviations are declared.
                </p>
              )}
              {details.deviations.map((deviation) => (
                <div
                  key={deviation.clauseKey}
                  className="border rounded p-3 mb-2 text-break"
                  data-id="legal-deviation-row"
                >
                  <h3 className="h6">
                    {deviation.statement}{' '}
                    <span className="badge text-bg-secondary">
                      {deviation.direction}
                    </span>
                  </h3>
                  <p className="small text-body-secondary">
                    Standard: {deviation.standardStatement} ·{' '}
                    <code>{deviation.clauseKey}</code>
                  </p>
                  {deviation.text.split('\n\n').map((paragraph, index) => (
                    <p key={index} className="small mb-2">
                      {paragraph}
                    </p>
                  ))}
                </div>
              ))}
            </section>
            <section className="mb-4">
              <h2 className="h5">How a person sees it</h2>
              <button
                type="button"
                className="btn btn-outline-secondary"
                data-id="legal-preview-consent"
                onClick={openPreview}
              >
                <i className="bi bi-eye me-1" aria-hidden="true" />
                Consent screen at registration
              </button>
              <button
                type="button"
                className="btn btn-outline-secondary ms-2"
                data-id="legal-preview-reconsent"
                onClick={openReconsent}
              >
                <i className="bi bi-arrow-repeat me-1" aria-hidden="true" />
                Re-consent screen
              </button>
            </section>
            <HilosViewportTable
              controller={revisions.controller}
              cells={{
                [HilosLegalRowKey.rowKey]: (row) => (
                  <div data-id="legal-revision-row" data-revision={row.rowKey}>
                    <strong>{row.rowKey}</strong>
                    {row.current && (
                      <span className="badge text-bg-primary ms-2">
                        Current
                      </span>
                    )}
                    {!row.declared && (
                      <span className="badge text-bg-warning ms-2">
                        Not in code
                      </span>
                    )}
                    {row.revision && (
                      <div className="small text-body-secondary">
                        {row.revision.publishedOn} · {row.revision.significance}{' '}
                        · Effective {row.revision.effectiveOn} · Set{' '}
                        {row.revision.setVersion}
                      </div>
                    )}
                  </div>
                ),
                [HILOS_TABLE_ACTIONS_KEY]: (row) => (
                  <HilosLink
                    to={resolveHilosPath(HilosPages.LEGAL_REVISION, {
                      documentKey: details.document,
                      revisionId: row.rowKey,
                    })}
                    className="btn btn-sm btn-outline-secondary"
                    data-id="legal-revision-open"
                    data-revision={row.rowKey}
                  >
                    Open
                  </HilosLink>
                ),
              }}
            />
          </>
        )
      )}
      <HilosModal
        open={previewOpen}
        title="Consent screen at registration"
        initialFocus="dialog"
        onClose={() => preview.close()}
        actions={() => (
          <>
            {previewError && (
              <button
                type="button"
                className="btn btn-primary"
                data-id="legal-consent-preview-retry"
                onClick={openPreview}
              >
                Try again
              </button>
            )}
            <button
              type="button"
              className="btn btn-secondary"
              data-id="legal-consent-preview-close"
              onClick={() => preview.close()}
            >
              Close
            </button>
          </>
        )}
      >
        <div data-id="legal-consent-preview">
          <div className="visually-hidden" role="status" aria-live="polite">
            {previewError ?? (previewLoading ? 'Loading terms…' : '')}
          </div>
          {previewLoading && (
            <div
              className="placeholder-glow"
              data-id="legal-consent-loading"
              aria-busy="true"
            >
              <span className="placeholder col-12" aria-hidden="true" />
              <span className="placeholder col-9" aria-hidden="true" />
            </div>
          )}
          {previewTerms &&
            (previewTerms.documents.length > 0 ? (
              <HilosLegalConsent
                terms={previewTerms}
                accepted={accepted}
                reading={reading}
                onAcceptedChange={setAccepted}
                onReadingChange={setReading}
              />
            ) : (
              <p data-id="legal-consent-unpublished">
                {LEGAL_TERMS_UNPUBLISHED_MESSAGE}
              </p>
            ))}
          {previewTerms?.form === 'line' &&
            previewTerms.documents.length > 0 && (
              <p
                className="small text-body-secondary mb-0"
                data-id="auth-consent-line"
              >
                By creating an account you accept the{' '}
                {previewTerms.documents.map((item, index) => (
                  <span key={item.document}>
                    {index > 0 && ' and the '}
                    <button
                      type="button"
                      className="btn btn-link btn-sm p-0"
                      data-id="legal-consent-read"
                      data-document={item.document}
                      onClick={() => setReading(item.document)}
                    >
                      {hilosLegalDocumentLabel(item.document)}
                    </button>
                  </span>
                ))}
                .
              </p>
            )}
          <HilosFormError
            message={previewError}
            dataId="legal-consent-preview-error"
          />
        </div>
      </HilosModal>
      <HilosModal
        open={reconsentOpen}
        title="Re-consent screen"
        initialFocus="dialog"
        onClose={() => reconsent.close()}
        actions={() => (
          <button
            type="button"
            className="btn btn-secondary"
            data-id="legal-reconsent-preview-close"
            onClick={() => reconsent.close()}
          >
            {LEGAL_RECONSENT_COPY.close}
          </button>
        )}
      >
        <div data-id="legal-reconsent-preview">
          <HilosLegalReconsent
            variant="preview"
            content={reconsentPreview}
            view={reconsentView}
            loading={reconsentLoading}
            error={reconsentError}
            onRetry={openReconsent}
            onShow={reconsent.show}
          />
        </div>
      </HilosModal>
    </HilosAdminPage>
  )
}
