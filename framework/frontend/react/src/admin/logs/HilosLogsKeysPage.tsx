// HilosLogsKeysPage draws the by-key stream list. The core frame owns the shared
// bar, columns, cards, and footer; this page supplies cells and its four empty
// states. The header names nodes reactively, and Open leads to the stream viewer.
import { useEffect, useMemo } from 'react'
import {
  HILOS_PAGE_ROUTES,
  HilosPages,
  createHilosLogKeysHeader,
  createHilosLogKeysTable,
  formatLogKeyClass,
  formatLogKeyGrowth,
  formatLogKeyState,
  formatLogKeyWeight,
  hasLogKeyNodes,
  logKeyViewerPath,
  logKeysEmptyState,
} from '@hilos/core'
import type { HilosLogKeyRow, HilosLogKeysContext } from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { useSignal } from '../../useSignal.js'

/** Props for {@link HilosLogsKeysPage}. */
export interface HilosLogsKeysPageProps {
  /** The project context: scope stores and the connection. */
  context: HilosLogKeysContext
}

// Where the split this page folds away is actually shown. HIL-385 left the phrase
// as plain text because the by-worker page was still a stub; it is a screen now.
const WORKERS_PATH = HILOS_PAGE_ROUTES[HilosPages.LOGS_WORKERS]

/**
 * A stream still being written is the live one; one left only in the archive is
 * quiet, and the two are told apart by weight of color rather than by wording alone.
 *
 * @param row The stream the badge speaks for.
 */
function stateClass(row: HilosLogKeyRow): string {
  return row.live
    ? 'text-bg-success'
    : 'bg-body-tertiary text-body-secondary border'
}

/**
 * The framework by-key screen: the windowed stream table with its node and class
 * filters and its four empty states.
 *
 * @param props The project context (scope stores + the connection).
 */
