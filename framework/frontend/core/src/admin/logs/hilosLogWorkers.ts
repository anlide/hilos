// The framework Hilos log-workers admin headless: the stream view-model, the row
// resolver, the screen header, the four empty states, the way into the viewer, and
// the table factory the per-framework HilosLogsWorkersPage view renders from. It is
// framework-agnostic (imports no UI framework) and reads only @hilos/core
// primitives, so the Vue/React/Angular by-worker views stay thin
// (multiframework-core.md).
//
// A row is one worker stream ON ONE NODE: the same worker-0.log on two nodes is two
// files on two machines. The rows ride the ordinary server-windowed table (there are
// no live per-row deltas — the backend projects them from a mirror of the cluster
// picture, which raises no source events), and whether there is a picture at all and
// which nodes exist ride the page's own header signal.
//
// The screen holds the one distinction the by-key screen folds away: the monopolistic
// worker against the ordinary ones. That is the whole reason it exists — when a log
// has grown, "all the workers grew" and "the single worker holding the shared work
// grew" have different causes and different cures.

import { z } from 'zod'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { HilosPages } from '../../routing/hilosPages.js'
import {
  readBoolean,
  readNumber,
  readNumberOrNull,
  readString,
  readStringOrNull,
} from '../../state/fieldReaders.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { createSignal, type ReadonlySignal } from '../../state/signal.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import { LOG_SOURCE_LIVE, logViewerPath } from './hilosLogViewer.js'

/** One row of the log-workers table — one worker stream on one node. */
export interface HilosLogWorkerRow {
  /** `<node>:<key>`, and also the table row key. */
  readonly rowKey: string
  /** File basename of the stream, the name that survives rotation. */
  readonly key: string
  /** Cluster node the file lives on, or null in a single-node installation. */
  readonly node: string | null
  /** Worker kind: {@link HILOS_LOG_WORKER_TYPE_MONOPOLISTIC} or {@link HILOS_LOG_WORKER_TYPE_REGULAR}. */
  readonly type: string
  /** Whether the stream is still being written, or only left in the archive. */
  readonly live: boolean
  /** Archived batches the stream occurs in. */
  readonly batchCount: number
  /** Newest batch holding the stream, or null when it has never been rotated. */
  readonly lastBatchAt: number | null
  /** Weight of the live file and every archived occurrence together. */
  readonly bytes: number
}

// Wire keys: the framework log-workers table and its single inline `stream` slot.
// A project binds its backend to these keys (Hilos::TABLES / PAGE_TABLES).
const WORKERS_TABLE = 'hilosLogWorkers'
const WORKER_SLOT = 'stream'
const WORKERS_PAGE_SIZE = 25

/** Server→client signal `type` carrying the screen header (PHP `SUBSCRIPTION_PAGE_HILOS_LOGS_WORKERS`). */
export const WORKERS_HEADER_SIGNAL = 'subscription_page_hilos_logs_workers'

// Row payload keys of the stream slot. They are declared here because this module
// owns the view-model they resolve into, and exported where a view also names them
// as a column key, so the wire name has one owner instead of a copy per view.

/** Row payload key of the stream name (also the key column key). */
export const WORKER_NAME_FIELD = 'key'

/** Row payload key of the node the file lives on (also the node column key). */
export const WORKER_NODE_FIELD = 'node'

/** Row payload key of the worker kind. */
const WORKER_TYPE_FIELD = 'type'

/** Row payload key of the "still being written" flag. */
const WORKER_LIVE_FIELD = 'live'

/** Row payload key of the archived batch count (also the batches column key). */
export const WORKER_BATCH_COUNT_FIELD = 'batchCount'

/** Row payload key of the newest batch the stream occurs in. */
const WORKER_LAST_BATCH_AT_FIELD = 'lastBatchAt'

/** Row payload key of the stream weight (also the weight column key and the default sort). */
export const WORKER_BYTES_FIELD = 'bytes'

/** Filter-map key: narrow the streams to one node (absent in a single-node installation). */
export const WORKER_FILTER_NODE = 'node'

/** Filter-map key: narrow the streams to the monopolistic worker. */
export const WORKER_FILTER_TYPE = 'type'

