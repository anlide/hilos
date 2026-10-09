// Framework headless workers table for one Daemon node. Rows come from the
// worker-local picture mirror through a server viewport, in picture order.
// The page header and empty states belong to the per-framework view.

import { type HilosConnection } from '../../connection/HilosConnection.js'
import { formatBytes } from '../../format/bytes.js'
import { LOG_SOURCE_LIVE, logViewerPath } from '../logs/hilosLogViewer.js'
import { HilosPages } from '../../routing/hilosPages.js'
import {
  readNumber,
  readNumberOrNull,
  readString,
} from '../../state/fieldReaders.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import {
  HILOS_TABLE_ACTIONS_KEY,
  type HilosTableColumnOf,
} from '../../table/hilosTableColumn.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'

const DAEMON_WORKERS_TABLE = 'hilosDaemonWorkers'
const DAEMON_WORKERS_SLOT = 'worker'

/** Viewport filter key identifying the node shown by the page. */
export const DAEMON_WORKERS_FILTER_NODE = 'node'

/** Row payload key of the worker index. */
export const DAEMON_WORKER_INDEX_FIELD = 'index'
/** Row payload key of the worker kind. */
export const DAEMON_WORKER_KIND_FIELD = 'kind'
/** Row payload key of the process id. */
export const DAEMON_WORKER_PID_FIELD = 'pid'
/** Row payload key of the measured RSS. */
export const DAEMON_WORKER_MEMORY_FIELD = 'memoryBytes'
/** Row payload key of the started agent count. */
export const DAEMON_WORKER_AGENT_COUNT_FIELD = 'agentCount'
/** Row payload key of the started agent ids. */
export const DAEMON_WORKER_AGENT_IDS_FIELD = 'agentIds'
/** Row payload key of the live worker log stream. */
export const DAEMON_WORKER_LOG_STREAM_FIELD = 'logStream'

/** Ordinary worker kind. */
export const DAEMON_WORKER_KIND_REGULAR = 'regular'
/** Monopolistic worker kind. */
export const DAEMON_WORKER_KIND_MONOPOLISTIC = 'monopolistic'

/** One worker in the selected node's process roster. */
export interface HilosDaemonWorkerRow {
  readonly rowKey: string
  readonly index: number
  readonly kind: string
  readonly pid: number | null
  readonly memoryBytes: number | null
  readonly agentCount: number
  readonly agentIds: readonly string[]
  readonly logStream: string
}

/** Connection and page scope used by the workers table. */
export interface HilosDaemonWorkersContext {
  readonly connection: HilosConnection
  readonly scopes: ScopeManager
}

/** Table controller and its view mount lifecycle. */
export interface HilosDaemonWorkersTable {
  readonly controller: TableViewportController<HilosDaemonWorkerRow>
  start(): void
  dispose(): void
}

/**
 * Read one inline `worker` slot; identity comes from the table fragment's row key.
 *
 * @param row The raw row in the page scope.
 */
export function resolveHilosDaemonWorkerRow(
  row: TableRow,
): HilosDaemonWorkerRow {
  const raw = row.slots[DAEMON_WORKERS_SLOT]
  const slot: Readonly<Record<string, unknown>> =
    typeof raw === 'object' && raw !== null && !Array.isArray(raw)
      ? (raw as Record<string, unknown>)
      : {}
  const agentIds = slot[DAEMON_WORKER_AGENT_IDS_FIELD]

  return {
    rowKey: String(row.rowKey),
    index: readNumber(slot, DAEMON_WORKER_INDEX_FIELD),
    kind: readString(slot, DAEMON_WORKER_KIND_FIELD),
    pid: readNumberOrNull(slot, DAEMON_WORKER_PID_FIELD),
    memoryBytes: readNumberOrNull(slot, DAEMON_WORKER_MEMORY_FIELD),
    agentCount: readNumber(slot, DAEMON_WORKER_AGENT_COUNT_FIELD),
    agentIds: Array.isArray(agentIds)
      ? agentIds.filter((id): id is string => typeof id === 'string')
      : [],
    logStream: readString(slot, DAEMON_WORKER_LOG_STREAM_FIELD),
  }
}

