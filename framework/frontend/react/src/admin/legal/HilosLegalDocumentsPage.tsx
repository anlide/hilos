import { useLayoutEffect, useState } from 'react'
import {
  createHilosLegalDocumentsTable,
  createHilosLegalSettingsTable,
  hilosLegalLapsedHref,
  HilosLegalRowKey,
  HilosLegalSettingKey,
  LEGAL_CATALOG_REFUSAL_SECTION,
  type HilosLegalDocumentRow,
  type HilosLegalSettingRow,
  type HilosLegalTable,
} from '@hilos/core/admin/legal'
import {
  computedSignal,
  HILOS_TABLE_ACTIONS_KEY,
  HilosPages,
  resolveHilosPath,
  type HilosLegalContext,
} from '@hilos/core/base'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { useSignal } from '../../useSignal.js'

/** Props for the legal Documents tab. */
export interface HilosLegalDocumentsPageProps {
  /** The project's connection, actions and live page scopes. */
  context: HilosLegalContext
}

/** The two carriers of one committed setup of the Documents tab. */
interface HilosLegalDocumentsBinding {
  readonly context: HilosLegalContext
  readonly settings: HilosLegalTable<HilosLegalSettingRow>
  readonly documents: HilosLegalTable<HilosLegalDocumentRow>
}

/**
 * The legal Documents tab: the declared and recorded documents in one
 * server-windowed table, or the catalog refusal in its place.
 *
 * @param props The project's legal page context.
 */
export function HilosLegalDocumentsPage({
  context,
}: HilosLegalDocumentsPageProps) {
  const [binding, setBinding] = useState<HilosLegalDocumentsBinding | null>(
    null,
  )
  const refusal = useSignal(
    context.scopes.pageDataSignal(LEGAL_CATALOG_REFUSAL_SECTION),
  )

  useLayoutEffect(() => {
    // Both controllers belong to one committed setup, including StrictMode replay.
    const settings = createHilosLegalSettingsTable(
      context,
      HilosPages.LEGAL_DOCUMENTS,
    )
    // The past-deadline column names the live refusal policy of this page.
    const policy = computedSignal(
      () =>
        settings.controller.rows
          .get()
          .find(
            ({ row }) =>
              row?.rowKey === HilosLegalSettingKey.refusalAfterDeadline,
          )?.row?.value,
    )
    const documents = createHilosLegalDocumentsTable(
      context,
      HilosPages.LEGAL_DOCUMENTS,
      policy,
    )
    settings.start()
    documents.start()
    setBinding({ context, settings, documents })
    return () => {
      documents.dispose()
      settings.dispose()
      documents.controller.dispose()
      settings.controller.dispose()
    }
  }, [context])

  return (
    <HilosAdminPage page={HilosPages.LEGAL_DOCUMENTS}>
      {typeof refusal === 'string' ? (
        <div
          className="alert alert-danger text-break"
          role="alert"
          data-id="legal-catalog-refusal"
        >
          <h2 className="h6">The legal document catalog could not be loaded</h2>
          <p className="mb-0">{refusal}</p>
        </div>
      ) : binding?.context === context ? (
        <HilosViewportTable
          controller={binding.documents.controller}
          cells={{
            [HilosLegalRowKey.documentLabel]: (row) => (
              <>
                <div
                  className="fw-semibold"
                  data-id="legal-document-row"
                  data-document={row.rowKey}
                >
                  {row.documentLabel}
                </div>
                <div className="small text-body-secondary">{row.rowKey}</div>
                {!row.declared && (
                  <span className="badge text-bg-warning">Not in code</span>
                )}
              </>
            ),
            [HilosLegalRowKey.publishedOn]: (row) =>
              row.revision ? (
                <>
                  <div>
                    {row.publishedOn} · {row.revision.significance}
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
            // people list (HIL-945); nobody to open, nothing to follow. The
            // policy word stands in the column header, not in the cell.
            [HilosLegalRowKey.lapsed]: (row) =>
              row.lapsed > 0 ? (
                <HilosLink
                  to={hilosLegalLapsedHref(row.rowKey)}
                  data-id="legal-count-lapsed-link"
                  data-document={row.rowKey}
                >
                  <span data-id="legal-count-lapsed">{row.lapsed}</span>
                </HilosLink>
              ) : (
                <span data-id="legal-count-lapsed">{row.lapsed}</span>
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
      ) : null}
    </HilosAdminPage>
  )
}
