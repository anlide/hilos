import { useEffect, useMemo } from 'react'
import {
  createHilosLegalChecksTable,
  createHilosLegalOverview,
  createHilosLegalSettingsTable,
  describeHilosLegalCheck,
  hilosLegalLapsedHref,
  hilosLegalLapsedLabel,
  HilosLegalRowKey,
  HilosLegalSettingKey,
  HILOS_LEGAL_VALUE_COPY,
  LEGAL_CATALOG_REFUSAL_SECTION,
  type HilosLegalSettingRow,
} from '@hilos/core/admin/legal'
import {
  HilosPages,
  type HilosLegalContext,
  type TableViewportController,
} from '@hilos/core/base'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosHideable } from '../../HilosHideable.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosSkeleton } from '../../HilosSkeleton.js'
import { HilosTableEmptyState } from '../../HilosTableEmptyState.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { useSignal } from '../../useSignal.js'

/**
 * One legal setting in its Overview tile: the value (or the hidden mark), the
 * settings window's refusal, or the skeleton until its row arrives.
 *
 * @param props The setting's row, the settings window and whether it refused.
 */
function HilosLegalSettingValue({
  setting,
  settings,
  unavailable,
}: {
  setting: HilosLegalSettingRow | null | undefined
  settings: TableViewportController<HilosLegalSettingRow>
  unavailable: boolean
}) {
  if (setting) {
    return (
      <div className="fs-4 fw-semibold">
        <HilosHideable value={setting.value}>
          {(shown) => HILOS_LEGAL_VALUE_COPY[shown] ?? shown}
        </HilosHideable>
      </div>
    )
  }

  return unavailable ? (
    <HilosTableEmptyState controller={settings} kind="unavailable" />
  ) : (
    <HilosSkeleton />
  )
}

/**
 * The legal root: a nonpersonal overview of the declared documents, the two
 * legal settings and the catalog checks.
 *
 * @param props The project's legal page context.
 */