/**
 * Build the viewport for the node selected by the page route.
 *
 * @param context Connection and scope manager.
 * @param nodeId Node id from the route, including `standalone` on one node.
 */
export function createHilosDaemonWorkersTable(
  context: HilosDaemonWorkersContext,
  nodeId: string,
): HilosDaemonWorkersTable {
  const columns: HilosTableColumnOf<HilosDaemonWorkerRow>[] = [
    {
      key: DAEMON_WORKER_INDEX_FIELD,
      label: 'Worker',
      card: 'title',
      reads: [DAEMON_WORKER_KIND_FIELD],
    },
    { key: DAEMON_WORKER_KIND_FIELD, label: 'Kind', card: 'badge' },
    { key: DAEMON_WORKER_PID_FIELD, label: 'PID' },
    {
      key: DAEMON_WORKER_AGENT_COUNT_FIELD,
      label: 'Agents',
      headerClass: 'text-center',
    },
    { key: DAEMON_WORKER_MEMORY_FIELD, label: 'Memory' },
    {
      key: HILOS_TABLE_ACTIONS_KEY,
      label: '',
      reads: [DAEMON_WORKER_LOG_STREAM_FIELD],
    },
    {
      key: DAEMON_WORKER_AGENT_IDS_FIELD,
      label: 'Agent instances',
      detail: true,
    },
  ]
  const frame: HilosTableFrame = { columns }
  const controller = new TableViewportController<HilosDaemonWorkerRow>({
    resolve: resolveHilosDaemonWorkerRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.DAEMON_WORKERS,
        DAEMON_WORKERS_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        HilosPages.DAEMON_WORKERS,
        DAEMON_WORKERS_TABLE,
        rendered,
      ),
    initialFilter: { [DAEMON_WORKERS_FILTER_NODE]: nodeId },
    frame,
  })
  const teardown: Array<() => void> = []

  return {
    controller,
    start() {
      teardown.push(
        bindTableViewport(
          context.connection,
          context.scopes,
          { page: HilosPages.DAEMON_WORKERS, tableKey: DAEMON_WORKERS_TABLE },
          controller,
        ),
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
 * Label a worker by its kind and actual index.
 *
 * @param index Worker index shared across kinds.
 * @param kind Worker kind.
 */
export function formatDaemonWorkerName(index: number, kind: string): string {
  switch (kind) {
    case DAEMON_WORKER_KIND_MONOPOLISTIC:
      return `m${index}`
    case DAEMON_WORKER_KIND_REGULAR:
      return `w${index}`
    default:
      return `${kind}:${index}`
  }
}

/**
 * Label the worker kind, preserving an unfamiliar kind as it arrived.
 *
 * @param row Worker being labelled.
 */
export function formatDaemonWorkerKind(row: HilosDaemonWorkerRow): string {
  switch (row.kind) {
    case DAEMON_WORKER_KIND_MONOPOLISTIC:
      return 'Monopolistic'
    case DAEMON_WORKER_KIND_REGULAR:
      return 'Ordinary'
    default:
      return row.kind
  }
}

/** @param row Worker whose process id is shown. */
export function formatDaemonWorkerPid(row: HilosDaemonWorkerRow): string {
  return row.pid === null ? '—' : String(row.pid)
}

/** @param row Worker whose RSS is shown. */
export function formatDaemonWorkerMemory(row: HilosDaemonWorkerRow): string {
  return row.memoryBytes === null ? '—' : formatBytes(row.memoryBytes)
}

/** @param row Worker whose started agent count is shown. */
export function formatDaemonWorkerAgentCount(
  row: HilosDaemonWorkerRow,
): string {
  return row.agentCount === 0 ? '·' : String(row.agentCount)
}

/**
 * Address the selected worker's live log on the node named by the page route.
 *
 * @param nodeId Node id from the route.
 * @param row Worker whose log is opened.
 */
export function daemonWorkerLogPath(
  nodeId: string,
  row: HilosDaemonWorkerRow,
): string {
  return logViewerPath({
    nodeId,
    source: LOG_SOURCE_LIVE,
    stream: row.logStream,
    anchorAtMs: null,
  })
}
