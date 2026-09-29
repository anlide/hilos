import { useEffect, useMemo } from 'react'
import {
  createHilosLegalDocumentsTable,
  createHilosLegalChecksTable,
  createHilosLegalSettingsTable,
  HilosPages,
  HilosLegalRowKey,
  HilosLegalSettingKey,
  HILOS_TABLE_ACTIONS_KEY,
  LEGAL_CATALOG_REFUSAL_SECTION,
  describeHilosLegalCheck,
  hilosLegalDocumentLabel,
  hilosLegalLapsedHref,
  resolveHilosPath,
  type HilosLegalContext,
} from '@hilos/core'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { useSignal } from '../../useSignal.js'

/** Read-only legal declarations and acceptance tallies. */
export function HilosLegalPage({ context }: { context: HilosLegalContext }) {
  const documents = useMemo(
    () => createHilosLegalDocumentsTable(context),
    [context],
  )
  const checks = useMemo(() => createHilosLegalChecksTable(context), [context])
  const settings = useMemo(
    () => createHilosLegalSettingsTable(context, HilosPages.LEGAL),
    [context],
  )
  const refusal = useSignal(
    context.scopes.pageDataSignal(LEGAL_CATALOG_REFUSAL_SECTION),
  )
  const settingRows = useSignal(settings.controller.rows)
  const documentRows = useSignal(documents.controller.rows)
  const lapsedLabel =
    settingRows.find(
      ({ row }) => row?.rowKey === HilosLegalSettingKey.refusalAfterDeadline,
    )?.row?.value === 'remind'
      ? 'Past deadline'
      : 'Frozen'
  useEffect(() => {
    documents.start()
    checks.start()
    settings.start()
    return () => {
      documents.dispose()
      checks.dispose()
      settings.dispose()
    }
  }, [documents, checks, settings])
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
          <HilosViewportTable
            controller={documents.controller}
            cells={{
              [HilosLegalRowKey.rowKey]: (row) => (
                <>
                  <span
                    data-id="legal-document-row"
                    data-document={row.rowKey}
                    className="fw-semibold"
                  >
                    {hilosLegalDocumentLabel(row.rowKey)}
                  </span>
                  {!row.declared && (
                    <span className="badge text-bg-warning ms-2">
                      Not in code
                    </span>
                  )}
                </>
              ),
              [HilosLegalRowKey.revision]: (row) =>
                row.revision ? (
                  <>
                    <div>
                      {row.revision.publishedOn} · {row.revision.significance}
                    </div>
                    <div className="small text-body-secondary">
                      Effective from {row.revision.effectiveOn}
                    </div>
                  </>
                ) : (
                  <span className="text-body-secondary">Not in code</span>
                ),
              [HilosLegalRowKey.covered]: (row) => (
                <span data-id="legal-count-covered">{row.covered}</span>
              ),
              [HilosLegalRowKey.window]: (row) => (
                <span data-id="legal-count-window">{row.window}</span>
              ),
              // The count of the people past the deadline opens them in the
              // people list (HIL-945); nobody to open, nothing to follow.
              [HilosLegalRowKey.lapsed]: (row) => (
                <>
                  {row.lapsed > 0 ? (
                    <HilosLink
                      to={hilosLegalLapsedHref(row.rowKey)}
                      data-id="legal-count-lapsed-link"
                      data-document={row.rowKey}
                    >
                      <span data-id="legal-count-lapsed">{row.lapsed}</span>
                    </HilosLink>
                  ) : (
                    <span data-id="legal-count-lapsed">{row.lapsed}</span>
                  )}{' '}
                  <span className="small text-body-secondary">
                    {lapsedLabel}
                  </span>
                </>
              ),
              [HILOS_TABLE_ACTIONS_KEY]: (row) => (
                <HilosLink
                  to={resolveHilosPath(HilosPages.LEGAL_DOCUMENT, {
                    documentKey: row.rowKey,
                  })}
                  className="btn btn-sm btn-outline-secondary"
                  data-id="legal-document-open"
                  data-document={row.rowKey}
                >
                  Open
                </HilosLink>
              ),
            }}
          />
          {documentRows.length > 0 && (
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
          <section className="mt-4 small text-body-secondary">
            <h2 className="h6">What this section does not do</h2>
            <p>
              There is no text editor, publish button, or acceptance-window
              setting. Those decisions are declared in the project's code.
            </p>
            <h2 className="h6">Why this is under Access &amp; identity</h2>
            <p className="mb-0">
              Agreement belongs to a person and an exact revision. Its deadline
              can affect that person's access.
            </p>
          </section>
        </>
      )}
    </HilosAdminPage>
  )
}