export function HilosLegalPage({ context }: { context: HilosLegalContext }) {
  const overviewSource = useMemo(
    () => createHilosLegalOverview(context),
    [context],
  )
  const checks = useMemo(() => createHilosLegalChecksTable(context), [context])
  const settings = useMemo(
    () => createHilosLegalSettingsTable(context, HilosPages.LEGAL),
    [context],
  )
  const overview = useSignal(overviewSource)
  const refusal = useSignal(
    context.scopes.pageDataSignal(LEGAL_CATALOG_REFUSAL_SECTION),
  )
  const settingsRows = useSignal(settings.controller.rows)
  const settingsBody = useSignal(settings.controller.frame.body)
  const checksBody = useSignal(checks.controller.frame.body)
  const consent = settingsRows.find(
    ({ row }) => row?.rowKey === HilosLegalSettingKey.consentForm,
  )?.row
  const policy = settingsRows.find(
    ({ row }) => row?.rowKey === HilosLegalSettingKey.refusalAfterDeadline,
  )?.row
  const lapsedLabel = hilosLegalLapsedLabel(policy?.value)
  useEffect(() => {
    checks.start()
    settings.start()
    return () => {
      checks.dispose()
      settings.dispose()
    }
  }, [checks, settings])

  return (
    <HilosAdminPage page={HilosPages.LEGAL}>
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
        <>
          <div className="alert alert-secondary small">
            Document texts live in code. Standard sets belong to Hilos;
            revisions and deviations belong to the project. Publishing a
            revision means deploying it. This section records what is declared
            and who accepted it.
          </div>
          {overview ? (
            <div
              className="row row-cols-2 row-cols-lg-3 g-3 mb-3"
              data-id="legal-overview"
            >
              <div className="col">
                <section
                  className="border rounded-3 p-3 h-100"
                  data-id="legal-overview-documents"
                >
                  <h2 className="small text-body-secondary fw-normal">
                    Documents
                  </h2>
                  <div
                    className="fs-4 fw-semibold"
                    data-id="legal-document-count"
                  >
                    {overview.documentCount}
                  </div>
                  <div className="small text-body-secondary">
                    {overview.documents
                      .map(({ documentLabel }) => documentLabel)
                      .join(' · ')}
                  </div>
                </section>
              </div>
              <div className="col">
                <section
                  className="border rounded-3 p-3 h-100"
                  data-id="legal-overview-window"
                >
                  <h2 className="small text-body-secondary fw-normal">
                    Waiting for acceptance
                  </h2>
                  {overview.documents.length > 0 ? (
                    <dl className="mb-0">
                      {overview.documents.map((document) => (
                        <div
                          key={document.document}
                          className="d-flex flex-wrap justify-content-between gap-2"
                          data-document={document.document}
                        >
                          <dt className="small fw-normal">
                            {document.documentLabel}
                          </dt>
                          <dd
                            className="fw-semibold mb-0"
                            data-id="legal-count-window"
                          >
                            {document.window}
                          </dd>
                        </div>
                      ))}
                    </dl>
                  ) : (
                    <div
                      className="fs-4 fw-semibold"
                      data-id="legal-count-window"
                    >
                      0
                    </div>
                  )}
                </section>
              </div>
              <div className="col">
                <section
                  className="border rounded-3 p-3 h-100"
                  data-id="legal-overview-lapsed"
                >
                  <h2 className="small text-body-secondary fw-normal">
                    {lapsedLabel}
                  </h2>
                  {overview.documents.length > 0 ? (
                    <dl className="mb-0">
                      {overview.documents.map((document) => (
                        <div
                          key={document.document}
                          className="d-flex flex-wrap justify-content-between gap-2"
                          data-document={document.document}
                        >
                          <dt className="small fw-normal">
                            {document.documentLabel}
                          </dt>
                          <dd className="fw-semibold mb-0">
                            {/* The count of the people past the deadline opens
                                them in the people list (HIL-945); nobody to
                                open, nothing to follow. */}
                            {document.lapsed > 0 ? (
                              <HilosLink
                                to={hilosLegalLapsedHref(document.document)}
                                data-id="legal-count-lapsed-link"
                              >
                                <span data-id="legal-count-lapsed">
                                  {document.lapsed}
                                </span>
                              </HilosLink>
                            ) : (
                              <span data-id="legal-count-lapsed">
                                {document.lapsed}
                              </span>
                            )}
                          </dd>
                        </div>
                      ))}
                    </dl>
                  ) : (
                    <div
                      className="fs-4 fw-semibold"
                      data-id="legal-count-lapsed"
                    >
                      0
                    </div>
                  )}
                </section>
              </div>
              <div className="col">
                <section
                  className="border rounded-3 p-3 h-100"
                  data-id="legal-overview-newer-sets"
                >
                  <h2 className="small text-body-secondary fw-normal">
                    New framework sets
                  </h2>
                  <div
                    className="fs-4 fw-semibold"
                    data-id="legal-newer-set-count"
                  >
                    {overview.newerSetCount}
                  </div>
                  <div className="small text-body-secondary">
                    Documents with a newer framework set available.
                  </div>
                </section>
              </div>
              <div className="col">
                <section
                  className="border rounded-3 p-3 h-100"
                  data-id="legal-overview-consent"
                >
                  <h2 className="small text-body-secondary fw-normal">
                    Consent
                  </h2>
                  <HilosLegalSettingValue
                    setting={consent}
                    settings={settings.controller}
                    unavailable={settingsBody === 'unavailable'}
                  />
                  <div className="small text-body-secondary">
                    How people agree at registration.
                  </div>
                </section>
              </div>
              <div className="col">
                <section
                  className="border rounded-3 p-3 h-100"
                  data-id="legal-overview-refusal"
                >
                  <h2 className="small text-body-secondary fw-normal">
                    Refusal
                  </h2>
                  <HilosLegalSettingValue
                    setting={policy}
                    settings={settings.controller}
                    unavailable={settingsBody === 'unavailable'}
                  />
                  <div className="small text-body-secondary">
                    What happens after a deadline.
                  </div>
                </section>
              </div>
            </div>
          ) : (
            <HilosSkeleton />
          )}
          {checksBody !== 'empty' && (
            <div className="mt-4">
              <HilosViewportTable
                controller={checks.controller}
                cells={{
                  [HilosLegalRowKey.rowKey]: (row) => (
                    <div data-id="legal-check-row" data-check={row.rowKey}>
                      <span
                        className={`badge me-2 ${row.ok ? 'text-bg-success' : 'text-bg-warning'}`}
                      >
                        {row.ok ? 'OK' : 'Review'}
                      </span>
                      <strong>{describeHilosLegalCheck(row).title}</strong>
                      {row.items.length > 0 && (
                        <ul className="small mt-2 mb-0">
                          {describeHilosLegalCheck(row).lines.map(
                            (line, index) => (
                              <li key={index}>{line}</li>
                            ),
                          )}
                        </ul>
                      )}
                    </div>
                  ),
                }}
              />
            </div>
          )}
        </>
      )}
    </HilosAdminPage>
  )
}
