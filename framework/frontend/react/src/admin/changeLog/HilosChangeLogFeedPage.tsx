import { useContext, useEffect, useMemo } from 'react'
import {
  CHANGE_LOG_FEED_ACTION_FIELD,
  CHANGE_LOG_FEED_ACTOR_LABEL_FIELD,
  CHANGE_LOG_FEED_CREATED_AT_FIELD,
  CHANGE_LOG_FEED_TOUCHED_FIELD,
  CHANGE_LOG_NO_RECEIPT_TEXT,
  CHANGE_LOG_ON_BEHALF_OF_TEXT,
  CHANGE_LOG_OPEN_TABLES_LABEL,
  CHANGE_LOG_OVERVIEW_SECTION,
  changeLogChannelBadge,
  changeLogFeedEmpty,
  createHilosChangeLogFeedTable,
  createHilosChangeLogReceiptReader,
  formatChangeLogFeedOpenLabel,
  formatChangeLogOnBehalfOf,
  formatChangeLogTime,
  formatChangeLogTouched,
  formatChangeLogWho,
  readHilosChangeLogOverview,
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
  HilosChangeLogFeedRow,
  HilosChangeLogReceiptContext,
} from '@hilos/core/admin/change-log'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosHiddenMark } from '../../HilosHiddenMark.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'
import { HilosChangeLogReceiptModal } from './HilosChangeLogReceiptModal.js'

/** Props for {@link HilosChangeLogFeedPage}. */
export interface HilosChangeLogFeedPageProps {
  /** The project's connection, page scope, and shared tracked actions. */
  context: HilosChangeLogReceiptContext
}

/**
 * The Change Log feed and its intent-driven receipt reader.
 *
 * @param props The project context (connection, page scope, tracked actions).
 */
export function HilosChangeLogFeedPage({
  context,
}: HilosChangeLogFeedPageProps) {
  const router = useContext(HilosRouterContext)
  useSignal(hilosTimeZone)
  const overviewSection = useMemo(
    () => context.scopes.pageDataSignal(CHANGE_LOG_OVERVIEW_SECTION),
    [context],
  )
  const overview = readHilosChangeLogOverview(useSignal(overviewSection))
  const empty = changeLogFeedEmpty(overview)
  const tablesPath = router?.resolvePath(HilosPages.CHANGE_LOG_TABLES, {})
  // The core calls the getter itself, so it reads the section signal rather
  // than this render's overview: a snapshot would freeze the table filter on
  // the first answer.
  const feed = useMemo(
    () =>
      createHilosChangeLogFeedTable(context, {
        tables: () =>
          readHilosChangeLogOverview(overviewSection.get())?.journaledTables ??
          [],
      }),
    [context, overviewSection],
  )
  const reader = useMemo(
    () => createHilosChangeLogReceiptReader(context),
    [context],
  )
  const dialog = useSignal(reader.dialog)

  useEffect(() => {
    feed.start()
    return () => feed.dispose()
  }, [feed])

  useEffect(() => {
    const offLeave = router?.onLeave(() => reader.close())
    const offIdentity = router
      ? subscribeSignal(router.pageIdentity, () => reader.close())
      : undefined
    // A re-decided page replaces its scope even when the address stays the
    // same. Discard attribution synchronously, before a viewer can render the
    // new scope.
    const offScope = subscribeSignal(
      computedSignal(() => context.scopes.page()),
      () => reader.close(),
    )
    return () => {
      offLeave?.()
      offIdentity?.()
      offScope()
      // Closed, not disposed: StrictMode runs this cleanup and the effect again
      // over the same memoized reader, and a disposed reader never opens again.
      // Unmounted, nothing opens it any more, and closing already drops the
      // snapshot and every reply still in flight.
      reader.close()
    }
  }, [context, router, reader])

  /** Open only the address carried by the selected feed row. */
  function openDetails(row: HilosChangeLogFeedRow): void {
    if (row.kind === 'receipt' && row.receiptId !== null) {
      void reader.openReceipt(row.receiptId)
    } else if (row.kind === 'entry' && row.entryId !== null) {
      void reader.openEntry(row.entryId)
    }
  }

  return (
    <HilosAdminPage page={HilosPages.CHANGE_LOG_FEED}>
      <HilosViewportTable
        controller={feed.controller}
        cells={{
          [CHANGE_LOG_FEED_CREATED_AT_FIELD]: (row) =>
            formatChangeLogTime(row.createdAt),
          [CHANGE_LOG_FEED_ACTOR_LABEL_FIELD]: (row) => {
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
          [CHANGE_LOG_FEED_ACTION_FIELD]: (row) =>
            row.receiptId !== null ? (
              <code>{row.action ?? '—'}</code>
            ) : (
              <span className="text-body-secondary small">
                {CHANGE_LOG_NO_RECEIPT_TEXT}
              </span>
            ),
          [CHANGE_LOG_FEED_TOUCHED_FIELD]: (row) => (
            <code className="small text-body-secondary">
              {formatChangeLogTouched(row.touched)}
            </code>
          ),
          [HILOS_TABLE_ACTIONS_KEY]: (row) => (
            <button
              type="button"
              className="btn btn-sm btn-outline-secondary text-wrap"
              data-id={`hilos-change-log-open-${row.rowKey}`}
              data-hilos-focus-return="change-log-open"
              onClick={() => openDetails(row)}
            >
              {formatChangeLogFeedOpenLabel(row)}
            </button>
          ),
        }}
        empty={
          <div data-id="hilos-change-log-feed-empty" data-state={empty.kind}>
            <i
              className="bi bi-inbox fs-1 text-body-secondary mb-2 d-block"
              aria-hidden="true"
            />
            <div className="fw-semibold small mb-1">{empty.title}</div>
            <p className="small text-body-secondary mb-3">{empty.hint}</p>
            {empty.kind === 'untracked' && tablesPath !== undefined && (
              <HilosLink
                to={tablesPath}
                className="btn btn-sm btn-outline-secondary"
                data-id="hilos-change-log-open-tables"
              >
                {CHANGE_LOG_OPEN_TABLES_LABEL}
              </HilosLink>
            )}
          </div>
        }
      />
      <HilosChangeLogReceiptModal reader={reader} dialog={dialog} />
    </HilosAdminPage>
  )
}
