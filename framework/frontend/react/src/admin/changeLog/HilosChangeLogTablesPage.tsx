import { useContext, useEffect, useMemo } from 'react'
import {
  CHANGE_LOG_COLUMN_MODES,
  CHANGE_LOG_COLUMN_MODES_TITLE,
  CHANGE_LOG_TABLES_CHANGES_FIELD,
  CHANGE_LOG_TABLES_COLUMNS_FIELD,
  CHANGE_LOG_TABLES_LAST_CHANGE_FIELD,
  CHANGE_LOG_TABLES_NAME_FIELD,
  CHANGE_LOG_TABLES_NOTE,
  CHANGE_LOG_TABLES_OPEN_LABEL,
  CHANGE_LOG_TABLES_OWNER_FIELD,
  CHANGE_LOG_TABLES_UNTRACKED_NOTE,
  CHANGE_LOG_TABLE_ROUTE_PARAM,
  createHilosChangeLogTablesTable,
  formatChangeLogColumnCounts,
  formatChangeLogTableChanges,
  formatChangeLogTableLastChange,
  formatChangeLogTableOwner,
} from '@hilos/core/admin/change-log'
import { HILOS_TABLE_ACTIONS_KEY, HilosPages } from '@hilos/core/base'
import type { HilosChangeLogContext } from '@hilos/core/admin/change-log'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'

/** Props for {@link HilosChangeLogTablesPage}. */
export interface HilosChangeLogTablesPageProps {
  /** The project's connection and page scope. */
  context: HilosChangeLogContext
}

/**
 * The tracked tables viewport and the column-mode reference.
 *
 * @param props The project context (connection and page scope).
 */
export function HilosChangeLogTablesPage({
  context,
}: HilosChangeLogTablesPageProps) {
  const router = useContext(HilosRouterContext)
  const tables = useMemo(
    () => createHilosChangeLogTablesTable(context),
    [context],
  )

  useEffect(() => {
    tables.start()
    return () => tables.dispose()
  }, [tables])

  /** Muted text for a table without the journal label. */
  function muted(journaled: boolean): string | undefined {
    return journaled ? undefined : 'text-body-secondary'
  }

  return (
    <HilosAdminPage page={HilosPages.CHANGE_LOG_TABLES}>
      <p className="text-body-secondary">{CHANGE_LOG_TABLES_NOTE}</p>

      <HilosViewportTable
        controller={tables.controller}
        cells={{
          [CHANGE_LOG_TABLES_NAME_FIELD]: (row) => (
            <>
              <code
                className={`text-break${row.journaled ? '' : ' text-body-secondary'}`}
              >
                {row.name}
              </code>
              {!row.journaled && (
                <div className="small text-body-secondary">
                  {CHANGE_LOG_TABLES_UNTRACKED_NOTE}
                </div>
              )}
            </>
          ),
          [CHANGE_LOG_TABLES_OWNER_FIELD]: (row) => (
            <span className={muted(row.journaled)}>
              {formatChangeLogTableOwner(row.owner)}
            </span>
          ),
          [CHANGE_LOG_TABLES_COLUMNS_FIELD]: (row) => (
            <span className={muted(row.journaled)}>
              {formatChangeLogColumnCounts(row.columns)}
            </span>
          ),
          [CHANGE_LOG_TABLES_CHANGES_FIELD]: (row) => (
            <span className={muted(row.journaled)}>
              {formatChangeLogTableChanges(row.changesLast24h)}
            </span>
          ),
          [CHANGE_LOG_TABLES_LAST_CHANGE_FIELD]: (row) => (
            <span className={muted(row.journaled)}>
              {formatChangeLogTableLastChange(row.lastChangeAt)}
            </span>
          ),
          [HILOS_TABLE_ACTIONS_KEY]: (row) => {
            // Resolved once for each drawn actions cell: only a journaled
            // table whose detail page has an address offers Open.
            const path = row.journaled
              ? router?.resolvePath(HilosPages.CHANGE_LOG_TABLE, {
                  [CHANGE_LOG_TABLE_ROUTE_PARAM]: row.name,
                })
              : undefined
            return (
              path !== undefined && (
                <HilosLink
                  to={path}
                  className="btn btn-sm btn-outline-secondary"
                  data-id={`hilos-change-log-open-table-${row.name}`}
                  aria-label={`${CHANGE_LOG_TABLES_OPEN_LABEL} ${row.name}`}
                >
                  {CHANGE_LOG_TABLES_OPEN_LABEL}
                </HilosLink>
              )
            )
          },
        }}
      />

      <section className="mt-4" data-id="hilos-change-log-column-modes">
        <h2 className="h5">{CHANGE_LOG_COLUMN_MODES_TITLE}</h2>
        <div className="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3">
          {CHANGE_LOG_COLUMN_MODES.map((mode) => (
            <div key={mode.key} className="col">
              <div className="border rounded-3 p-3 h-100">
                <span className={`badge text-wrap mb-2 ${mode.tone}`}>
                  <i className={`bi ${mode.icon} me-1`} aria-hidden="true" />
                  {mode.label}
                </span>
                <p className="small text-body-secondary mb-0">
                  {mode.description}
                </p>
              </div>
            </div>
          ))}
        </div>
      </section>
    </HilosAdminPage>
  )
}
