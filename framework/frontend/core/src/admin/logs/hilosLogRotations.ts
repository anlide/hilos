// The framework Hilos log-rotations admin headless: the archive-history
// view-model, the row resolver, the screen header, the four empty states, and the
// table factory the per-framework HilosLogsRotationsPage view renders from. It is
// framework-agnostic (imports no UI framework) and reads only @hilos/core
// primitives, so the Vue/React/Angular rotation views stay thin
// (multiframework-core.md).
//
// A row is one archived batch ON ONE NODE: the same rotation moment on two nodes
// is two directories on two machines, carried off apart. The rows ride the ordinary
// server-windowed table (there are no live per-row deltas — the backend projects
// them from a mirror of the cluster picture, which raises no source events), and
// everything else on the screen — whether there is a picture at all, which nodes
// exist, and the rules in force — rides the page's own header signal.
//
// The screen judges nothing: the retention verdict is decided on the backend. It
// does command two things — an operator's word that a recommended batch has been
// carried off (HIL-483), and that word taken back while the batch is still there
// (HIL-759) — and even those it only forwards: both are the one durable fact
// written and removed by the node holding the directory, and the badge repaints
// when that node's next index arrives rather than when the ack does.

import { z } from 'zod'
import {
  type ActionHandle,
  type ActionLifecycle,
} from '../../connection/actionLifecycle.js'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { HilosPages } from '../../routing/hilosPages.js'
import {
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

/** One row of the log-rotations table — one archived batch on one node. */
export interface HilosLogRotationRow {
  /** `<node>:<timestamp>`, and also the table row key. */
  readonly rowKey: string
  /** Unix timestamp of the rotation the batch was written by. */
  readonly batchAt: number
  /** Cluster node holding the batch, or null in a single-node installation. */
  readonly node: string | null
  /** Archive directory of the batch, relative to that node's log root. */
  readonly path: string
  /**
   * Archive directory of the batch as addressed ON ITS OWN NODE, or null when
   * that node named no log root. It is what an operator pastes into a copy
   * command, which is the only place a machine's private layout belongs.
   */
  readonly absolutePath: string | null
  /** Agent files in the batch. */
  readonly agentFileCount: number
  /** Worker files in the batch, the monopolistic ones apart. */
  readonly workerFileCount: number
  /** Monopolistic worker files in the batch. */
  readonly workerMonopolisticFileCount: number
  /** Weight of the whole directory, every stream class included. */
  readonly bytes: number
  /** Retention verdict: one of {@link HILOS_ROTATION_STATE_KEPT} and its neighbours. */
  readonly retentionState: string
  /**
   * Unix instant this batch's own node may first prune it, or null when there is
   * no instant to name — the batch is unconfirmed, or that node's window is zero
   * and its pruner will take a confirmed batch on its very next pass.
   */
  readonly pruneNotBefore: number | null
}

// Wire keys: the framework log-rotations table and its single inline `batch` slot.
// A project binds its backend to these keys (Hilos::TABLES / PAGE_TABLES).
const ROTATIONS_TABLE = 'hilosLogRotations'
const ROTATION_SLOT = 'batch'
const ROTATIONS_PAGE_SIZE = 25

/** Server→client signal `type` carrying the screen header (PHP `SUBSCRIPTION_PAGE_HILOS_LOGS_ROTATIONS`). */
export const ROTATIONS_HEADER_SIGNAL = 'subscription_page_hilos_logs_rotations'

// Row payload keys of the batch slot. They are declared here because this module
// owns the view-model they resolve into, and exported where a view also names them
// as a column key, so the wire name has one owner instead of a copy per view.

/** Row payload key of the rotation instant (also the table's default sort field). */
export const ROTATION_BATCH_AT_FIELD = 'batchAt'

/** Row payload key of the node holding the batch (also the node column key). */
export const ROTATION_NODE_FIELD = 'node'

/** Row payload key of the archive directory. */
const ROTATION_PATH_FIELD = 'path'

/** Row payload key of the archive directory as addressed on its own node. */
const ROTATION_ABSOLUTE_PATH_FIELD = 'absolutePath'

/** Row payload key of the agent file count. */
const ROTATION_AGENT_FILE_COUNT_FIELD = 'agentFileCount'

/** Row payload key of the worker file count. */
const ROTATION_WORKER_FILE_COUNT_FIELD = 'workerFileCount'

/** Row payload key of the monopolistic worker file count. */
const ROTATION_WORKER_MONOPOLISTIC_FILE_COUNT_FIELD =
  'workerMonopolisticFileCount'

/** Row payload key of the batch weight (also the weight column key). */
export const ROTATION_BYTES_FIELD = 'bytes'

/** Row payload key of the retention verdict. */
const ROTATION_RETENTION_STATE_FIELD = 'retentionState'

/** Row payload key of the instant the batch's own node may first prune it. */
const ROTATION_PRUNE_NOT_BEFORE_FIELD = 'pruneNotBefore'

/** Filter-map key: narrow the history to one node (absent in a single-node installation). */
export const ROTATION_FILTER_NODE = 'node'

/** Filter-map key: narrow the history to one retention state. */
export const ROTATION_FILTER_STATE = 'state'

/** Retention verdict: the batch is inside what the policy protects. */
export const HILOS_ROTATION_STATE_KEPT = 'kept'

/** Retention verdict: the policy recommends carrying the batch off; also the one filter value. */
export const HILOS_ROTATION_STATE_DUE = 'due'

/**
 * Retention verdict: an operator has confirmed the batch was carried off.
 *
 * It outranks the other two rather than sitting beside them: the confirmation is a
 * durable fact on the node, while kept and due are a reading of a rule that may
 * have moved since. A batch that came back under protection while it was being
 * copied off is still a batch that was carried off.
 */
export const HILOS_ROTATION_STATE_TAKEN = 'taken'

/**
 * Where the batch is: still on its way from the staging directory into the archive.
 *
 * It outranks all three above it, the confirmation included, because it is not a
 * verdict about the batch at all — it says the batch is not in the archive yet, and
 * kept, due and taken are all readings of one that is. Nothing may be said about
 * carrying off a directory that has not arrived, so a row in this state offers
 * neither action, and the node refuses both if asked anyway.
 */
export const HILOS_ROTATION_STATE_CARRYING = 'carrying'

/**
 * Client→server action `type` confirming one batch has been carried off (PHP
 * `LOGS_TAKEOUT_CONFIRM`). It names the batch by the pair that identifies it — the
 * node and the rotation stamp — because one rotation moment on two nodes is two
 * directories and two confirmations.
 */
export const LOGS_TAKEOUT_CONFIRM_ACTION = 'logs_takeout_confirm'

/**
 * Client→server action `type` withdrawing that word while the batch is still there
 * (PHP `LOGS_TAKEOUT_UNDO`). Its own action rather than a direction on the one
 * above: the two are checked differently on the node and refuse for different
 * reasons, and it answers with a sentence and no data at all.
 */
export const LOGS_TAKEOUT_UNDO_ACTION = 'logs_takeout_undo'

/**
 * Ack of {@link LOGS_TAKEOUT_CONFIRM_ACTION}: the instant the batch is now
 * recorded as carried off, which is the one the node wrote and not the one the
 * click asked for.
 *
 * Nothing here is drawn. The row repaints when the node's next index reaches the
 * mirror, and the schema is declared so a backend that changed the shape of its
 * ack fails at the parse boundary instead of quietly acking something else.
 */
const logsTakeoutConfirmReplySchema = z.looseObject({
  takenAt: z.number(),
})

/**
 * Payload of the screen header: whether there is a picture, which nodes it holds,
 * and the rotation and retention rules actually in force (PHP
 * `HilosLogsRotationsSignalData`).
 */
const rotationsHeaderSchema = z.looseObject({
  available: z.boolean().nullable(),
  nodes: z.array(z.string()),
  rotationCron: z.string().nullable(),
  rotationMaxAgeSeconds: z.number(),
  rotationMaxLiveSizeBytes: z.number(),
  retentionKeepBatches: z.number(),
  retentionMaxAgeSeconds: z.number(),
})

/** The screen header as the page answers a subscription with it. */
export type HilosLogRotationsHeader = z.infer<typeof rotationsHeaderSchema>

/**
 * The logs signal schemas keyed for a connection's `projectSchemas`, so the parse
 * boundary validates the header frames {@link createHilosLogRotationsHeader}
 * ingests. {@link createHilosConnection} merges them in, so a project never
 * restates them.
 */
export const LOGS_SIGNAL_SCHEMAS = {
  [ROTATIONS_HEADER_SIGNAL]: rotationsHeaderSchema,
}

/**
 * The project-supplied context the log-rotations admin reads from: the
 * scope-partitioned stores that own the page-scoped history table, and the live
 * connection the table sends its viewport over and the header arrives on.
 * Everything else (the table key, slot name, view-model, filters, and wording) is
 * the framework's.
 */
export interface HilosLogRotationsContext {
  /** The connection the table sends its viewport over and the header arrives on. */
  readonly connection: HilosConnection
  /** The scope manager owning the page scope the table window normalizes into. */
  readonly scopes: ScopeManager
  /** The action lifecycle the takeout confirmation and its withdrawal dispatch over. */
  readonly actions: ActionLifecycle
}

/** Read a row slot as an inline record, or undefined when it is not one. */
function recordSlot(slot: unknown): Record<string, unknown> | undefined {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : undefined
}

/**
 * Resolve one raw rotation table row into its view-model. The batch fields ride a
 * single inline `batch` slot (no entity reference — a batch is page-scoped and has
 * no row of its own anywhere), so this reads the slot as a plain record.
 *
 * @param row The raw table row from the page-scoped table store.
 */
export function resolveHilosLogRotationRow(row: TableRow): HilosLogRotationRow {
  const slot = recordSlot(row.slots[ROTATION_SLOT]) ?? {}

  return {
    // Identity is the fragment's row key. It must not travel inside the slot: a slot
    // payload carrying `id` is ingested as an entity fragment and replaced by a
    // reference, which would strip every other field off the row (normalizer.ts).
    rowKey: String(row.rowKey),
    batchAt: readNumber(slot, ROTATION_BATCH_AT_FIELD),
    // Null is the single-node installation and not a missing name, which is why the
    // node reads as nullable here and the column disappears rather than emptying.
    node: readStringOrNull(slot, ROTATION_NODE_FIELD),
    path: readString(slot, ROTATION_PATH_FIELD),
    // Null is a node that named no log root — an older build reporting an index
    // frame without one — and not an address that happens to be blank.
    absolutePath: readStringOrNull(slot, ROTATION_ABSOLUTE_PATH_FIELD),
    agentFileCount: readNumber(slot, ROTATION_AGENT_FILE_COUNT_FIELD),
    workerFileCount: readNumber(slot, ROTATION_WORKER_FILE_COUNT_FIELD),
    workerMonopolisticFileCount: readNumber(
      slot,
      ROTATION_WORKER_MONOPOLISTIC_FILE_COUNT_FIELD,
    ),
    bytes: readNumber(slot, ROTATION_BYTES_FIELD),
    retentionState: readString(slot, ROTATION_RETENTION_STATE_FIELD),
    // Null is "there is no deadline to name", which the withdrawal modal says in
    // words rather than as a blank: an unconfirmed batch is on its way nowhere, and
    // a node whose window is zero has said it will not wait.
    pruneNotBefore: readNumberOrNull(slot, ROTATION_PRUNE_NOT_BEFORE_FIELD),
  }
}

/** The log-rotations table handle a view drives: the controller plus its mount lifecycle. */
export interface HilosLogRotationsTable {
  /** The server-windowed controller the view renders rows, descriptor, and filters from. */
  readonly controller: TableViewportController<HilosLogRotationRow>
  /** Bind the table to the connection and request the first window — call on mount. */
  start(): void
  /** Unbind from the connection — call on unmount. */
  dispose(): void
}

/**
 * The server-windowed controller for the rotation history: search, the node and
 * state filters, sort, and paging change the viewport descriptor sent over the
 * connection, and the backend replies a window plus the total count scoped to the
 * table's (page, tableKey) address. Rows resolve through
 * {@link resolveHilosLogRotationRow}. Newest batch first by default.
 *
 * There are no live deltas here, and the window is not re-requested by the client
 * either: the page re-serves it whenever the cluster picture or the retention rule
 * moves, over this same descriptor. The returned handle's `start` binds the table
 * and requests the first window; `dispose` unbinds it.
 *
 * @param context The project context (connection and scope stores).
 * @param initialFilter The initial filter map, or none.
 */
export function createHilosLogRotationsTable(
  context: HilosLogRotationsContext,
  initialFilter?: Record<string, unknown>,
): HilosLogRotationsTable {
  const controller = new TableViewportController<HilosLogRotationRow>({
    resolve: resolveHilosLogRotationRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.LOGS_ROTATIONS,
        ROTATIONS_TABLE,
        descriptor,
      ),
    pageSize: ROTATIONS_PAGE_SIZE,
    initialFilter,
    initialSort: { field: ROTATION_BATCH_AT_FIELD, direction: 'desc' },
  })
  const teardown: Array<() => void> = []

  return {
    controller,
    start() {
      teardown.push(
        bindTableViewport(
          context.connection,
          context.scopes,
          { page: HilosPages.LOGS_ROTATIONS, tableKey: ROTATIONS_TABLE },
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

/** The takeout surface a rotations view binds to — the two things this screen commands. */
export interface HilosLogRotationsActions {
  /**
   * Confirm that one batch has been carried off, as a tracked action.
   *
   * The answer is owed by the node holding the directory rather than by the page:
   * the page forwards the request and steps out of its own ack, so a refusal here
   * is the owner's sentence (the batch is gone, it is protected again, the
   * directory cannot be written to) and not this screen's guess at one.
   *
   * @param row The batch being confirmed.
   */
  sendTakeoutConfirm(row: HilosLogRotationRow): ActionHandle

  /**
   * Withdraw that word while the batch is still there, as a tracked action.
   *
   * Answered by the same owner and with no reply payload: the row returns to its
   * policy verdict when that node's next index reaches the mirror, so there is
   * nothing an ack could carry that the index does not already say. What the
   * success does carry is the node's own sentence, which the toast shows.
   *
   * @param row The batch whose confirmation is being withdrawn.
   */
  sendTakeoutUndo(row: HilosLogRotationRow): ActionHandle
}

/**
 * The takeout surface, bound to a project's action lifecycle.
 *
 * @param context The project context (the action lifecycle the confirmation dispatches over).
 */
export function createHilosLogRotationsActions(
  context: HilosLogRotationsContext,
): HilosLogRotationsActions {
  return {
    sendTakeoutConfirm(row) {
      return context.actions.dispatch(
        LOGS_TAKEOUT_CONFIRM_ACTION,
        {
          // The empty id is the wire's word for "this node", which is what a
          // single-node installation always sends: it names no nodes at all, so
          // there is no id to send and none to look up on the other side.
          nodeId: row.node ?? '',
          batchTimestamp: row.batchAt,
        },
        { replySchema: logsTakeoutConfirmReplySchema },
      )
    },
    sendTakeoutUndo(row) {
      return context.actions.dispatch(LOGS_TAKEOUT_UNDO_ACTION, {
        nodeId: row.node ?? '',
        batchTimestamp: row.batchAt,
      })
    },
  }
}

/** The screen header a rotations view renders its rule line and node filter from. */
export interface HilosLogRotationsHeaderHandle {
  /** The latest header this connection was sent, or null until the first one arrives. */
  readonly header: ReadonlySignal<HilosLogRotationsHeader | null>
  /** Start listening for header frames — call on mount. */
  start(): void
  /** Stop listening — call on unmount. */
  dispose(): void
}

/**
 * The screen header, reactively: the answer to the subscription and every later
 * push the page makes when the picture or the rule moves.
 *
 * It rides the connection rather than the page scope because it is the page's own
 * signal, sent ahead of the frame that releases the page and again on the agent's
 * tick — the same channel the overview's figures travel by.
 *
 * @param context The project context (the connection the frames arrive on).
 */
export function createHilosLogRotationsHeader(
  context: HilosLogRotationsContext,
): HilosLogRotationsHeaderHandle {
  const header = createSignal<HilosLogRotationsHeader | null>(null)
  const teardown: Array<() => void> = []

  return {
    header,
    start() {
      teardown.push(
        context.connection.on('projectSignal', (signal) => {
          if (signal.type === ROTATIONS_HEADER_SIGNAL) {
            // Validated against rotationsHeaderSchema at the parse boundary; this cast
            // is the declared typed selector for that schema's output.
            header.set(signal.data as HilosLogRotationsHeader)
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
export function hasRotationNodes(
  header: HilosLogRotationsHeader | null,
): boolean {
  return header !== null && header.nodes.length > 0
}

/** What the rotation history has to say instead of rows. */
export type HilosRotationsEmptyState =
  /** Rows to show, so no empty state at all. */
  | 'rows'
  /** No merged picture has arrived; the figures are unknown rather than zero. */
  | 'unknown'
  /** The picture arrived and no node could read its log store. */
  | 'unreadable'
  /** The search or a filter matched nothing, though there are batches. */
  | 'nomatch'
  /** There are no archived batches at all — nothing has rotated yet. */
  | 'never'

/**
 * Which of the four empty states the screen is in, or that it has rows.
 *
 * Four and not one, because they are cured differently and only one of them is a
 * fault: waiting on the aggregator, a log directory nobody can read, a filter that
 * matched nothing, and an installation that has simply never rotated. Zeros are
 * shown for none of them — a zero claims a measurement that was never taken.
 *
 * The order matters: the states of the PICTURE outrank the states of the window,
 * because a window built on no picture is empty for a reason that has nothing to do
 * with what was searched for.
 *
 * @param header The latest header, or null before the first one arrives.
 * @param rowCount How many rows the current window holds.
 * @param filtered Whether a search or a filter is narrowing the history.
 */
export function rotationsEmptyState(
  header: HilosLogRotationsHeader | null,
  rowCount: number,
  filtered: boolean,
): HilosRotationsEmptyState {
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

/**
 * The weight of one batch, in the largest unit that leaves a readable number.
 *
 * A zero-byte batch prints as `0 B` and not as a dash: rotation created the
 * directory, so the measurement happened and its answer is zero — where a dash
 * would mean "not known".
 *
 * @param row The batch row to format.
 */
export function formatRotationWeight(row: HilosLogRotationRow): string {
  return formatRotationBytes(row.bytes)
}

/**
 * The label of one retention badge.
 *
 * The taken one says what happens NEXT and not only what happened: an operator who
 * confirms a takeout has just given the one permission that lets the batch be
 * deleted, and a badge reading "Taken" alone would leave that consequence
 * unspoken. It is said before the pruner exists (HIL-382) on purpose — the
 * permission is granted here, whatever acts on it later.
 *
 * A verdict this build does not know is printed as it arrived rather than folded
 * into "kept": this screen may be older than the backend answering it, and
 * silently calling an evicted batch protected is the one mistake here that an
 * operator cannot see.
 *
 * @param row The batch row to label.
 */
export function formatRotationState(row: HilosLogRotationRow): string {
  switch (row.retentionState) {
    case HILOS_ROTATION_STATE_KEPT:
      return 'Kept'
    case HILOS_ROTATION_STATE_DUE:
      return 'Awaiting carry-off'
    case HILOS_ROTATION_STATE_TAKEN:
      return 'Taken — removed at the next cleanup'
    case HILOS_ROTATION_STATE_CARRYING:
      return 'Moving to the archive'
    default:
      return row.retentionState
  }
}

/**
 * Where the batch lies, said the way an operator has to say it to reach it.
 *
 * In a cluster the node leads the address, because the batch is on that machine
 * and only on it — logs do not converge anywhere. In a single-node installation
 * there is no node to name and the address is the path alone.
 *
 * Null is a node that reported no log root: it has no address to give, and this
 * screen must not fill the gap with its OWN root — the page worker knows where
 * ITS logs live, and that directory is on the wrong machine.
 *
 * @param row The batch to address.
 */
export function rotationTakeoutAddress(
  row: HilosLogRotationRow,
): string | null {
  if (row.absolutePath === null) {
    return null
  }

  return row.node === null
    ? row.absolutePath
    : `${row.node}:${row.absolutePath}`
}

/**
 * The command that carries one batch off, ready to be copied.
 *
 * `rsync -a` and not a bare copy: the archive keeps timestamps and permissions,
 * and the trailing slash on the source is what makes the batch land INSIDE the
 * destination rather than beside it. The destination is a suggestion under the
 * working directory and is named after the batch (and its node, in a cluster), so
 * two nodes' batches of one rotation moment do not land on top of each other.
 *
 * Null when the batch has no address, for the reason
 * {@link rotationTakeoutAddress} gives.
 *
 * @param row The batch to carry off.
 */
export function rotationTakeoutCommand(
  row: HilosLogRotationRow,
): string | null {
  const address = rotationTakeoutAddress(row)
  if (address === null) {
    return null
  }

  const batch = rotationBatchDirectoryName(row)
  const destination =
    row.node === null
      ? `./cold-logs/${batch}/`
      : `./cold-logs/${row.node}/${batch}/`

  return `rsync -a ${address} ${destination}`
}

/**
 * The name of the batch's own directory, taken off the relative path.
 *
 * The path is the one the backend built (`archive/<name>/`), so the last segment
 * is the batch's name whatever the archive subdirectory is called.
 *
 * @param row The batch to name.
 */
function rotationBatchDirectoryName(row: HilosLogRotationRow): string {
  const segments = row.path.split('/').filter((segment) => segment !== '')

  return segments[segments.length - 1] ?? row.path
}

/** A selectable retention state: its wire value and a human-readable label. */
export interface HilosRotationStateOption {
  /** The wire value sent as the state filter (empty clears the filter). */
  readonly value: string
  /** The label shown in the state switch. */
  readonly label: string
}

/**
 * The two choices the state switch offers. The empty value is "everything" and
 * clears the filter; there is no third choice, because the other two verdicts are
 * things to read on a row rather than things to hunt for.
 */
export const HILOS_ROTATION_STATE_OPTIONS: readonly HilosRotationStateOption[] =
  [
    { value: '', label: 'All' },
    { value: HILOS_ROTATION_STATE_DUE, label: 'Awaiting carry-off' },
  ]

/**
 * The three file counts of the Files column — agent, worker, monopolistic worker.
 *
 * The daemon's own streams are a fourth class and deliberately absent: they are the
 * node's own log rather than anything the installation runs, and the overview
 * leaves them out of its two tiles for the same reason. They are still inside the
 * weight, because the directory costs what it costs.
 *
 * @param row The batch row to format.
 */
export function formatRotationFileCounts(row: HilosLogRotationRow): string {
  return [
    row.agentFileCount,
    row.workerFileCount,
    row.workerMonopolisticFileCount,
  ].join(' / ')
}

/**
 * The rotation half of the rule line: every axis that is on, in words.
 *
 * The axes are listed rather than named by a preset, because presets do not exist
 * yet (HIL-762) and a screen that said "Normal mode" would be naming something the
 * installation cannot be set to. An axis at zero is off and is left out entirely —
 * printing `0` would read as "rotates at no size", which is the opposite of what a
 * disabled threshold means.
 *
 * Every axis off is a sentence of its own rather than an empty line: rotation that
 * only happens on a restart is a real configuration, and an operator reading a blank
 * would take it for a screen that failed to load.
 *
 * @param header The latest header.
 */
export function formatRotationRule(header: HilosLogRotationsHeader): string {
  const axes: string[] = []
  if (header.rotationCron !== null && header.rotationCron !== '') {
    axes.push(`on the schedule ${header.rotationCron}`)
  }
  if (header.rotationMaxAgeSeconds > 0) {
    axes.push(
      `${formatRotationDuration(header.rotationMaxAgeSeconds)} after the last rotation`,
    )
  }
  if (header.rotationMaxLiveSizeBytes > 0) {
    axes.push(
      `when the live logs reach ${formatRotationBytes(header.rotationMaxLiveSizeBytes)}`,
    )
  }

  return axes.length === 0
    ? 'Rotates only when the node restarts'
    : `Rotates ${axes.join(', or ')}`
}

/**
 * The retention half of the rule line: what protects a batch from being recommended.
 *
 * Both criteria hold at once — a batch is recommended only when it is outside the
 * newest kept ones AND older than the age — so they are joined by "and" and not by
 * "or". A criterion at zero is off and left out; both off means nothing will ever be
 * recommended, which is said outright because it is the state an unreadable setting
 * leaves behind (HIL-682) and the one an operator most needs to notice.
 *
 * @param header The latest header.
 */
export function formatRetentionRule(header: HilosLogRotationsHeader): string {
  const criteria: string[] = []
  if (header.retentionKeepBatches > 0) {
    criteria.push(`outside the newest ${header.retentionKeepBatches}`)
  }
  if (header.retentionMaxAgeSeconds > 0) {
    criteria.push(
      `older than ${formatRotationDuration(header.retentionMaxAgeSeconds)}`,
    )
  }

  return criteria.length === 0
    ? 'Nothing is ever recommended for carrying off'
    : `Recommends carrying off a batch ${criteria.join(' and ')}`
}

/** Seconds in one hour, the smallest unit the rule line speaks in. */
const SECONDS_PER_HOUR = 3600

/** Seconds in one day. */
const SECONDS_PER_DAY = 86400

/**
 * A configured threshold in the largest whole unit it fits, down to seconds.
 *
 * Whole units only: a threshold is something an administrator typed, and `1.5 d`
 * would be this screen's rounding rather than their number.
 *
 * @param seconds The threshold, in seconds.
 */
function formatRotationDuration(seconds: number): string {
  if (seconds % SECONDS_PER_DAY === 0) {
    return `${seconds / SECONDS_PER_DAY} d`
  }
  if (seconds % SECONDS_PER_HOUR === 0) {
    return `${seconds / SECONDS_PER_HOUR} h`
  }

  return `${seconds} s`
}

/**
 * A byte figure in the largest unit that leaves a readable number — the one place
 * this module turns bytes into words, for a measured batch and a configured
 * threshold alike.
 *
 * @param bytes The figure, in bytes.
 */
function formatRotationBytes(bytes: number): string {
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let size = bytes
  let unit = 0
  while (size >= 1024 && unit < units.length - 1) {
    size /= 1024
    unit += 1
  }

  return `${unit === 0 ? size : size.toFixed(1)} ${units[unit]}`
}
