// HilosLogsWorkersPage draws the by-worker stream list. The core frame owns the
// shared bar, columns, cards, and footer; this page supplies cells and its four
// empty states. The header names nodes reactively, and Open leads to the stream viewer.
import { useEffect, useMemo } from 'react'
import {
  HILOS_LOG_WORKER_TYPE_MONOPOLISTIC,
  HilosPages,
  createHilosLogWorkersHeader,
  createHilosLogWorkersTable,
  formatLogWorkerState,
  formatLogWorkerType,
  formatLogWorkerWeight,
  hasLogWorkerNodes,
  logWorkerViewerPath,
  logWorkersEmptyState,
} from '@hilos/core'
import type { HilosLogWorkerRow, HilosLogWorkersContext } from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { useSignal } from '../../useSignal.js'

/** Props for {@link HilosLogsWorkersPage}. */
export interface HilosLogsWorkersPageProps {
  /** The project context: scope stores and the connection. */
  context: HilosLogWorkersContext
}

/**
 * The monopolistic worker is the one this screen was opened for, so its badge is the
 * one that carries color; the ordinary ones stay quiet.
 *
 * @param row The worker stream the badge speaks for.
 */
function typeClass(row: HilosLogWorkerRow): string {
  return row.type === HILOS_LOG_WORKER_TYPE_MONOPOLISTIC
    ? 'text-bg-info-subtle text-info-emphasis border border-info-subtle'
    : 'text-bg-light border'
}

/**
 * A stream still being written is the live one; one left only in the archive is
 * quiet, and the two are told apart by weight of color rather than by wording alone.
 *
 * @param row The worker stream the badge speaks for.
 */
function stateClass(row: HilosLogWorkerRow): string {
  return row.live ? 'text-bg-success' : 'text-bg-light border'
}

/**
 * The framework by-worker screen: the windowed worker-stream table with its node
 * and type filters and its four empty states.
 *
 * @param props The project context (scope stores + the connection).
 */
export function HilosLogsWorkersPage({ context }: HilosLogsWorkersPageProps) {
  const headerHandle = useMemo(
    () => createHilosLogWorkersHeader(context),
    [context],
  )
  const streams = useMemo(
    () => createHilosLogWorkersTable(context, headerHandle.header),
    [context, headerHandle],
  )
  const streamsTable = streams.controller
  const header = useSignal(headerHandle.header)

  // Bind the server-windowed table and start listening for the header on mount; the
  // header also arrives once as the answer to the subscription.
  useEffect(() => {
    headerHandle.start()
    streams.start()

    return () => {
      streams.dispose()
      headerHandle.dispose()
    }
  }, [headerHandle, streams])

  const rows = useSignal(streamsTable.rows)
  const search = useSignal(streamsTable.search)
  const filter = useSignal(streamsTable.filter)

  // The node column, the node filter and the footnote's wording all follow the same
  // question: in a single-node installation a column repeating one name and a filter
  // offering one option would both be furniture for a choice that does not exist.
  const clustered = hasLogWorkerNodes(header)
  function clearFilters(): void {
    streamsTable.resetFilters()
  }

  // Which of the four empty states the screen is in — the discrimination is the
  // headless's, because it is the same question in all three view frameworks.
  const emptyState = logWorkersEmptyState(
    header,
    rows.length,
    search !== '' || Object.keys(filter).length > 0,
  )

  return (
    <HilosAdminPage page={HilosPages.LOGS_WORKERS}>
      <p className="text-body-secondary">
        The same thing again, but only for the workers and with the distinction
        the by-key page deliberately loses: an ordinary worker or the
        monopolistic one.
      </p>

      <HilosViewportTable
        controller={streamsTable}
        cells={{
          key: (row: HilosLogWorkerRow) => (
            <code className="fw-semibold small">{row.key}</code>
          ),
          node: (row: HilosLogWorkerRow) => row.node,
          type: (row: HilosLogWorkerRow) => (
            <span className={`badge ${typeClass(row)}`}>
              {formatLogWorkerType(row)}
            </span>
          ),
          live: (row: HilosLogWorkerRow) => (
            <span className={`badge ${stateClass(row)}`}>
              {formatLogWorkerState(row)}
            </span>
          ),
          batchCount: (row: HilosLogWorkerRow) => row.batchCount,
          bytes: (row: HilosLogWorkerRow) => formatLogWorkerWeight(row),
          actions: (row: HilosLogWorkerRow) =>
            logWorkerViewerPath(row) !== '' ? (
              <HilosLink
                to={logWorkerViewerPath(row)}
                className="btn btn-sm btn-outline-secondary text-nowrap"
                data-id={`hilos-log-worker-open-${row.rowKey}`}
              >
                Open
              </HilosLink>
            ) : null,
        }}
        empty={
          emptyState === 'unknown' ? (
            <div data-id="hilos-log-worker-empty-unknown">
              <div className="fw-semibold">
                The cluster picture has not arrived yet
              </div>
              <p className="mb-0">
                Nobody has reported yet, so there are no figures — not zero of
                them.
              </p>
            </div>
          ) : emptyState === 'unreadable' ? (
            <div data-id="hilos-log-worker-empty-unreadable">
              <div className="fw-semibold">
                The log directory cannot be read
              </div>
              <p className="mb-0">
                {clustered
                  ? 'No node could read its log store.'
                  : 'The log store could not be read.'}{' '}
                Check the log directory setting and the permissions on it.
              </p>
            </div>
          ) : emptyState === 'nomatch' ? (
            <div data-id="hilos-log-worker-empty-nomatch">
              <div className="fw-semibold">Nothing matches</div>
              <p className="mb-2">There are worker streams — just not these.</p>
              <button
                type="button"
                className="btn btn-sm btn-outline-secondary"
                data-id="hilos-log-worker-clear-filters"
                onClick={clearFilters}
              >
                Clear the filters
              </button>
            </div>
          ) : (
            <div data-id="hilos-log-worker-empty-never">
              <div className="fw-semibold">Nothing has been logged yet</div>
              <p className="mb-0">
                No worker has written into this directory — an installation that
                has only just come up looks exactly like this.
              </p>
            </div>
          )
        }
      />

      <div className="alert alert-secondary small py-3 mt-4 mb-0">
        <div className="fw-semibold mb-1">
          <i className="bi bi-lightbulb me-1" aria-hidden="true" />
          Why a page of its own
        </div>
        {clustered ? (
          <>
            There is one monopolistic worker for the whole cluster and any
            number of ordinary ones, and they live on different machines. When
            the log has grown, the first question is whether all the workers
            grew or only the single one holding the shared work. The node column
            shows whose machine it is happening on.
          </>
        ) : (
          <>
            The monopolistic worker holds work that cannot be done by two hands
            at once. When the log has grown, the first question is whether the
            ordinary workers grew or that one did — they have different causes
            and different cures.
          </>
        )}
      </div>
    </HilosAdminPage>
  )
}
