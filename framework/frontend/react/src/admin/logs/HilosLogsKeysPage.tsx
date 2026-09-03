// HilosLogsKeysPage — the framework Hilos by-key page (HilosPages.LOGS_KEYS):
// which log streams the installation has, what each of them weighs, and how fast it
// grows. A key is a file name that survives rotation, and a row is one key ON ONE
// NODE — the same worker-0.log on two machines is two files, carried off apart — so
// the node column and the node filter exist only where nodes have names. Search, the
// node filter and the All / Agents / Workers switch ride the open viewport filter map
// (server-side, no local filtering); the window is re-served by the page whenever the
// cluster picture moves. The screen commands nothing: the only way out of it is the
// Open button into the viewer (HIL-388). The monopolistic workers are folded in with
// the ordinary ones here, and the daemon's own streams are not in the list at all.
// All table logic, the row view-model, the empty-state discrimination and the wording
// are the core headless's (hilosLogKeys); this view owns only the markup, so a project
// mounts it by passing its HilosLogKeysContext. Bootstrap classes only
// (styling-rules.md).
import { useEffect, useMemo, useState } from 'react'
import {
  HILOS_LOG_CLASS_OPTIONS,
  HILOS_PAGE_ROUTES,
  HilosPages,
  KEY_BATCH_COUNT_FIELD,
  KEY_BYTES_FIELD,
  KEY_FILTER_CLASS,
  KEY_FILTER_NODE,
  KEY_GROWTH_PER_DAY_FIELD,
  KEY_NAME_FIELD,
  KEY_NODE_FIELD,
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
import type {
  HilosLogKeyRow,
  HilosLogKeysContext,
  HilosTableColumn,
} from '@hilos/core'

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
 * The sortable keys are the exported wire constants, which is where a typo would
 * actually cost something — they travel to the backend as the sort field. The
 * growth sorts under its own displayed name: the backend maps that name onto the
 * integer it orders by, so a stream nothing is known about sinks to the bottom of a
 * descending sort rather than opening it.
 *
 * The node, weight and growth columns drop out of the header below `lg`, where their
 * values move into the sub-line of the key cell: a narrow screen gets a shorter
 * table rather than one that scrolls sideways.
 *
 * @param clustered Whether this installation names its nodes.
 */
function keyColumns(clustered: boolean): HilosTableColumn[] {
  return [
    { key: KEY_NAME_FIELD, label: 'Key', sortable: true },
    ...(clustered
      ? [
          {
            key: KEY_NODE_FIELD,
            label: 'Node',
            sortable: true,
            headerClass: 'd-none d-lg-table-cell',
          },
        ]
      : []),
    { key: 'class', label: 'Class' },
    { key: 'state', label: 'State' },
    {
      key: KEY_BATCH_COUNT_FIELD,
      label: 'Batches',
      sortable: true,
      headerClass: 'text-end',
    },
    {
      key: KEY_BYTES_FIELD,
      label: 'Weight',
      sortable: true,
      headerClass: 'text-end d-none d-lg-table-cell',
    },
    {
      key: KEY_GROWTH_PER_DAY_FIELD,
      label: 'Per day',
      sortable: true,
      headerClass: 'text-end d-none d-lg-table-cell',
    },
    { key: 'open', label: '' },
  ]
}

/**
 * A stream still being written is the live one; one left only in the archive is
 * quiet, and the two are told apart by weight of color rather than by wording alone.
 *
 * @param row The stream the badge speaks for.
 */
function stateClass(row: HilosLogKeyRow): string {
  return row.live ? 'text-bg-success' : 'text-bg-light border'
}

/**
 * The framework by-key screen: the windowed stream table with its node and class
 * filters and its four empty states.
 *
 * @param props The project context (scope stores + the connection).
 */
export function HilosLogsKeysPage({ context }: HilosLogsKeysPageProps) {
  const streams = useMemo(() => createHilosLogKeysTable(context), [context])
  const streamsTable = streams.controller
  const headerHandle = useMemo(
    () => createHilosLogKeysHeader(context),
    [context],
  )
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

  // The node column and the node filter exist only where nodes have names: in a
  // single-node installation a column repeating one name and a filter offering one
  // option would both be furniture for a choice that does not exist.
  const clustered = hasLogKeyNodes(header)
  const columns = keyColumns(clustered)

  // Domain filters: the node and the class ride the open filter map so the backend
  // narrows the window (no local filtering). Empty clears the filter.
  const [nodeFilter, setNodeFilter] = useState('')
  const [classFilter, setClassFilter] = useState('')

  function setNode(value: string): void {
    setNodeFilter(value)
    streamsTable.setFilter(KEY_FILTER_NODE, value)
  }

  function setClass(value: string): void {
    setClassFilter(value)
    streamsTable.setFilter(KEY_FILTER_CLASS, value)
  }

  function clearFilters(): void {
    streamsTable.setSearch('')
    setNode('')
    setClass('')
  }

  // Which of the four empty states the screen is in — the discrimination is the
  // headless's, because it is the same question in all three view frameworks.
  const emptyState = logKeysEmptyState(
    header,
    rows.length,
    search !== '' || nodeFilter !== '' || classFilter !== '',
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

      <div className="d-flex flex-wrap align-items-end gap-2 mb-3">
        {clustered ? (
          <div>
            <label className="form-label" htmlFor="hilos-log-key-node">
              Node
            </label>
            <select
              id="hilos-log-key-node"
              className="form-select"
              value={nodeFilter}
              data-id="hilos-log-key-node"
              onChange={(event) => setNode(event.target.value)}
            >
              <option value="">All nodes</option>
              {(header?.nodes ?? []).map((node) => (
                <option key={node} value={node}>
                  {node}
                </option>
              ))}
            </select>
          </div>
        ) : null}
        <div
          className="btn-group btn-group-sm"
          role="group"
          aria-label="Stream class"
        >
          {HILOS_LOG_CLASS_OPTIONS.map((option) => (
            <button
              key={option.value}
              type="button"
              className={`btn btn-outline-secondary${classFilter === option.value ? ' active' : ''}`}
              aria-pressed={classFilter === option.value}
              data-id={`hilos-log-key-class-${option.value || 'all'}`}
              onClick={() => setClass(option.value)}
            >
              {option.label}
            </button>
          ))}
        </div>
      </div>

      <HilosViewportTable
        label="Log streams"
        controller={streamsTable}
        columns={columns}
        searchable
        searchPlaceholder="Search by key or node…"
        row={(row: HilosLogKeyRow) => (
          <>
            <td>
              <code className="fw-semibold small">{row.key}</code>
              {/* The sub-line carries whatever the hidden columns were carrying, so a
              narrow screen loses the layout and not the figures. It is there in a
              single-node installation too, where only the weight and the growth were
              hidden. */}
              <div className="small text-body-secondary d-lg-none">
                {clustered ? `${row.node} · ` : null}
                {formatLogKeyWeight(row)} · {formatLogKeyGrowth(row)}
              </div>
            </td>
            {clustered ? (
              <td className="d-none d-lg-table-cell">{row.node}</td>
            ) : null}
            <td>
              <span className="badge text-bg-light border">
                {formatLogKeyClass(row)}
              </span>
            </td>
            <td>
              <span className={`badge ${stateClass(row)}`}>
                {formatLogKeyState(row)}
              </span>
            </td>
            <td className="text-end">{row.batchCount}</td>
            <td className="text-end d-none d-lg-table-cell">
              {formatLogKeyWeight(row)}
            </td>
            <td className="text-end d-none d-lg-table-cell">
              {formatLogKeyGrowth(row)}
            </td>
            <td className="text-end">
              {/* A stream that is neither live nor archived has no file to open, and
              the headless answers with an empty address rather than a broken one. */}
              {logKeyViewerPath(row) !== '' ? (
                <HilosLink
                  to={logKeyViewerPath(row)}
                  className="btn btn-sm btn-outline-secondary text-nowrap"
                  data-id={`hilos-log-key-open-${row.rowKey}`}
                >
                  Open
                </HilosLink>
              ) : null}
            </td>
          </>
        )}
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
                No node could read its log store. Check the log directory
                setting and the permissions on it.
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
