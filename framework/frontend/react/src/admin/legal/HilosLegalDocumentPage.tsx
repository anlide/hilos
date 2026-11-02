import { useEffect, useLayoutEffect, useMemo, useState } from 'react'
import {
  computedSignal,
  createHilosLegalConsentPreview,
  createHilosLegalReconsentPreview,
  hilosLegalDocumentLabel,
  HilosPages,
  LEGAL_RECONSENT_COPY,
  type HilosLegalContext,
  type HilosLegalDocumentKey,
} from '@hilos/core/base'
import {
  createHilosLegalDocument,
  createHilosLegalSettingsTable,
  hilosLegalLapsedHref,
  hilosLegalLapsedLabel,
  HilosLegalSettingKey,
  type HilosLegalSettingRow,
  type HilosLegalTable,
} from '@hilos/core/admin/legal'
import { HilosLegalDocumentPageShell } from './HilosLegalDocumentPageShell.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosFormError } from '../../HilosFormError.js'
import { HilosLegalConsent } from '../../legal/HilosLegalConsent.js'
import { HilosLegalReconsent } from '../../legal/HilosLegalReconsent.js'
import { HilosLink } from '../../HilosLink.js'
import { useSignal } from '../../useSignal.js'

/** The hidden settings carrier of one committed document Overview setup. */
interface HilosLegalDocumentBinding {
  readonly context: HilosLegalContext
  readonly settings: HilosLegalTable<HilosLegalSettingRow>
}

/**
 * A document's coverage, sources and read-only previews.
 *
 * @param props The project's legal page context.
 */
export function HilosLegalDocumentPage({
  context,
}: {
  context: HilosLegalContext
}) {
  const documentSource = useMemo(
    () => createHilosLegalDocument(context),
    [context],
  )
  const details = useSignal(documentSource)
  const [binding, setBinding] = useState<HilosLegalDocumentBinding | null>(null)
  const lapsedLabelSource = useMemo(
    () =>
      computedSignal(() =>
        hilosLegalLapsedLabel(
          binding?.context === context
            ? binding.settings.controller.rows
                .get()
                .find(
                  ({ row }) =>
                    row?.rowKey === HilosLegalSettingKey.refusalAfterDeadline,
                )?.row?.value
            : undefined,
        ),
      ),
    [binding, context],
  )
  const lapsedLabel = useSignal(lapsedLabelSource)
  useLayoutEffect(() => {
    const settings = createHilosLegalSettingsTable(
      context,
      HilosPages.LEGAL_DOCUMENT,
    )
    settings.start()
    setBinding({ context, settings })
    return () => {
      settings.dispose()
      settings.controller.dispose()
    }
  }, [context])
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
  return (
    <HilosLegalDocumentPageShell
      context={context}
      page={HilosPages.LEGAL_DOCUMENT}
    >
      {details && (
        <>
          <div
            className="alert alert-secondary small text-break"
            data-id="legal-document-sources"
          >
            Standard sets: <code>{details.standardSetCatalogClass}</code>.
            {details.projectCatalogClass && (
              <>
                {' '}
                Project revisions and deviations:{' '}
                <code>{details.projectCatalogClass}</code>.
              </>
            )}{' '}
            These declarations are read-only; publishing a revision means
            deploying it.
          </div>
          <section className="mb-4">
            <h2 className="h5">Current revision</h2>
            <div className="row row-cols-1 row-cols-sm-3 g-3">
              <div className="col">
                <section className="border rounded-3 p-3 h-100">
                  <h3 className="small text-body-secondary fw-normal">
                    Covered
                  </h3>
                  <div
                    className="fs-4 fw-semibold"
                    data-id="legal-count-covered"
                  >
                    {details.covered}
                  </div>
                </section>
              </div>
              <div className="col">
                <section className="border rounded-3 p-3 h-100">
                  <h3 className="small text-body-secondary fw-normal">
                    Waiting for acceptance
                  </h3>
                  <div
                    className="fs-4 fw-semibold"
                    data-id="legal-count-window"
                  >
                    {details.window}
                  </div>
                </section>
              </div>
              <div className="col">
                <section
                  className="border rounded-3 p-3 h-100"
                  data-id="legal-document-lapsed"
                >
                  <h3 className="small text-body-secondary fw-normal">
                    {lapsedLabel}
                  </h3>
                  <div className="fs-4 fw-semibold">
                    {details.lapsed > 0 ? (
                      <HilosLink
                        to={hilosLegalLapsedHref(details.document)}
                        data-id="legal-count-lapsed-link"
                      >
                        <span data-id="legal-count-lapsed">
                          {details.lapsed}
                        </span>
                      </HilosLink>
                    ) : (
                      <span data-id="legal-count-lapsed">{details.lapsed}</span>
                    )}
                  </div>
                </section>
              </div>
            </div>
          </section>
          {details.newerSet && (
            <section className="alert alert-warning" data-id="legal-set-newer">
              <h2 className="h6">
                Hilos has released set {details.newerSet.version} for this
                document
              </h2>
              <p className="small">
                {details.newerSet.publishedOn} · {details.newerSet.significance}
                . The project keeps its declared set until it publishes a new
                revision.
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
        </>
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
                {previewTerms.unpublishedMessage}
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
    </HilosLegalDocumentPageShell>
  )
}