export function HilosLogsKeysPage({ context }: HilosLogsKeysPageProps) {
  const headerHandle = useMemo(
    () => createHilosLogKeysHeader(context),
    [context],
  )
  const streams = useMemo(
    () => createHilosLogKeysTable(context, headerHandle.header),
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

  // The node column and the node filter exist only where nodes have names: in a
  // single-node installation a column repeating one name and a filter offering one
  // option would both be furniture for a choice that does not exist.
  const clustered = hasLogKeyNodes(header)
  function clearFilters(): void {
    streamsTable.resetFilters()
  }

  // Which of the four empty states the screen is in — the discrimination is the
  // headless's, because it is the same question in all three view frameworks.
  const emptyState = logKeysEmptyState(
    header,
    rows.length,
    search !== '' || Object.keys(filter).length > 0,
  )

  return (
    <HilosAdminPage page={HilosPages.LOGS_KEYS}>
      <p className="text-body-secondary">
        A key is the file name that survives rotation: the same stream goes on
        being written under that name into the next batch.
        {clustered ? (
          <>
            {' '}
            A row here is a key <em>on a node</em>: the same{' '}
            <code>worker-0.log</code> on two nodes is two files, carried off
            apart.
          </>
        ) : null}
      </p>

      <HilosViewportTable
        controller={streamsTable}
        cells={{
          key: (row: HilosLogKeyRow) => (
            <code className="fw-semibold small">{row.key}</code>
          ),
          node: (row: HilosLogKeyRow) => row.node,
          class: (row: HilosLogKeyRow) => (
            <span className="badge text-bg-secondary-subtle text-secondary-emphasis border">
              {formatLogKeyClass(row)}
            </span>
          ),
          live: (row: HilosLogKeyRow) => (
            <span className={`badge ${stateClass(row)}`}>
              {formatLogKeyState(row)}
            </span>
          ),
          batchCount: (row: HilosLogKeyRow) => row.batchCount,
          bytes: (row: HilosLogKeyRow) => formatLogKeyWeight(row),
          growthPerDay: (row: HilosLogKeyRow) => formatLogKeyGrowth(row),
          actions: (row: HilosLogKeyRow) =>
            logKeyViewerPath(row) !== '' ? (
              <HilosLink
                to={logKeyViewerPath(row)}
                className="btn btn-sm btn-outline-secondary text-nowrap"
                data-id={`hilos-log-key-open-${row.rowKey}`}
              >
                Open
              </HilosLink>
            ) : null,
        }}
        empty={
          emptyState === 'unknown' ? (
            <div data-id="hilos-log-key-empty-unknown">
              <div className="fw-semibold">
                The cluster picture has not arrived yet
              </div>
              <p className="mb-0">
                Nobody has reported yet, so there are no figures — not zero of
                them.
              </p>
            </div>
          ) : emptyState === 'unreadable' ? (
            <div data-id="hilos-log-key-empty-unreadable">
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
            <div data-id="hilos-log-key-empty-nomatch">
              <div className="fw-semibold">Nothing matches</div>
              <p className="mb-2">There are streams — just not these.</p>
              <button
                type="button"
                className="btn btn-sm btn-outline-secondary"
                data-id="hilos-log-key-clear-filters"
                onClick={clearFilters}
              >
                Clear the filters
              </button>
            </div>
          ) : (
            <div data-id="hilos-log-key-empty-never">
              <div className="fw-semibold">Nothing has been logged yet</div>
              <p className="mb-0">
                The daemon has not written into this directory — an installation
                that has only just come up looks exactly like this.
              </p>
            </div>
          )
        }
      />

      {/* The legend of the three classes reads no data and has no state, so it
      stands even under an empty table: it says what the files behind the class
      switch are. */}
      <section data-id="hilos-log-key-classes">
        <h2 className="h6 text-uppercase text-body-secondary mb-2 mt-4">
          Three stream classes, and there will be no fourth
        </h2>
        <p className="small text-body-secondary">
          The class is a property of which process writes the file, not of how
          the screen shows it. There are exactly three, and the All button is
          the sum of the other three.
        </p>
        <div className="row row-cols-1 row-cols-lg-3 g-3">
          <div className="col">
            <div className="border rounded-3 p-3 h-100">
              <div className="d-flex align-items-center gap-2 mb-1">
                <i
                  className="bi bi-hdd-stack text-body-secondary"
                  aria-hidden="true"
                ></i>
                <span className="badge text-bg-light border">Daemon</span>
              </div>
              <div className="small mb-2">
                <code>daemon.log</code>, <code>daemon-error.log</code> and the
                raw stream beside each
              </div>
              <p className="small text-body-secondary mb-0">
                Matched by the exact file name rather than by a prefix: the
                daemon's own streams carry no prefix at all. There are four
                files, not two — beside the one the logger writes lies a raw
                stream taking everything PHP prints past it: a fatal error, a
                warning, a trace. Rotation does not touch the raw pair; only a
                daemon restart replaces it.
              </p>
            </div>
          </div>
          <div className="col">
            <div className="border rounded-3 p-3 h-100">
              <div className="d-flex align-items-center gap-2 mb-1">
                <i
                  className="bi bi-cpu text-body-secondary"
                  aria-hidden="true"
                ></i>
                <span className="badge text-bg-light border">Agents</span>
              </div>
              <div className="small mb-2">
                <code>agent-*.log</code>
              </div>
              <p className="small text-body-secondary mb-0">
                One file per agent, the stream named after the agent type.
              </p>
            </div>
          </div>
          <div className="col">
            <div className="border rounded-3 p-3 h-100">
              <div className="d-flex align-items-center gap-2 mb-1">
                <i
                  className="bi bi-diagram-3 text-body-secondary"
                  aria-hidden="true"
                ></i>
                <span className="badge text-bg-light border">Workers</span>
              </div>
              <div className="small mb-2">
                <code>worker-*.log</code> and{' '}
                <code>worker-monopolistic-*.log</code>
              </div>
              <p className="small text-body-secondary mb-0">
                Both prefixes are folded into one class: telling ordinary
                workers from monopolistic ones is the neighbouring page's
                question, and here it would only split the list.
              </p>
            </div>
          </div>
        </div>
        <p className="small text-body-secondary mt-3 mb-0">
          The daemon's streams are where the errors that bring anyone to this
          section land. Hiding them here would keep a whole class of logs from
          the operator.
          {clustered
            ? ' Each node has its own four, and they are carried off apart.'
            : null}
        </p>
      </section>

      <p className="small text-body-secondary mt-3 mb-0">
        The weight answers "how much is taken", the growth answers "when the
        room runs out"; a stream that is no longer written has no growth.
        Monopolistic workers are folded in with the ordinary ones here — the
        split is shown by{' '}
        <HilosLink to={WORKERS_PATH} data-id="hilos-log-key-workers-link">
          the workers page
        </HilosLink>
        . Search and sorting go to the server: while it counts, the table is
        busy rather than showing the old order as the new one.
      </p>
    </HilosAdminPage>
  )
}
