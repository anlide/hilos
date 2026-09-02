// The framework Hilos log-keys admin headless: the stream view-model, the row
// resolver, the screen header, the four empty states, the way into the viewer, and
// the table factory the per-framework HilosLogsKeysPage view renders from. It is
// framework-agnostic (imports no UI framework) and reads only @hilos/core
// primitives, so the Vue/React/Angular by-key views stay thin
// (multiframework-core.md).
//
// A row is one log key ON ONE NODE: the same worker-0.log on two nodes is two files
// on two machines, carried off apart. The rows ride the ordinary server-windowed
// table (there are no live per-row deltas — the backend projects them from a mirror
// of the cluster picture, which raises no source events), and whether there is a
// picture at all and which nodes exist ride the page's own header signal.
//
// The screen answers "how much is taken, and by which stream": it starts on the
// heaviest, it commands nothing, and the daemon's own streams are not in it at all
// (the backend drops them, proposal P-218).

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

/** One row of the log-keys table — one log stream on one node. */
export interface HilosLogKeyRow {
  /** `<node>:<key>`, and also the table row key. */
  readonly rowKey: string
  /** File basename of the stream, the name that survives rotation. */
  readonly key: string
  /** Cluster node the file lives on, or null in a single-node installation. */
  readonly node: string | null
  /** Stream class: {@link HILOS_LOG_CLASS_AGENT} or {@link HILOS_LOG_CLASS_WORKER}. */
  readonly class: string
  /** Whether the stream is still being written, or only left in the archive. */
  readonly live: boolean
  /** Archived batches the stream occurs in. */
  readonly batchCount: number
  /** Newest batch holding the stream, or null when it has never been rotated. */
  readonly lastBatchAt: number | null
  /** Weight of the live file and every archived occurrence together. */
  readonly bytes: number
  /** Bytes written over the last day, or null while the measuring window fills. */
  readonly growthPerDay: number | null
}

// Wire keys: the framework log-keys table and its single inline `stream` slot.
// A project binds its backend to these keys (Hilos::TABLES / PAGE_TABLES).
const KEYS_TABLE = 'hilosLogKeys'
const KEY_SLOT = 'stream'
const KEYS_PAGE_SIZE = 25

/** Server→client signal `type` carrying the screen header (PHP `SUBSCRIPTION_PAGE_HILOS_LOGS_KEYS`). */
export const KEYS_HEADER_SIGNAL = 'subscription_page_hilos_logs_keys'

// Row payload keys of the stream slot. They are declared here because this module
// owns the view-model they resolve into, and exported where a view also names them
// as a column key, so the wire name has one owner instead of a copy per view.

/** Row payload key of the stream name (also the key column key). */
export const KEY_NAME_FIELD = 'key'

/** Row payload key of the node the file lives on (also the node column key). */
export const KEY_NODE_FIELD = 'node'

/** Row payload key of the stream class. */
const KEY_CLASS_FIELD = 'class'

/** Row payload key of the "still being written" flag. */
const KEY_LIVE_FIELD = 'live'

/** Row payload key of the archived batch count (also the batches column key). */
export const KEY_BATCH_COUNT_FIELD = 'batchCount'

/** Row payload key of the newest batch the stream occurs in. */
const KEY_LAST_BATCH_AT_FIELD = 'lastBatchAt'

/** Row payload key of the stream weight (also the weight column key and the default sort). */
export const KEY_BYTES_FIELD = 'bytes'

/**
 * Row payload key of the daily growth (also the growth column key).
 *
 * The window orders by it, and the backend maps that name onto its own integer key
 * so that a stream nothing is known about sinks to the bottom of a descending sort
 * rather than opening it. The unknown itself arrives here as null and is drawn as a
 * dash.
 */
export const KEY_GROWTH_PER_DAY_FIELD = 'growthPerDay'

/** Filter-map key: narrow the streams to one node (absent in a single-node installation). */
export const KEY_FILTER_NODE = 'node'

/** Filter-map key: narrow the streams to one class. */
export const KEY_FILTER_CLASS = 'class'

/** Stream class: a log an agent writes. */
export const HILOS_LOG_CLASS_AGENT = 'agent'

/**
 * Stream class: a log a worker writes, the monopolistic workers folded in with the
 * ordinary ones — that split is the neighbouring workers screen (HIL-386).
 */
export const HILOS_LOG_CLASS_WORKER = 'worker'

/**
 * Payload of the screen header: whether there is a picture and which nodes it holds
 * (PHP `HilosLogsKeysSignalData`).
 */
