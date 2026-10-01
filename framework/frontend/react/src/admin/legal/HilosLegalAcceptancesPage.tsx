import { useEffect, useMemo } from 'react'
import {
  createHilosLegalAcceptancesTable,
  hiddenAsWord,
  hilosLegalDocumentLabel,
  HilosLegalRowKey,
  HILOS_TABLE_ACTIONS_KEY,
  HilosPages,
  resolveHilosPath,
  type HilosLegalContext,
} from '@hilos/core'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'

/** Immutable acceptance records with the complete server-supplied filter vocabulary. */
export function HilosLegalAcceptancesPage({
  context,
}: {
  context: HilosLegalContext
}) {
  const table = useMemo(
    () => createHilosLegalAcceptancesTable(context),
    [context],
  )
  useEffect(() => {
    table.start()
    return () => table.dispose()
  }, [table])
  return (
    <HilosAdminPage page={HilosPages.LEGAL_ACCEPTANCES}>
      <div data-id="legal-acceptances-table">
        <HilosViewportTable
          controller={table.controller}
          cells={{
            [HilosLegalRowKey.name]: (row) => (
              <div data-id="legal-acceptance-row" data-record={row.rowKey}>
                <strong>{hiddenAsWord(row.name)}</strong>
                <div className="small text-body-secondary">
                  {hiddenAsWord(row.email) ?? 'No verified email'}
                </div>
              </div>
            ),
            [HilosLegalRowKey.document]: (row) =>
              hilosLegalDocumentLabel(row.document),
            [HilosLegalRowKey.revisionId]: (row) => (
              <>
                {row.revisionId}{' '}
                {row.declared === false && (
                  <span className="badge text-bg-warning">Not in code</span>
                )}
              </>
            ),
            [HILOS_TABLE_ACTIONS_KEY]: (row) => (
              <HilosLink
                to={resolveHilosPath(HilosPages.USER, {
                  userId: String(row.userId),
                })}
                className="btn btn-sm btn-outline-secondary"
                data-id="legal-acceptance-person"
              >
                Account
              </HilosLink>
            ),
          }}
        />
      </div>
      <p className="small text-body-secondary mt-3">
        Acceptance records cannot be edited or deleted here. A record names one
        person, one document, one exact revision and the time of acceptance.
        Refusal and silence create no acceptance record.
      </p>
    </HilosAdminPage>
  )
}
