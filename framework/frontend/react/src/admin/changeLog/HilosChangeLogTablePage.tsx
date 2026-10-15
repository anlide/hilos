// HilosChangeLogTablePage — the framework Tracked table page
// (HilosPages.CHANGE_LOG_TABLE). It draws one table's section: its header, its
// columns, its journal service triggers, and the history window the view builds
// after the page answers, only for a journaled table. The address names the
// table and may narrow the history to one record; the receipt modal reads
// through the Change Log's own reading action. The core owns the section, the
// history table and every word, so a project mounts this view by passing its
// HilosChangeLogReceiptContext. Bootstrap classes only (styling-rules.md).
import { useContext, useEffect, useId, useMemo, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import {
  CHANGE_LOG_HISTORY_ACTOR_LABEL_FIELD,
  CHANGE_LOG_HISTORY_CHANGES_FIELD,
  CHANGE_LOG_HISTORY_CREATED_AT_FIELD,
  CHANGE_LOG_HISTORY_MUTATION_FIELD,
  CHANGE_LOG_HISTORY_RECORD_KEY_FIELD,
  CHANGE_LOG_ON_BEHALF_OF_TEXT,
  CHANGE_LOG_RECORD_ROUTE_PARAM,
  CHANGE_LOG_TABLE_COPY as copy,
  CHANGE_LOG_TABLE_ROUTE_PARAM,
  CHANGE_LOG_TABLE_SECTION,
  CHANGE_LOG_TABLES_UNTRACKED_NOTE,
  HILOS_TABLE_ACTIONS_KEY,
  HilosPages,
  changeLogChannelBadge,
  changeLogColumnModeCard,
  changeLogRecordRouteParam,
  computedSignal,
  createHilosChangeLogHistoryTable,
  createHilosChangeLogReceiptReader,
  formatChangeLogColumnStorage,
  formatChangeLogHistoryChanges,
  formatChangeLogMutation,
  formatChangeLogOnBehalfOf,
  formatChangeLogRecordKey,
  formatChangeLogTableActivity,
  formatChangeLogTableOwner,
  formatChangeLogTime,
  formatChangeLogTrigger,
  formatChangeLogWho,
  isHiddenValue,
  readChangeLogRecordRouteParam,
  readHilosChangeLogTable,
  subscribeSignal,
} from '@hilos/core'
import type {
  HilosChangeLogHistoryRow,
  HilosChangeLogHistoryTable,
  HilosChangeLogReceiptContext,
  HilosChangeLogTableColumn,
} from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosHiddenMark } from '../../HilosHiddenMark.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'
import { HilosChangeLogReceiptModal } from './HilosChangeLogReceiptModal.js'

/** Props for {@link HilosChangeLogTablePage}. */
export interface HilosChangeLogTablePageProps {
  /** The project's connection, page scope, and shared tracked actions. */
  context: HilosChangeLogReceiptContext
}

/** The history window of the current address, and the table drawing it. */
interface HistoryWindow {
  readonly table: HilosChangeLogHistoryTable
  /** Keys the viewport table, so every rebuilt window gets a table of its own. */
  readonly generation: number
}

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

/**
 * The Tracked table page: the header, the columns, the service triggers, the
 * history and the receipt modal.
 *
 * @param props The project context (connection, page scope, tracked actions).
 */
