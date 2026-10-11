// Framework headless placement matrix. The page supplies the current node list;
// rows come from the page worker's picture mirror through a server viewport.

import { z } from 'zod'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { HilosPages } from '../../routing/hilosPages.js'
import { readBoolean, readString } from '../../state/fieldReaders.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { type ReadonlySignal } from '../../state/signal.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { type HilosTableColumn } from '../../table/hilosTableColumn.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import { formatDaemonAgentKind } from './hilosDaemonAgents.js'

const DAEMON_PLACEMENT_TABLE = 'hilosDaemonPlacement'
const DAEMON_PLACEMENT_SLOT = 'type'

/** Row payload key of the declared agent type. */
export const DAEMON_PLACEMENT_TYPE_FIELD = 'agentType'
/** Row payload key of the agent placement policy. */
export const DAEMON_PLACEMENT_PLACEMENT_FIELD = 'placement'
/** Row payload key of the idle timeout state. */
export const DAEMON_PLACEMENT_IDLE_FIELD = 'idle'
/** Row payload key of the per-node instance lists. */
export const DAEMON_PLACEMENT_NODES_FIELD = 'nodes'
/** Row payload key of the leader's unplaced instance ids. */
export const DAEMON_PLACEMENT_UNPLACED_FIELD = 'unplacedAgentIds'

const nodesSchema = z.array(
  z.object({ nodeId: z.string(), agentIds: z.array(z.string()).nullable() }),
)

/** One page-header node used to build a matrix column. */
export interface HilosDaemonPlacementNode {
  readonly nodeId: string
  readonly leader: boolean
}

/** What one node reports about a declared agent type. */
export interface HilosDaemonPlacementCell {
  readonly nodeId: string
  readonly agentIds: readonly string[] | null
}

/** One declared agent type and its instances on known nodes. */
export interface HilosDaemonPlacementRow {
  readonly rowKey: string
  readonly agentType: string
  readonly placement: string
  readonly idle: boolean
  readonly nodes: readonly HilosDaemonPlacementCell[]
  readonly unplacedAgentIds: readonly string[]
}

/** Connection and page scope used by the placement matrix. */
export interface HilosDaemonPlacementContext {
  readonly connection: HilosConnection
  readonly scopes: ScopeManager
}

/** Matrix controller and its view mount lifecycle. */
export interface HilosDaemonPlacementTable {
  readonly controller: TableViewportController<HilosDaemonPlacementRow>
  start(): void
  dispose(): void
}

/**
 * Read one inline `type` slot; identity comes from the table fragment's row key.
 *
 * @param row The raw row in the page scope.
 */
export function resolveHilosDaemonPlacementRow(
  row: TableRow,
): HilosDaemonPlacementRow {
  const raw = row.slots[DAEMON_PLACEMENT_SLOT]
  const slot: Readonly<Record<string, unknown>> =
    typeof raw === 'object' && raw !== null && !Array.isArray(raw)
      ? (raw as Record<string, unknown>)
      : {}
  const nodes = nodesSchema.safeParse(slot[DAEMON_PLACEMENT_NODES_FIELD])
  const unplaced = slot[DAEMON_PLACEMENT_UNPLACED_FIELD]

  return {
    rowKey: String(row.rowKey),
    agentType: readString(slot, DAEMON_PLACEMENT_TYPE_FIELD),
    placement: readString(slot, DAEMON_PLACEMENT_PLACEMENT_FIELD),
    idle: readBoolean(slot, DAEMON_PLACEMENT_IDLE_FIELD),
    nodes: nodes.success ? nodes.data : [],
    unplacedAgentIds: Array.isArray(unplaced)
      ? unplaced.filter((id): id is string => typeof id === 'string')
      : [],
  }
}

/**
 * Build the matrix viewport over every declared agent type.
 *
 * @param context Connection and scope manager.
 * @param nodes Current page-header nodes, updated as the picture changes.
 */
export function createHilosDaemonPlacementTable(
  context: HilosDaemonPlacementContext,
  nodes: ReadonlySignal<readonly HilosDaemonPlacementNode[]>,
): HilosDaemonPlacementTable {
  const frame: HilosTableFrame = {
    search: { placeholder: 'Search by agent id…' },
    columns: (): HilosTableColumn[] => [
      { key: DAEMON_PLACEMENT_TYPE_FIELD, label: 'Agent type', card: 'title' },
      {
        key: DAEMON_PLACEMENT_PLACEMENT_FIELD,
        label: 'Kind',
        reads: [DAEMON_PLACEMENT_IDLE_FIELD],
      },
      ...nodes.get().map(
        (node): HilosTableColumn => ({
          key: daemonPlacementNodeColumnKey(node.nodeId),
          label: formatDaemonPlacementNodeLabel(node),
          reads: [DAEMON_PLACEMENT_NODES_FIELD],
          headerClass: 'text-center',
          cellClass: 'text-center',
        }),
      ),
      {
        key: DAEMON_PLACEMENT_UNPLACED_FIELD,
        label: 'State',
        card: 'badge',
        headerClass: 'text-end',
        cellClass: 'text-end',
      },
    ],
  }
  const controller = new TableViewportController<HilosDaemonPlacementRow>({
    resolve: resolveHilosDaemonPlacementRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.DAEMON,
        DAEMON_PLACEMENT_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        HilosPages.DAEMON,
        DAEMON_PLACEMENT_TABLE,
        rendered,
      ),
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
          { page: HilosPages.DAEMON, tableKey: DAEMON_PLACEMENT_TABLE },
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

/** @param nodeId Actual id of the node whose cell is being rendered. */
export function daemonPlacementNodeColumnKey(nodeId: string): string {
  return `node:${nodeId}`
}

/** @param node Node named in a matrix header. */
export function formatDaemonPlacementNodeLabel(
  node: HilosDaemonPlacementNode,
): string {
  return node.leader ? `${node.nodeId} ★` : node.nodeId
}

/**
 * Return the ids held by a node, or null when its roster is unknown.
 *
 * @param row Declared type shown in the matrix.
 * @param nodeId Actual node id from the page header.
 */
export function daemonPlacementCellIds(
  row: HilosDaemonPlacementRow,
  nodeId: string,
): readonly string[] | null {
  return row.nodes.find((node) => node.nodeId === nodeId)?.agentIds ?? null
}

/**
 * Show a count only when the node reported its roster.
 *
 * @param row Declared type shown in the matrix.
 * @param nodeId Actual node id from the page header.
 */
export function formatDaemonPlacementCell(
  row: HilosDaemonPlacementRow,
  nodeId: string,
): string {
  const ids = daemonPlacementCellIds(row, nodeId)
  return ids === null ? '?' : ids.length === 0 ? '·' : String(ids.length)
}

/**
 * Use the same kind label as the per-node agents table.
 *
 * @param row Declared type shown in the matrix.
 * @param cluster Whether this installation has cluster leadership.
 */
export function formatDaemonPlacementKind(
  row: HilosDaemonPlacementRow,
  cluster: boolean,
): string {
  return formatDaemonAgentKind(row, cluster)
}

/** @param row Declared type whose leader placement verdict is shown. */
export function formatDaemonPlacementState(
  row: HilosDaemonPlacementRow,
): string {
  return row.unplacedAgentIds.length > 0 ? 'Unplaced' : ''
}