/** Worker kind: the stream of the worker that holds work no two hands may do. */
export const HILOS_LOG_WORKER_TYPE_MONOPOLISTIC = 'monopolistic'

/** Worker kind: the stream of an ordinary worker, of which there may be any number. */
export const HILOS_LOG_WORKER_TYPE_REGULAR = 'regular'

/**
 * Payload of the screen header: whether there is a picture and which nodes it holds
 * (PHP `HilosLogsWorkersSignalData`).
 */
const workersHeaderSchema = z.looseObject({
  available: z.boolean().nullable(),
  nodes: z.array(z.string()),
})

/** The screen header as the page answers a subscription with it. */
export type HilosLogWorkersHeader = z.infer<typeof workersHeaderSchema>

/**
 * The by-worker signal schemas keyed for a connection's `projectSchemas`, so the
 * parse boundary validates the header frames {@link createHilosLogWorkersHeader}
 * ingests. {@link createHilosConnection} merges them in, so a project never restates
 * them.
 *
 * A set of its own rather than an entry in the by-key one: two independent screens
 * must not depend on which of them landed first.
 */
export const LOGS_WORKERS_SIGNAL_SCHEMAS = {
  [WORKERS_HEADER_SIGNAL]: workersHeaderSchema,
}

/**
 * The project-supplied context the log-workers admin reads from: the
 * scope-partitioned stores that own the page-scoped stream table, and the live
 * connection the table sends its viewport over and the header arrives on. Everything
 * else (the table key, slot name, view-model, filters, and wording) is the
 * framework's.
 */
export interface HilosLogWorkersContext {
  /** The connection the table sends its viewport over and the header arrives on. */
  readonly connection: HilosConnection
  /** The scope manager owning the page scope the table window normalizes into. */
  readonly scopes: ScopeManager
}

/** Read a row slot as an inline record, or undefined when it is not one. */
function recordSlot(slot: unknown): Record<string, unknown> | undefined {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : undefined
}

/**
 * Resolve one raw log-workers table row into its view-model. The stream fields ride a
 * single inline `stream` slot (no entity reference — a stream is page-scoped and has
 * no row of its own anywhere), so this reads the slot as a plain record.
 *
 * @param row The raw table row from the page-scoped table store.
 */
export function resolveHilosLogWorkerRow(row: TableRow): HilosLogWorkerRow {
  const slot = recordSlot(row.slots[WORKER_SLOT]) ?? {}

  return {
    // Identity is the fragment's row key. It must not travel inside the slot: a slot
    // payload carrying `id` is ingested as an entity fragment and replaced by a
    // reference, which would strip every other field off the row (normalizer.ts).
    rowKey: String(row.rowKey),
    key: readString(slot, WORKER_NAME_FIELD),
    // Null is the single-node installation and not a missing name, which is why the
    // node reads as nullable here and the column disappears rather than emptying.
    node: readStringOrNull(slot, WORKER_NODE_FIELD),
    type: readString(slot, WORKER_TYPE_FIELD),
    live: readBoolean(slot, WORKER_LIVE_FIELD),
    batchCount: readNumber(slot, WORKER_BATCH_COUNT_FIELD),
    lastBatchAt: readNumberOrNull(slot, WORKER_LAST_BATCH_AT_FIELD),
    bytes: readNumber(slot, WORKER_BYTES_FIELD),
  }
}

/** The log-workers table handle a view drives: the controller plus its mount lifecycle. */
export interface HilosLogWorkersTable {
  /** The server-windowed controller the view renders rows, descriptor, and filters from. */
  readonly controller: TableViewportController<HilosLogWorkerRow>
  /** Bind the table to the connection and request the first window — call on mount. */
  start(): void
  /** Unbind from the connection — call on unmount. */
  dispose(): void
}

/**
 * The server-windowed controller for the worker-stream list: search, the node and
 * type filters, sort, and paging change the viewport descriptor sent over the
 * connection, and the backend replies a window plus the total count scoped to the
 * table's (page, tableKey) address. Rows resolve through
 * {@link resolveHilosLogWorkerRow}. Heaviest stream first by default, because that is
 * the question the screen is opened with.
 *
 * There are no live deltas here, and the window is not re-requested by the client
 * either: the page re-serves it whenever the cluster picture moves, over this same
 * descriptor. The returned handle's `start` binds the table and requests the first
 * window; `dispose` unbinds it.
 *
 * @param context The project context (connection and scope stores).
 * @param initialFilter The initial filter map, or none.
 */
