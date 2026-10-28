import { useContext, useId, useMemo } from 'react'
import type { ReactNode } from 'react'
import {
  CHANGE_LOG_TABLE_COPY as copy,
  CHANGE_LOG_TABLE_ROUTE_PARAM,
  CHANGE_LOG_TABLE_SECTION,
  changeLogColumnModeCard,
  formatChangeLogColumnStorage,
  readHilosChangeLogTable,
} from '@hilos/core/admin/change-log'
import { HilosPages } from '@hilos/core/base'
import type {
  HilosChangeLogReceiptContext,
  HilosChangeLogTableColumn,
} from '@hilos/core/admin/change-log'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'

/**
 * Draw the badge of the mode a column enters the journal with, or a dash.
 *
 * @param mode The column's declared mode, or null for an untracked table.
 */
function modeBadge(mode: HilosChangeLogTableColumn['mode']): ReactNode {
  const card = changeLogColumnModeCard(mode)
  return card ? (
    <span className={`badge text-wrap ${card.tone}`}>
      <i className={`bi ${card.icon} me-1`} aria-hidden="true" />
      {card.label}
    </span>
  ) : (
    '—'
  )
}

/** @param props Project connection and page scope. */
export function HilosChangeLogTableColumnsPage({
  context,
}: {
  context: HilosChangeLogReceiptContext
}) {
  const router = useContext(HilosRouterContext)!
  const route = useSignal(router.currentRoute)
  const tableSection = useMemo(
    () => context.scopes.pageDataSignal(CHANGE_LOG_TABLE_SECTION),
    [context],
  )
  const table = readHilosChangeLogTable(useSignal(tableSection))
  const columnsHeadingId = useId()
  const hasNoise = table?.columns.some(({ mode }) => mode === 'noise') === true
  const overviewPath = router.resolvePath(HilosPages.CHANGE_LOG_TABLE, {
    [CHANGE_LOG_TABLE_ROUTE_PARAM]:
      route.params[CHANGE_LOG_TABLE_ROUTE_PARAM] ?? '',
  })
  return (
    <HilosAdminPage page={HilosPages.CHANGE_LOG_TABLE_COLUMNS}>
      <p>
        {overviewPath !== undefined && (
          <HilosLink
            to={overviewPath}
            data-id="hilos-change-log-table-overview"
          >
            Overview
          </HilosLink>
        )}
      </p>
      {table !== null && (
        <>
          <section className="mb-4" data-id="hilos-change-log-table-columns">
            <h2 id={columnsHeadingId} className="h5">
              {copy.columns}
            </h2>
            <div className="table-responsive d-none d-md-block">
              <table
                className="table table-sm align-middle mb-0"
                aria-labelledby={columnsHeadingId}
              >
                <thead>
                  <tr>
                    <th scope="col">{copy.column}</th>
                    <th scope="col">{copy.storedAs}</th>
                    <th scope="col">{copy.inJournal}</th>
                  </tr>
                </thead>
                <tbody>
                  {table.columns.map((column) => (
                    <tr key={column.name}>
                      <td>
                        <code className="text-break">{column.name}</code>
                      </td>
                      <td className="small">
                        {formatChangeLogColumnStorage(column)}
                      </td>
                      <td>{modeBadge(column.mode)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div
              className="d-md-none"
              role="list"
              aria-labelledby={columnsHeadingId}
            >
              {table.columns.map((column) => (
                <div key={column.name} className="card mb-2" role="listitem">
                  <div className="card-body py-2 px-3">
                    <div className="fw-medium mb-1">
                      <code className="text-break">{column.name}</code>
                    </div>
                    <dl className="row mb-0 small g-0">
                      <dt className="col-5 fw-normal text-body-secondary">
                        {copy.storedAs}
                      </dt>
                      <dd className="col-7 mb-0">
                        {formatChangeLogColumnStorage(column)}
                      </dd>
                      <dt className="col-5 fw-normal text-body-secondary">
                        {copy.inJournal}
                      </dt>
                      <dd className="col-7 mb-0">{modeBadge(column.mode)}</dd>
                    </dl>
                  </div>
                </div>
              ))}
            </div>
            {hasNoise && (
              <p className="small text-body-secondary mt-2 mb-0">
                {copy.noisyNote}
              </p>
            )}
          </section>
        </>
      )}
    </HilosAdminPage>
  )
}