const keysHeaderSchema = z.looseObject({
  available: z.boolean().nullable(),
  nodes: z.array(z.string()),
})

/** The screen header as the page answers a subscription with it. */
export type HilosLogKeysHeader = z.infer<typeof keysHeaderSchema>

/**
 * The by-key signal schemas keyed for a connection's `projectSchemas`, so the parse
 * boundary validates the header frames {@link createHilosLogKeysHeader} ingests.
 * {@link createHilosConnection} merges them in, so a project never restates them.
 *
 * A set of its own rather than an entry in the rotations one: two independent screens
 * must not depend on which of them landed first.
 */
export const LOGS_KEYS_SIGNAL_SCHEMAS = {
  [KEYS_HEADER_SIGNAL]: keysHeaderSchema,
}

/**
 * The project-supplied context the log-keys admin reads from: the scope-partitioned
 * stores that own the page-scoped stream table, and the live connection the table
 * sends its viewport over and the header arrives on. Everything else (the table key,
 * slot name, view-model, filters, and wording) is the framework's.
 */
export interface HilosLogKeysContext {
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
 * Resolve one raw log-keys table row into its view-model. The stream fields ride a
 * single inline `stream` slot (no entity reference — a stream is page-scoped and has
 * no row of its own anywhere), so this reads the slot as a plain record.
 *
 * @param row The raw table row from the page-scoped table store.
 */
export function resolveHilosLogKeyRow(row: TableRow): HilosLogKeyRow {
  const slot = recordSlot(row.slots[KEY_SLOT]) ?? {}

  return {
    // Identity is the fragment's row key. It must not travel inside the slot: a slot
    // payload carrying `id` is ingested as an entity fragment and replaced by a
    // reference, which would strip every other field off the row (normalizer.ts).
    rowKey: String(row.rowKey),
    key: readString(slot, KEY_NAME_FIELD),
    // Null is the single-node installation and not a missing name, which is why the
    // node reads as nullable here and the column disappears rather than emptying.
    node: readStringOrNull(slot, KEY_NODE_FIELD),
    class: readString(slot, KEY_CLASS_FIELD),
    live: readBoolean(slot, KEY_LIVE_FIELD),
    batchCount: readNumber(slot, KEY_BATCH_COUNT_FIELD),
    lastBatchAt: readNumberOrNull(slot, KEY_LAST_BATCH_AT_FIELD),
    bytes: readNumber(slot, KEY_BYTES_FIELD),
    // Null is "the day is not measured yet" and is drawn as a dash: a zero would say
    // the stream is not growing, which is a measurement nobody made.
    growthPerDay: readNumberOrNull(slot, KEY_GROWTH_PER_DAY_FIELD),
  }
}

/** The log-keys table handle a view drives: the controller plus its mount lifecycle. */
export interface HilosLogKeysTable {
  /** The server-windowed controller the view renders rows, descriptor, and filters from. */
  readonly controller: TableViewportController<HilosLogKeyRow>
  /** Bind the table to the connection and request the first window — call on mount. */
  start(): void
  /** Unbind from the connection — call on unmount. */
  dispose(): void
}

/**
 * The server-windowed controller for the stream list: search, the node and class
 * filters, sort, and paging change the viewport descriptor sent over the connection,
 * and the backend replies a window plus the total count scoped to the table's (page,
 * tableKey) address. Rows resolve through {@link resolveHilosLogKeyRow}. Heaviest
 * stream first by default, because that is the question the screen is opened with.
 *
 * There are no live deltas here, and the window is not re-requested by the client
 * either: the page re-serves it whenever the cluster picture moves, over this same
 * descriptor. The returned handle's `start` binds the table and requests the first
 * window; `dispose` unbinds it.
 *
 * @param context The project context (connection and scope stores).
 * @param initialFilter The initial filter map, or none.
 */
export function createHilosLogKeysTable(
  context: HilosLogKeysContext,
  initialFilter?: Record<string, unknown>,
): HilosLogKeysTable {
  const controller = new TableViewportController<HilosLogKeyRow>({
    resolve: resolveHilosLogKeyRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.LOGS_KEYS,
        KEYS_TABLE,
        descriptor,
      ),
    pageSize: KEYS_PAGE_SIZE,
    initialFilter,
    initialSort: { field: KEY_BYTES_FIELD, direction: 'desc' },
  })
  const teardown: Array<() => void> = []

