// Framework headless agents table for one Daemon node. Rows come from the
// worker-local picture mirror through a server viewport. The page header and
// empty states belong to the per-framework view.

import { z } from 'zod'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { HilosPages } from '../../routing/hilosPages.js'
import {
  readBoolean,
  readNumber,
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
import { LOG_SOURCE_LIVE, logViewerPath } from '../logs/hilosLogViewer.js'
import { formatDaemonWorkerName } from './hilosDaemonWorkers.js'

const DAEMON_AGENTS_TABLE = 'hilosDaemonAgents'
const DAEMON_AGENTS_SLOT = 'agent'

/** Viewport filter key identifying the node shown by the page. */
export const DAEMON_AGENTS_FILTER_NODE = 'node'

/** Row payload key of the agent instance id. */
export const DAEMON_AGENT_ID_FIELD = 'agentId'
/** Row payload key of the hosting worker index. */
export const DAEMON_AGENT_WORKER_INDEX_FIELD = 'workerIndex'
/** Row payload key of the hosting worker kind. */
export const DAEMON_AGENT_WORKER_KIND_FIELD = 'workerKind'
/** Row payload key of the agent placement. */
export const DAEMON_AGENT_PLACEMENT_FIELD = 'placement'
/** Row payload key of the idle timeout state. */
export const DAEMON_AGENT_IDLE_FIELD = 'idle'
/** Row payload key of declared runtime ownership. */
export const DAEMON_AGENT_OWNS_RT_FIELD = 'ownsRt'
/** Row payload key of declared database ownership. */
export const DAEMON_AGENT_OWNS_DB_FIELD = 'ownsDb'
/** Row payload key of the live agent log stream. */
export const DAEMON_AGENT_LOG_STREAM_FIELD = 'logStream'

/** Node replica placement. */
export const DAEMON_AGENT_PLACEMENT_NODE = 'node'
/** Leader-hosted placement. */
export const DAEMON_AGENT_PLACEMENT_LEADER = 'leader'
/** Policy-selected placement. */
export const DAEMON_AGENT_PLACEMENT_POLICY = 'policy'

/** Claim over an entire collection. */
export const DAEMON_AGENT_WIDTH_WHOLE = 'whole'
/** Claim over named rows. */
export const DAEMON_AGENT_WIDTH_ROWS = 'rows'
/** Claim over one set of rows. */
export const DAEMON_AGENT_WIDTH_SET = 'set'

const ownershipSchema = z.array(
  z.object({ collection: z.string(), width: z.string() }),
)

/** One declared collection claim of an agent class. */
export interface HilosDaemonAgentOwnership {
  readonly collection: string
  readonly width: string
}

/** One started agent in the selected node's process roster. */
export interface HilosDaemonAgentRow {
  readonly rowKey: string
  readonly agentId: string
  readonly workerIndex: number
  readonly workerKind: string
  readonly placement: string
  readonly idle: boolean
  readonly ownsRt: readonly HilosDaemonAgentOwnership[]
  readonly ownsDb: readonly HilosDaemonAgentOwnership[]
  readonly logStream: string
}

/** Connection and page scope used by the agents table. */
export interface HilosDaemonAgentsContext {
  readonly connection: HilosConnection
  readonly scopes: ScopeManager
}

/** Table controller and its view mount lifecycle. */
export interface HilosDaemonAgentsTable {
  readonly controller: TableViewportController<HilosDaemonAgentRow>
  start(): void
  dispose(): void
}

/**
 * Read one inline `agent` slot; identity comes from the table fragment's row key.
 *
 * @param row The raw row in the page scope.
 */
export function resolveHilosDaemonAgentRow(row: TableRow): HilosDaemonAgentRow {
  const raw = row.slots[DAEMON_AGENTS_SLOT]
  const slot: Readonly<Record<string, unknown>> =
    typeof raw === 'object' && raw !== null && !Array.isArray(raw)
      ? (raw as Record<string, unknown>)
      : {}
  const ownsRt = ownershipSchema.safeParse(slot[DAEMON_AGENT_OWNS_RT_FIELD])
  const ownsDb = ownershipSchema.safeParse(slot[DAEMON_AGENT_OWNS_DB_FIELD])

  return {
    rowKey: String(row.rowKey),
    agentId: readString(slot, DAEMON_AGENT_ID_FIELD),
    workerIndex: readNumber(slot, DAEMON_AGENT_WORKER_INDEX_FIELD),
    workerKind: readString(slot, DAEMON_AGENT_WORKER_KIND_FIELD),
    placement: readString(slot, DAEMON_AGENT_PLACEMENT_FIELD),
    idle: readBoolean(slot, DAEMON_AGENT_IDLE_FIELD),
    ownsRt: ownsRt.success ? ownsRt.data : [],
    ownsDb: ownsDb.success ? ownsDb.data : [],
    logStream: readString(slot, DAEMON_AGENT_LOG_STREAM_FIELD),
  }
}

/**
 * Build the viewport for the node selected by the page route.
 *
 * @param context Connection and scope manager.
 * @param nodeId Node id from the route, including `standalone` on one node.
 */
export function createHilosDaemonAgentsTable(
  context: HilosDaemonAgentsContext,
  nodeId: string,
): HilosDaemonAgentsTable {
  const columns: HilosTableColumnOf<HilosDaemonAgentRow>[] = [
    { key: DAEMON_AGENT_ID_FIELD, label: 'Agent', card: 'title' },
    {
      key: DAEMON_AGENT_WORKER_INDEX_FIELD,
      label: 'Worker',
      reads: [DAEMON_AGENT_WORKER_KIND_FIELD],
    },
    {
      key: DAEMON_AGENT_PLACEMENT_FIELD,
      label: 'Kind',
      card: 'badge',
      reads: [DAEMON_AGENT_IDLE_FIELD],
    },
    { key: DAEMON_AGENT_OWNS_RT_FIELD, label: 'Owns in RT' },
    { key: DAEMON_AGENT_OWNS_DB_FIELD, label: 'Owns in DB' },
    {
      key: HILOS_TABLE_ACTIONS_KEY,
      label: '',
      reads: [DAEMON_AGENT_ID_FIELD, DAEMON_AGENT_LOG_STREAM_FIELD],
    },
  ]
  const frame: HilosTableFrame = {
    search: { placeholder: 'Search by agent id…' },
    columns,
  }
  const controller = new TableViewportController<HilosDaemonAgentRow>({
    resolve: resolveHilosDaemonAgentRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.DAEMON_AGENTS,
        DAEMON_AGENTS_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        HilosPages.DAEMON_AGENTS,
        DAEMON_AGENTS_TABLE,
        rendered,
      ),
    initialFilter: { [DAEMON_AGENTS_FILTER_NODE]: nodeId },
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
          { page: HilosPages.DAEMON_AGENTS, tableKey: DAEMON_AGENTS_TABLE },
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
 * Label the hosting worker by its kind and actual index.
 *
 * @param row Agent whose worker is labelled.
 */
export function formatDaemonAgentWorker(row: HilosDaemonAgentRow): string {
  return formatDaemonWorkerName(row.workerIndex, row.workerKind)
}

/**
 * Label the agent's placement and idle policy for the installation mode.
 *
 * @param row Agent being labelled.
 * @param cluster Whether this installation has cluster leadership.
 */
export function formatDaemonAgentKind(
  row: HilosDaemonAgentRow,
  cluster: boolean,
): string {
  if (!cluster) {
    return row.idle ? 'Lazy' : 'Stays up'
  }

  let kind: string
  switch (row.placement) {
    case DAEMON_AGENT_PLACEMENT_NODE:
      kind = 'Node replica'
      break
    case DAEMON_AGENT_PLACEMENT_LEADER:
      kind = 'Leader-hosted'
      break
    case DAEMON_AGENT_PLACEMENT_POLICY:
      kind = 'Policy-placed'
      break
    default:
      kind = row.placement
  }
  return row.idle ? `${kind} · lazy` : kind
}

/**
 * List collection names with the declared width of each claim.
 *
 * @param entries Class declarations in collection order.
 */
export function formatDaemonAgentOwnership(
  entries: readonly HilosDaemonAgentOwnership[],
): string {
  if (entries.length === 0) {
    return '—'
  }
  return entries
    .map(({ collection, width }) =>
      width === DAEMON_AGENT_WIDTH_WHOLE
        ? collection
        : `${collection} · ${width}`,
    )
    .join(', ')
}

/**
 * Address the selected agent's live log on the node named by the page route.
 *
 * @param nodeId Node id from the route.
 * @param row Agent whose log is opened.
 */
export function daemonAgentLogPath(
  nodeId: string,
  row: HilosDaemonAgentRow,
): string {
  return logViewerPath({
    nodeId,
    source: LOG_SOURCE_LIVE,
    stream: row.logStream,
    anchorAtMs: null,
  })
}
