import { useContext, useEffect, useMemo, useRef, useState } from 'react'
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
  changeLogChannelBadge,
  changeLogRecordRouteParam,
  createHilosChangeLogHistoryTable,
  createHilosChangeLogReceiptReader,
  formatChangeLogHistoryChanges,
  formatChangeLogMutation,
  formatChangeLogOnBehalfOf,
  formatChangeLogRecordKey,
  formatChangeLogTime,
  formatChangeLogWho,
  readChangeLogRecordRouteParam,
  readHilosChangeLogTable,
} from '@hilos/core/admin/change-log'
import {
  HILOS_TABLE_ACTIONS_KEY,
  HilosPages,
  computedSignal,
  hilosTimeZone,
  isHiddenValue,
  subscribeSignal,
} from '@hilos/core/base'
import type {
  HilosChangeLogHistoryRow,
  HilosChangeLogHistoryTable,
  HilosChangeLogReceiptContext,
} from '@hilos/core/admin/change-log'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosHiddenMark } from '../../HilosHiddenMark.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'
import { HilosChangeLogReceiptModal } from './HilosChangeLogReceiptModal.js'

/** Props for {@link HilosChangeLogTableHistoryPage}. */
export interface HilosChangeLogTableHistoryPageProps {
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
 * Record history and its receipt reader.
 *
 * @param props The project context (connection, page scope, tracked actions).
 */
export function HilosChangeLogTableHistoryPage({
  context,
}: HilosChangeLogTableHistoryPageProps) {
  const router = useContext(HilosRouterContext)
  if (!router) {
    throw new Error(
      'HilosChangeLogTableHistoryPage requires a provided Hilos router.',
    )
  }

  const route = useSignal(router.currentRoute)
  useSignal(hilosTimeZone)
  const tableId = route.params[CHANGE_LOG_TABLE_ROUTE_PARAM] ?? ''
  const recordParam = route.params[CHANGE_LOG_RECORD_ROUTE_PARAM]
  const record = readChangeLogRecordRouteParam(recordParam)
  const tableSection = useMemo(
    () => context.scopes.pageDataSignal(CHANGE_LOG_TABLE_SECTION),
    [context],
  )
  const table = readHilosChangeLogTable(useSignal(tableSection))
  const journaled = table?.journaled === true
  const tablePath = router.resolvePath(HilosPages.CHANGE_LOG_TABLE_HISTORY, {
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
    return router.resolvePath(HilosPages.CHANGE_LOG_TABLE_HISTORY, {
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

  const overviewPath = router.resolvePath(HilosPages.CHANGE_LOG_TABLE, {
    [CHANGE_LOG_TABLE_ROUTE_PARAM]: tableId,
  })
  return (
    <HilosAdminPage page={HilosPages.CHANGE_LOG_TABLE_HISTORY}>
      <p>
        {overviewPath !== undefined && (
          <HilosLink
            to={overviewPath}
            data-id="hilos-change-log-table-overview"
          >
            Overview
          </HilosLink>
        )}
      </p>{' '}
      {table !== null && !table.journaled && (
        <p className="alert alert-secondary small py-2">{copy.untracked}</p>
      )}
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
                formatChangeLogHistoryChanges(row).map((line, index) => (
                  <div key={index} className="small text-break">
                    {line}
                  </div>
                )),
              [CHANGE_LOG_HISTORY_ACTOR_LABEL_FIELD]: (row) => {
                const who = formatChangeLogWho(row)
                const onBehalfOf = formatChangeLogOnBehalfOf(row)
                const channel = changeLogChannelBadge(row.channel)
                return (
                  <>
                    <span
                      className={
                        row.actorDeleted ? 'text-body-secondary' : undefined
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
      <HilosChangeLogReceiptModal reader={reader} dialog={dialog} />
    </HilosAdminPage>
  )
}