  return {
    controller,
    start() {
      teardown.push(
        bindTableViewport(
          context.connection,
          context.scopes,
          { page: HilosPages.LOGS_KEYS, tableKey: KEYS_TABLE },
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

/** The screen header a by-key view renders its node filter and empty states from. */
export interface HilosLogKeysHeaderHandle {
  /** The latest header this connection was sent, or null until the first one arrives. */
  readonly header: ReadonlySignal<HilosLogKeysHeader | null>
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
export function createHilosLogKeysHeader(
  context: HilosLogKeysContext,
): HilosLogKeysHeaderHandle {
  const header = createSignal<HilosLogKeysHeader | null>(null)
  const teardown: Array<() => void> = []

  return {
    header,
    start() {
      teardown.push(
        context.connection.on('projectSignal', (signal) => {
          if (signal.type === KEYS_HEADER_SIGNAL) {
            // Validated against keysHeaderSchema at the parse boundary; this cast is
            // the declared typed selector for that schema's output.
            header.set(signal.data as HilosLogKeysHeader)
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
 * Whether this installation names its nodes, which is what decides the node column
 * and the node filter.
 *
 * An empty list is the single-node installation, where the whole idea of a node is
 * absent: a column of one repeated name and a filter with one option would both be
 * furniture for a choice that does not exist. A header that has not arrived reads
 * the same way — the screen starts without the column rather than flashing one that
 * is about to go.
 *
 * @param header The latest header, or null before the first one arrives.
 */
export function hasLogKeyNodes(header: HilosLogKeysHeader | null): boolean {
  return header !== null && header.nodes.length > 0
}

/** What the stream list has to say instead of rows. */
export type HilosLogKeysEmptyState =
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
export function logKeysEmptyState(
  header: HilosLogKeysHeader | null,
  rowCount: number,
  filtered: boolean,
): HilosLogKeysEmptyState {
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

/** A selectable stream class: its wire value and a human-readable label. */
export interface HilosLogKeyClassOption {
  /** The wire value sent as the class filter (empty clears the filter). */
  readonly value: string
  /** The label shown in the class switch. */
  readonly label: string
}

/**
 * The three choices the class switch offers. The empty value is "everything" and
 * clears the filter; there is no fourth choice, because the daemon's own streams are
 * not on this screen at all.
 */
export const HILOS_LOG_CLASS_OPTIONS: readonly HilosLogKeyClassOption[] = [
  { value: '', label: 'All' },
  { value: HILOS_LOG_CLASS_AGENT, label: 'Agents' },
  { value: HILOS_LOG_CLASS_WORKER, label: 'Workers' },
]

/**
 * The label of one class badge.
 *
 * A class this build does not know is printed as it arrived rather than folded into
 * one of the two: a third class exists on the backend already
 * (`LogKeySummary::CLASS_DAEMON`) and is only kept off this screen by the table, so a
 * name that turns up here is news rather than a mistake to hide.
 *
 * @param row The stream row to label.
 */
export function formatLogKeyClass(row: HilosLogKeyRow): string {
  switch (row.class) {
    case HILOS_LOG_CLASS_AGENT:
      return 'Agent'
    case HILOS_LOG_CLASS_WORKER:
      return 'Worker'
    default:
      return row.class
  }
}

/**
 * The label of one state badge: whether the stream is still being written.
 *
 * @param row The stream row to label.
 */
export function formatLogKeyState(row: HilosLogKeyRow): string {
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
export function formatLogKeyWeight(row: HilosLogKeyRow): string {
  return formatLogKeyBytes(row.bytes)
}

/**
 * What the stream wrote over the last day, or a dash while nobody can say.
 *
 * The dash covers both unknowns the backend sends null for: a measuring window that
 * has not filled yet, and a stream that is no longer written at all. A zero here
 * would claim the day was measured and the stream stood still.
 *
 * @param row The stream row to format.
 */
export function formatLogKeyGrowth(row: HilosLogKeyRow): string {
  return row.growthPerDay === null ? '—' : formatLogKeyBytes(row.growthPerDay)
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
export function logKeyViewerPath(row: HilosLogKeyRow): string {
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

/**
 * A byte figure in the largest unit that leaves a readable number — the one place
 * this module turns bytes into words, for a weight and a daily growth alike.
 *
 * @param bytes The figure, in bytes.
 */
function formatLogKeyBytes(bytes: number): string {
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let size = bytes
  let unit = 0
  while (size >= 1024 && unit < units.length - 1) {
    size /= 1024
    unit += 1
  }

  return `${unit === 0 ? size : size.toFixed(1)} ${units[unit]}`
}
