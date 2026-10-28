import { useMemo } from 'react'
import {
  CHANGE_LOG_TABLE_COPY as copy,
  CHANGE_LOG_TABLE_SECTION,
  CHANGE_LOG_TABLES_UNTRACKED_NOTE,
  formatChangeLogTableActivity,
  formatChangeLogTableOwner,
  readHilosChangeLogTable,
} from '@hilos/core/admin/change-log'
import { HilosPages, hilosTimeZone } from '@hilos/core/base'
import type { HilosChangeLogReceiptContext } from '@hilos/core/admin/change-log'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { useSignal } from '../../useSignal.js'

/** Props for the table Overview. */
export interface HilosChangeLogTablePageProps {
  /** Project connection, page scope and tracked actions. */
  context: HilosChangeLogReceiptContext
}

/** @param props Project connection and page scope. */
export function HilosChangeLogTablePage({
  context,
}: HilosChangeLogTablePageProps) {
  useSignal(hilosTimeZone)
  const tableSection = useMemo(
    () => context.scopes.pageDataSignal(CHANGE_LOG_TABLE_SECTION),
    [context],
  )
  const table = readHilosChangeLogTable(useSignal(tableSection))
  return (
    <HilosAdminPage page={HilosPages.CHANGE_LOG_TABLE}>
      {table === null ? (
        <p className="text-body-secondary">—</p>
      ) : (
        <>
          {' '}
          <div
            className="d-flex flex-wrap align-items-center gap-2 border rounded-3 p-3 mb-3"
            data-id="hilos-change-log-table-header"
          >
            <i className="bi bi-table text-body-secondary" aria-hidden="true" />
            <code className="fs-6 fw-semibold text-break">{table.name}</code>
            <span className="small text-body-secondary">
              {formatChangeLogTableOwner(table.owner)}
            </span>
            {table.journaled ? (
              <span className="badge bg-success-subtle text-success-emphasis border border-success-subtle ms-auto">
                <i className="bi bi-journal-check me-1" aria-hidden="true" />
                {copy.journaled}
              </span>
            ) : (
              <span className="badge bg-body-secondary text-body border ms-auto">
                {CHANGE_LOG_TABLES_UNTRACKED_NOTE}
              </span>
            )}
            {table.journaled && (
              <div className="w-100 small text-body-secondary">
                {formatChangeLogTableActivity(table)}
              </div>
            )}
          </div>
          {!table.journaled && (
            <p className="alert alert-secondary small py-2">{copy.untracked}</p>
          )}
        </>
      )}
    </HilosAdminPage>
  )
}