export function createHilosLogWorkersTable(
  context: HilosLogWorkersContext,
  initialFilter?: Record<string, unknown>,
): HilosLogWorkersTable {
  const controller = new TableViewportController<HilosLogWorkerRow>({
    resolve: resolveHilosLogWorkerRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.LOGS_WORKERS,
        WORKERS_TABLE,
        descriptor,
      ),
    pageSize: WORKERS_PAGE_SIZE,
    initialFilter,
    initialSort: { field: WORKER_BYTES_FIELD, direction: 'desc' },
  })
  const teardown: Array<() => void> = []

  return {
    controller,
    start() {
      teardown.push(
        bindTableViewport(
          context.connection,
          context.scopes,
          { page: HilosPages.LOGS_WORKERS, tableKey: WORKERS_TABLE },
          controller,
        ),
        // Re-request the window whenever the socket (re)connects: the initial
        // request below can run before the connection is open, and a reconnect is a
        // fresh exchange that no longer remembers this connection's window.
        context.connection.on('state', (state) => {
          if (state === 'connected') {
            controller.start()
          }
        }),
      )
      controller.start()
    },
    dispose() {
      for (const off of teardown.splice(0)) {
        off()
      }
    },
  }
}

/** The screen header a by-worker view renders its node filter and empty states from. */
export interface HilosLogWorkersHeaderHandle {
  /** The latest header this connection was sent, or null until the first one arrives. */
  readonly header: ReadonlySignal<HilosLogWorkersHeader | null>
  /** Start listening for header frames — call on mount. */
  start(): void
  /** Stop listening — call on unmount. */
  dispose(): void
}

/**
 * The screen header, reactively: the answer to the subscription and every later push
 * the page makes when the picture moves.
 *
 * It rides the connection rather than the page scope because it is the page's own
 * signal, sent ahead of the frame that releases the page and again on the agent's
 * tick — the same channel the overview's figures travel by.
 *
 * @param context The project context (the connection the frames arrive on).
 */
export function createHilosLogWorkersHeader(
  context: HilosLogWorkersContext,
): HilosLogWorkersHeaderHandle {
  const header = createSignal<HilosLogWorkersHeader | null>(null)
  const teardown: Array<() => void> = []

  return {
    header,
    start() {
      teardown.push(
        context.connection.on('projectSignal', (signal) => {
          if (signal.type === WORKERS_HEADER_SIGNAL) {
            // Validated against workersHeaderSchema at the parse boundary; this cast
            // is the declared typed selector for that schema's output.
            header.set(signal.data as HilosLogWorkersHeader)
          }
        }),
      )
    },
    dispose() {
      for (const off of teardown.splice(0)) {
        off()
      }
    },
  }
}

/**
 * Whether this installation names its nodes, which is what decides the node column,
 * the node filter and which wording the footnote carries.
 *
 * An empty list is the single-node installation, where the whole idea of a node is
 * absent: a column of one repeated name and a filter with one option would both be
 * furniture for a choice that does not exist. A header that has not arrived reads
 * the same way — the screen starts without the column rather than flashing one that
 * is about to go.
 *
 * @param header The latest header, or null before the first one arrives.
 */
export function hasLogWorkerNodes(
  header: HilosLogWorkersHeader | null,
): boolean {
  return header !== null && header.nodes.length > 0
}

/** What the worker-stream list has to say instead of rows. */
export type HilosLogWorkersEmptyState =
  /** Rows to show, so no empty state at all. */
  | 'rows'
  /** No merged picture has arrived; the figures are unknown rather than zero. */
  | 'unknown'
  /** The picture arrived and no node could read its log store. */
  | 'unreadable'
  /** The search or a filter matched nothing, though there are streams. */
  | 'nomatch'
  /** There are no streams at all — nothing has been written yet. */
  | 'never'

