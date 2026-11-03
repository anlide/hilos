import { useContext, useMemo } from 'react'
import {
  CHANGE_LOG_TABLE_COPY as copy,
  CHANGE_LOG_TABLE_SECTION,
  changeLogTableColumnCounts,
  formatChangeLogColumnCounts,
  formatChangeLogTableChanges,
  formatChangeLogTableLastChange,
  readHilosChangeLogTable,
} from '@hilos/core/admin/change-log'
import { HilosPages, hilosTabLinks, hilosTimeZone } from '@hilos/core/base'
import type { HilosChangeLogReceiptContext } from '@hilos/core/admin/change-log'

import { HilosLink } from '../../HilosLink.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'
import { HilosChangeLogTableShell } from './HilosChangeLogTableShell.js'

/** Props for the table Overview. */
export interface HilosChangeLogTablePageProps {
  /** Project connection, page scope and tracked actions. */
  context: HilosChangeLogReceiptContext
}

/** @param props Project connection and page scope. */
export function HilosChangeLogTablePage({
  context,
}: HilosChangeLogTablePageProps) {
  const router = useContext(HilosRouterContext)
  if (!router)
    throw new Error('HilosChangeLogTablePage requires a provided Hilos router.')
  useSignal(hilosTimeZone)
  const tableSection = useMemo(
    () => context.scopes.pageDataSignal(CHANGE_LOG_TABLE_SECTION),
    [context],
  )
  const table = readHilosChangeLogTable(useSignal(tableSection))
  const identity = useSignal(router.pageIdentity)
  const route = useSignal(router.currentRoute)
  const tabs = hilosTabLinks(
    identity?.pageTabs ?? [],
    route.params,
    router.resolvePath,
  )
  const historyPath = tabs.find(
    (tab) => tab.page === HilosPages.CHANGE_LOG_TABLE_HISTORY,
  )?.to
  const columnsPath = tabs.find(
    (tab) => tab.page === HilosPages.CHANGE_LOG_TABLE_COLUMNS,
  )?.to
  const triggersPath = tabs.find(
    (tab) => tab.page === HilosPages.CHANGE_LOG_TABLE_TRIGGERS,
  )?.to
  return (
    <HilosChangeLogTableShell
      context={context}
      page={HilosPages.CHANGE_LOG_TABLE}
    >
      {table && (
        <>
          {!table.journaled && (
            <p className="alert alert-secondary small py-2">{copy.untracked}</p>
          )}
          <div className="row row-cols-1 row-cols-sm-3 g-3 mb-3">
            {table.journaled && historyPath && (
              <div className="col">
                <HilosLink
                  to={historyPath}
                  className="card h-100 text-decoration-none link-body-emphasis"
                  data-id="hilos-change-log-table-link-history"
                >
                  <div className="card-body p-3">
                    <h2 className="small fw-normal d-flex align-items-center gap-2 text-body-secondary mb-1">
                      <i className="bi bi-activity" aria-hidden="true" />
                      Changes in 24 hours
                    </h2>
                    <div className="fs-4 lh-1 mb-1">
                      {formatChangeLogTableChanges(table.changesLast24h)}
                    </div>
                    <div className="small text-body-secondary">
                      Last change:{' '}
                      {formatChangeLogTableLastChange(table.lastChangeAt)}
                    </div>
                  </div>
                </HilosLink>
              </div>
            )}
            {table.journaled && columnsPath && (
              <div className="col">
                <HilosLink
                  to={columnsPath}
                  className="card h-100 text-decoration-none link-body-emphasis"
                  data-id="hilos-change-log-table-link-columns"
                >
                  <div className="card-body p-3">
                    <h2 className="small fw-normal d-flex align-items-center gap-2 text-body-secondary mb-1">
                      <i
                        className="bi bi-layout-three-columns"
                        aria-hidden="true"
                      />
                      Columns
                    </h2>
                    <div className="fs-4 lh-1 mb-1">{table.columns.length}</div>
                    <div className="small text-body-secondary">
                      {formatChangeLogColumnCounts(
                        changeLogTableColumnCounts(table),
                      )}
                    </div>
                  </div>
                </HilosLink>
              </div>
            )}
            {triggersPath && (
              <div className="col">
                <HilosLink
                  to={triggersPath}
                  className="card h-100 text-decoration-none link-body-emphasis"
                  data-id="hilos-change-log-table-link-triggers"
                >
                  <div className="card-body p-3">
                    <h2 className="small fw-normal d-flex align-items-center gap-2 text-body-secondary mb-1">
                      <i
                        className="bi bi-lightning-charge"
                        aria-hidden="true"
                      />
                      Triggers
                    </h2>
                    <div className="fs-4 lh-1 mb-1">
                      {table.triggers.length}
                    </div>
                    <div className="small text-body-secondary">
                      Service {table.triggers.length}
                    </div>
                  </div>
                </HilosLink>
              </div>
            )}
          </div>
        </>
      )}
    </HilosChangeLogTableShell>
  )
}
