// HilosChangeLogTablesPage — the framework Tracked tables page
// (HilosPages.CHANGE_LOG_TABLES). It draws the core's viewport and live schema
// metadata: the opening page response supplies the complete noisy-column
// snapshot, and filters apply only to the tables viewport. A journaled table
// offers Open once the router gives the Tracked table page an address. The
// core owns the table, the row view-model and every word, so a project mounts
// this view by passing its HilosChangeLogContext. Bootstrap classes only
// (styling-rules.md).
import { useContext, useEffect, useId, useMemo } from 'react'
import {
  CHANGE_LOG_COLUMN_MODES,
  CHANGE_LOG_COLUMN_MODES_TITLE,
  CHANGE_LOG_NOISY_COLUMNS_COLUMN_LABEL,
  CHANGE_LOG_NOISY_COLUMNS_EMPTY,
  CHANGE_LOG_NOISY_COLUMNS_NOTE,
  CHANGE_LOG_NOISY_COLUMNS_REASON_LABEL,
  CHANGE_LOG_NOISY_COLUMNS_SECTION,
  CHANGE_LOG_NOISY_COLUMNS_TITLE,
  CHANGE_LOG_TABLES_CHANGES_FIELD,
  CHANGE_LOG_TABLES_COLUMNS_FIELD,
  CHANGE_LOG_TABLES_LAST_CHANGE_FIELD,
  CHANGE_LOG_TABLES_NAME_FIELD,
  CHANGE_LOG_TABLES_NOTE,
  CHANGE_LOG_TABLES_OPEN_LABEL,
  CHANGE_LOG_TABLES_OWNER_FIELD,
  CHANGE_LOG_TABLES_UNTRACKED_NOTE,
  CHANGE_LOG_TABLE_ROUTE_PARAM,
  HILOS_TABLE_ACTIONS_KEY,
  HilosPages,
  createHilosChangeLogTablesTable,
  formatChangeLogColumnCounts,
  formatChangeLogTableChanges,
  formatChangeLogTableLastChange,
  formatChangeLogTableOwner,
  readHilosChangeLogNoisyColumns,
} from '@hilos/core'
import type { HilosChangeLogContext } from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'

/** Props for {@link HilosChangeLogTablesPage}. */
export interface HilosChangeLogTablesPageProps {
  /** The project's connection and page scope. */
  context: HilosChangeLogContext
}

/**
 * The Tracked tables page: the tables viewport, the noisy columns and the
 * column modes.
 *
 * @param props The project context (connection and page scope).
 */
export function HilosChangeLogTablesPage({
  context,
}: HilosChangeLogTablesPageProps) {
  const router = useContext(HilosRouterContext)
  const noisyHeadingId = useId()
  const noisyColumns = readHilosChangeLogNoisyColumns(
    useSignal(
      useMemo(
        () => context.scopes.pageDataSignal(CHANGE_LOG_NOISY_COLUMNS_SECTION),
        [context],
      ),
    ),
  )
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

      <section className="mt-4" data-id="hilos-change-log-noisy-columns">
        <h2 id={noisyHeadingId} className="h5">
          {CHANGE_LOG_NOISY_COLUMNS_TITLE}
        </h2>
        <p className="small text-body-secondary">
          {CHANGE_LOG_NOISY_COLUMNS_NOTE}
        </p>
        {noisyColumns === null ? (
          <p className="text-body-secondary">—</p>
        ) : noisyColumns.length === 0 ? (
          <p className="text-body-secondary">
            {CHANGE_LOG_NOISY_COLUMNS_EMPTY}
          </p>
        ) : (
          <>
            <div className="d-none d-md-block">
              <table
                className="table table-sm align-middle mb-0"
                aria-labelledby={noisyHeadingId}
              >
                <thead>
                  <tr>
                    <th scope="col">{CHANGE_LOG_NOISY_COLUMNS_COLUMN_LABEL}</th>
                    <th scope="col">{CHANGE_LOG_NOISY_COLUMNS_REASON_LABEL}</th>
                  </tr>
                </thead>
                <tbody>
                  {noisyColumns.map((column) => (
                    <tr key={`${column.table}.${column.column}`}>
                      <td className="font-monospace text-break">
                        {column.table}.{column.column}
                      </td>
                      <td className="small text-break">{column.reason}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="d-md-none d-flex flex-column gap-2">
              {noisyColumns.map((column) => (
                <div
                  key={`${column.table}.${column.column}`}
                  className="border rounded-3 p-3"
                >
                  <div className="font-monospace text-break mb-1">
                    {column.table}.{column.column}
                  </div>
                  <p className="small text-body-secondary text-break mb-0">
                    {column.reason}
                  </p>
                </div>
              ))}
            </div>
          </>
        )}
      </section>

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