export function HilosChangeLogTablePage({
  context,
}: HilosChangeLogTablePageProps) {
  const router = useContext(HilosRouterContext)
  if (!router) {
    throw new Error('HilosChangeLogTablePage requires a provided Hilos router.')
  }

  const columnsHeadingId = useId()
  const triggersHeadingId = useId()
  const route = useSignal(router.currentRoute)
  const tableId = route.params[CHANGE_LOG_TABLE_ROUTE_PARAM] ?? ''
  const recordParam = route.params[CHANGE_LOG_RECORD_ROUTE_PARAM]
  const record = readChangeLogRecordRouteParam(recordParam)
  const tableSection = useMemo(
    () => context.scopes.pageDataSignal(CHANGE_LOG_TABLE_SECTION),
    [context],
  )
  const table = readHilosChangeLogTable(useSignal(tableSection))
  const journaled = table?.journaled === true
  const hasNoise = table?.columns.some(({ mode }) => mode === 'noise') === true
  const tablePath = router.resolvePath(HilosPages.CHANGE_LOG_TABLE, {
    [CHANGE_LOG_TABLE_ROUTE_PARAM]: tableId,
  })
  const [history, setHistory] = useState<HistoryWindow | null>(null)
  const generations = useRef(0)
  const reader = useMemo(
    () => createHilosChangeLogReceiptReader(context),
    [context],
  )
  const dialog = useSignal(reader.dialog)

  useEffect(() => {
    const offLeave = router.onLeave(() => reader.close())
    const offIdentity = subscribeSignal(router.pageIdentity, () =>
      reader.close(),
    )
    const offRoute = subscribeSignal(router.currentRoute, () => reader.close())
    // A re-decided page replaces its scope even when the address stays the
    // same. Discard attribution synchronously, before a viewer can render the
    // new scope.
    const offScope = subscribeSignal(
      computedSignal(() => context.scopes.page()),
      () => reader.close(),
    )
    return () => {
      offLeave()
      offIdentity()
      offRoute()
      offScope()
      // Closed, not disposed: StrictMode runs this cleanup and the effect again
      // over the same memoized reader, and a disposed reader never opens again.
      // Unmounted, nothing opens it any more, and closing already drops the
      // snapshot and every reply still in flight.
      reader.close()
    }
  }, [context, router, reader])

  // The router clears its buffered page response after publishing the route.
  // An effect runs after the render that navigation scheduled, so the window
  // binds once that synchronous navigation has finished. The core calls the
  // fields getter itself, so it reads the section signal rather than this
  // render's table.
  useEffect(() => {
    if (!journaled) return
    const next = createHilosChangeLogHistoryTable(context, {
      table: tableId,
      record: readChangeLogRecordRouteParam(recordParam),
      fields: () =>
        (readHilosChangeLogTable(tableSection.get())?.columns ?? [])
          .filter(({ mode }) => mode !== 'record_key')
          .map(({ name }) => name),
    })
    next.start()
    generations.current += 1
    setHistory({ table: next, generation: generations.current })
    return () => {
      next.dispose()
      setHistory(null)
    }
  }, [context, tableSection, tableId, recordParam, journaled])

  // An arrow, not a declaration: a hoisted function would lose the router's
  // narrowing above.
  /**
   * Address this table narrowed to a record whose key is not masked.
   *
   * @param row History row whose record the link would open.
   */
  const recordPath = (row: HilosChangeLogHistoryRow): string | undefined => {
    const key = row.recordKey
    const parts = (key ?? []).filter(
      (part): part is number | string => part !== null,
    )
    if (key === null || parts.length === 0 || parts.length !== key.length) {
      return undefined
    }
    return router.resolvePath(HilosPages.CHANGE_LOG_TABLE, {
      [CHANGE_LOG_TABLE_ROUTE_PARAM]: tableId,
      [CHANGE_LOG_RECORD_ROUTE_PARAM]: changeLogRecordRouteParam(parts),
    })
  }

  /**
   * Open the receipt a history row carries, if it has one.
   *
   * @param row History row whose receipt button was pressed.
   */
  function openReceipt(row: HilosChangeLogHistoryRow): void {
    if (row.receiptId !== null) void reader.openReceipt(row.receiptId)
  }

  return (
    <HilosAdminPage page={HilosPages.CHANGE_LOG_TABLE}>
      {table === null ? (
        <p className="text-body-secondary">—</p>
      ) : (
        <>
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

          {!table.journaled ? (
            <p className="alert alert-secondary small py-2">{copy.untracked}</p>
          ) : (
            <>
              <section
                className="mb-4"
                data-id="hilos-change-log-table-columns"
              >
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
                    <div
                      key={column.name}
                      className="card mb-2"
                      role="listitem"
                    >
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
                          <dd className="col-7 mb-0">
                            {modeBadge(column.mode)}
                          </dd>
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

              <section
                className="mb-4"
                data-id="hilos-change-log-table-triggers"
              >
                <h2 id={triggersHeadingId} className="h5">
                  {copy.triggers}
                </h2>
                <p className="small text-body-secondary mb-2">
                  {copy.triggersLead}
                </p>
                <div className="table-responsive d-none d-md-block">
                  <table
                    className="table table-sm align-middle mb-0"
                    aria-labelledby={triggersHeadingId}
                  >
                    <thead>
                      <tr>
                        <th scope="col">{copy.trigger}</th>
                        <th scope="col">{copy.owner}</th>
                        <th scope="col">{copy.fires}</th>
                        <th scope="col">{copy.writesTo}</th>
                        <th scope="col">{copy.validFrom}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {table.triggers.map((trigger) => (
                        <tr key={trigger.name}>
                          <td>
                            <code className="text-break">{trigger.name}</code>
                          </td>
                          <td>
                            <span className="badge bg-body-secondary text-body border">
                              {copy.service}
                            </span>
                          </td>
                          <td className="small text-nowrap">
                            {formatChangeLogTrigger(trigger).fires}
                          </td>
                          <td className="small">
                            {formatChangeLogTrigger(trigger).writesTo}
                          </td>
                          <td className="small text-nowrap">
                            {formatChangeLogTrigger(trigger).validFrom}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                <div
                  className="d-md-none"
                  role="list"
                  aria-labelledby={triggersHeadingId}
                >
                  {table.triggers.map((trigger) => (
                    <div
                      key={trigger.name}
                      className="card mb-2"
                      role="listitem"
                    >
                      <div className="card-body py-2 px-3">
                        <div className="d-flex flex-wrap align-items-center gap-1 fw-medium mb-1">
                          <code className="text-break">{trigger.name}</code>
                          <span className="badge bg-body-secondary text-body border">
                            {copy.service}
                          </span>
                        </div>
                        <dl className="row mb-0 small g-0">
                          <dt className="col-5 fw-normal text-body-secondary">
                            {copy.fires}
                          </dt>
                          <dd className="col-7 mb-0">
                            {formatChangeLogTrigger(trigger).fires}
                          </dd>
                          <dt className="col-5 fw-normal text-body-secondary">
                            {copy.writesTo}
                          </dt>
                          <dd className="col-7 mb-0">
                            {formatChangeLogTrigger(trigger).writesTo}
                          </dd>
                          <dt className="col-5 fw-normal text-body-secondary">
                            {copy.validFrom}
                          </dt>
                          <dd className="col-7 mb-0">
                            {formatChangeLogTrigger(trigger).validFrom}
                          </dd>
                        </dl>
                      </div>
                    </div>
                  ))}
                </div>
                <p className="small text-body-secondary mt-2 mb-0">
                  {copy.triggersFootnote}
                </p>
              </section>

              {history && (
                <section>
                  {record !== null && (
                    <div className="mb-2">
                      <span
                        className="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle d-inline-flex align-items-center gap-1"
                        data-id="hilos-change-log-record-filter"
                      >
                        {copy.onlyRecord} {formatChangeLogRecordKey(record)}
                        {tablePath !== undefined && (
                          <HilosLink
                            to={tablePath}
                            className="link-primary"
                            data-id="hilos-change-log-record-filter-clear"
                            aria-label={copy.showAllRecords}
                          >
                            <i className="bi bi-x-lg" aria-hidden="true" />
                          </HilosLink>
                        )}
                      </span>
                    </div>
                  )}
                  <HilosViewportTable
                    key={history.generation}
                    controller={history.table.controller}
                    cells={{
                      [CHANGE_LOG_HISTORY_CREATED_AT_FIELD]: (row) =>
                        formatChangeLogTime(row.createdAt),
                      [CHANGE_LOG_HISTORY_RECORD_KEY_FIELD]: (row) => {
                        const path = recordPath(row)
                        return path !== undefined ? (
                          <HilosLink
                            to={path}
                            data-id={`hilos-change-log-history-record-${row.rowKey}`}
                          >
                            {formatChangeLogRecordKey(row.recordKey)}
                          </HilosLink>
                        ) : (
                          <span>{formatChangeLogRecordKey(row.recordKey)}</span>
                        )
                      },
                      [CHANGE_LOG_HISTORY_MUTATION_FIELD]: (row) => (
                        <span className="badge bg-body-secondary text-body border">
                          {formatChangeLogMutation(row.mutation)}
                        </span>
                      ),
                      [CHANGE_LOG_HISTORY_CHANGES_FIELD]: (row) =>
                        formatChangeLogHistoryChanges(row).map(
                          (line, index) => (
                            <div key={index} className="small text-break">
                              {line}
                            </div>
                          ),
                        ),
                      [CHANGE_LOG_HISTORY_ACTOR_LABEL_FIELD]: (row) => {
                        const who = formatChangeLogWho(row)
                        const onBehalfOf = formatChangeLogOnBehalfOf(row)
                        const channel = changeLogChannelBadge(row.channel)
                        return (
                          <>
                            <span
                              className={
                                row.actorDeleted
                                  ? 'text-body-secondary'
                                  : undefined
                              }
                            >
                              {isHiddenValue(who) ? (
                                <>
                                  <HilosHiddenMark />
                                  {row.actorId !== null && ` (#${row.actorId})`}
                                </>
                              ) : (
                                who
                              )}
                            </span>
                            {row.receiptId === null && (
                              <div className="small text-body-secondary">
                                {copy.pastApplication}
                              </div>
                            )}
                            {row.subjectId !== null && (
                              <div className="small text-body-secondary">
                                {CHANGE_LOG_ON_BEHALF_OF_TEXT}{' '}
                                {isHiddenValue(onBehalfOf) ? (
                                  <>
                                    <HilosHiddenMark />
                                    {` (#${row.subjectId})`}
                                  </>
                                ) : (
                                  (onBehalfOf ?? `#${row.subjectId}`)
                                )}
                              </div>
                            )}
                            {row.receiptId !== null && channel !== null && (
                              <span className="badge bg-body-secondary text-body border mt-1">
                                <i
                                  className={`bi ${channel.icon} me-1`}
                                  aria-hidden="true"
                                />
                                {channel.label}
                              </span>
                            )}
                          </>
                        )
                      },
                      [HILOS_TABLE_ACTIONS_KEY]: (row) =>
                        row.receiptId !== null ? (
                          <button
                            type="button"
                            className="btn btn-sm btn-outline-secondary text-nowrap"
                            data-id={`hilos-change-log-history-receipt-${row.rowKey}`}
                            aria-label={`${copy.receipt} #${row.receiptId}`}
                            onClick={() => openReceipt(row)}
                          >
                            {copy.receipt}
                          </button>
                        ) : (
                          <span className="small text-body-secondary text-nowrap">
                            {copy.noReceipt}
                          </span>
                        ),
                    }}
                  />
                  <p className="small text-body-secondary mt-2 mb-0">
                    {copy.historyNote}
                  </p>
                </section>
              )}
            </>
          )}
        </>
      )}
      <HilosChangeLogReceiptModal reader={reader} dialog={dialog} />
    </HilosAdminPage>
  )
}