/**
 * Which of the four empty states the screen is in, or that it has rows.
 *
 * Four and not one, because they are cured differently and only one of them is a
 * fault: waiting on the aggregator, a log directory nobody can read, a filter that
 * matched nothing, and an installation that has simply never written a log. Zeros
 * are shown for none of them — a zero claims a measurement that was never taken.
 *
 * The order matters: the states of the PICTURE outrank the states of the window,
 * because a window built on no picture is empty for a reason that has nothing to do
 * with what was searched for.
 *
 * @param header The latest header, or null before the first one arrives.
 * @param rowCount How many rows the current window holds.
 * @param filtered Whether a search or a filter is narrowing the list.
 */
export function logWorkersEmptyState(
  header: HilosLogWorkersHeader | null,
  rowCount: number,
  filtered: boolean,
): HilosLogWorkersEmptyState {
  if (header === null || header.available === null) {
    return 'unknown'
  }
  if (!header.available) {
    return 'unreadable'
  }
  if (rowCount > 0) {
    return 'rows'
  }

  return filtered ? 'nomatch' : 'never'
}

/** A selectable worker kind: its wire value and a human-readable label. */
export interface HilosLogWorkerTypeOption {
  /** The wire value sent as the type filter (empty clears the filter). */
  readonly value: string
  /** The label shown in the type switch. */
  readonly label: string
}

/**
 * The two choices the type switch offers. The empty value is "everything" and clears
 * the filter; there is no third choice for the ordinary workers, because "all" and
 * "only the monopolistic one" are the two questions this screen is opened with, and
 * the backend narrows on the monopolistic value alone.
 */
export const HILOS_LOG_WORKER_TYPE_OPTIONS: readonly HilosLogWorkerTypeOption[] =
  [
    { value: '', label: 'All' },
    { value: HILOS_LOG_WORKER_TYPE_MONOPOLISTIC, label: 'Monopolistic only' },
  ]

/**
 * The label of one type badge.
 *
 * A kind this build does not know is printed as it arrived rather than folded into
 * one of the two: a name that turns up here is news rather than a mistake to hide.
 *
 * @param row The stream row to label.
 */
export function formatLogWorkerType(row: HilosLogWorkerRow): string {
  switch (row.type) {
    case HILOS_LOG_WORKER_TYPE_MONOPOLISTIC:
      return 'Monopolistic'
    case HILOS_LOG_WORKER_TYPE_REGULAR:
      return 'Ordinary'
    default:
      return row.type
  }
}

/**
 * The label of one state badge: whether the stream is still being written.
 *
 * @param row The stream row to label.
 */
export function formatLogWorkerState(row: HilosLogWorkerRow): string {
  return row.live ? 'Writing' : 'Archive only'
}

/**
 * The weight of one stream, in the largest unit that leaves a readable number.
 *
 * A stream of zero bytes prints as `0 B` and not as a dash: the file exists and was
 * measured, and its answer is zero — where a dash means "not known".
 *
 * @param row The stream row to format.
 */
export function formatLogWorkerWeight(row: HilosLogWorkerRow): string {
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let size = row.bytes
  let unit = 0
  while (size >= 1024 && unit < units.length - 1) {
    size /= 1024
    unit += 1
  }

  return `${unit === 0 ? size : size.toFixed(1)} ${units[unit]}`
}

/**
 * The address the Open button leads to: this stream, on this node, in the viewer.
 *
 * A live stream opens on the live file; a stream that is only in the archive opens
 * on its newest batch, because the live file it would otherwise be sent to is a file
 * that is no longer there. A stream that is neither — no live file and no batch —
 * cannot be opened at all, and the empty address is how a view is told to leave the
 * button out.
 *
 * @param row The stream row the button belongs to.
 */
export function logWorkerViewerPath(row: HilosLogWorkerRow): string {
  const source = row.live ? LOG_SOURCE_LIVE : row.lastBatchAt
  if (source === null) {
    return ''
  }

  return logViewerPath({
    // The empty node is the single-node installation, which the viewer's own address
    // builder turns into the dash segment; null here means the same thing.
    nodeId: row.node ?? '',
    source,
    stream: row.key,
  })
}
