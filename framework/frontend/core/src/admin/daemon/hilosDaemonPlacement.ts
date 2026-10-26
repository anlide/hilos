// Framework headless placement matrix. The page supplies the current node list;
// rows come from the page worker's picture mirror through a server viewport.

import { z } from 'zod'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { HilosPages } from '../../routing/hilosPages.js'
import { readBoolean, readString } from '../../state/fieldReaders.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
} from '../../state/signal.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { type HilosTableColumn } from '../../table/hilosTableColumn.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import { formatDaemonAgentKind } from './hilosDaemonAgents.js'
import { type HilosDaemonOverviewData } from './hilosDaemonOverview.js'

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

/** The current permitted cell, resolved afresh from the server window. */
export interface HilosDaemonPlacementSelection {
  readonly rowKey: string
  readonly agentType: string
  readonly nodeId: string
  readonly agentIds: readonly string[]
}

/** One matrix and its local cell selection for the Placement page's lifetime. */
export interface HilosDaemonPlacementView extends HilosDaemonPlacementTable {
  readonly nodes: ReadonlySignal<readonly HilosDaemonPlacementNode[]>
  readonly selectedCell: ReadonlySignal<HilosDaemonPlacementSelection | null>
  openCell(rowKey: string, nodeId: string): void
  closeCell(): void
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
 * @param expandable Whether a row has a locally selected, permitted cell.
 */
export function createHilosDaemonPlacementTable(
  context: HilosDaemonPlacementContext,
  nodes: ReadonlySignal<readonly HilosDaemonPlacementNode[]>,
  expandable: (row: HilosDaemonPlacementRow) => boolean = () => false,
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
      ...nodes.get().map((node): HilosTableColumn => ({
        key: daemonPlacementNodeColumnKey(node.nodeId),
        label: formatDaemonPlacementNodeLabel(node),
        reads: [DAEMON_PLACEMENT_NODES_FIELD],
        headerClass: 'text-center',
        cellClass: 'text-center',
      })),
      {
        key: DAEMON_PLACEMENT_UNPLACED_FIELD,
        label: 'State',
        card: 'badge',
        headerClass: 'text-end',
        cellClass: 'text-end',
      },
      {
        key: DAEMON_PLACEMENT_NODES_FIELD,
        label: 'Instances',
        detail: true,
        detailWide: true,
      },
    ],
  }
  const controller = new TableViewportController<HilosDaemonPlacementRow>({
    resolve: resolveHilosDaemonPlacementRow,
    expandable,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.DAEMON_PLACEMENT,
        DAEMON_PLACEMENT_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        HilosPages.DAEMON_PLACEMENT,
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
          {
            page: HilosPages.DAEMON_PLACEMENT,
            tableKey: DAEMON_PLACEMENT_TABLE,
          },
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
 * Keep only the chosen address; the current window owns every instance id.
 *
 * @param context Connection and page scope used by the matrix.
 * @param overview The Placement page's complete picture, independent of table search.
 */
export function createHilosDaemonPlacementView(
  context: HilosDaemonPlacementContext,
  overview: ReadonlySignal<HilosDaemonOverviewData | null>,
): HilosDaemonPlacementView {
  const nodes = computedSignal(() =>
    (overview.get()?.nodes ?? []).map((node) => ({
      nodeId: node.nodeId,
      leader: overview.get()?.clustered === true && node.state === 'leader',
    })),
  )
  const chosen = createSignal<{
    readonly rowKey: string
    readonly nodeId: string
  } | null>(null)
  const knownNode = (nodeId: string): boolean =>
    overview
      .get()
      ?.nodes.some(
        (node) =>
          node.nodeId === nodeId && node.online && node.workers !== null,
      ) === true
  const table = createHilosDaemonPlacementTable(context, nodes, (row) => {
    const cell = chosen.get()
    // Read only the supplied row and the chosen address: selectedCell reads
    // controller.rows, so using it here would make rows depend on themselves.
    return (
      cell !== null &&
      cell.rowKey === row.rowKey &&
      knownNode(cell.nodeId) &&
      (daemonPlacementCellIds(row, cell.nodeId)?.length ?? 0) > 0
    )
  })
  const controller = table.controller
  const resolveCell = (
    rowKey: string,
    nodeId: string,
  ): HilosDaemonPlacementSelection | null => {
    const row = controller.rows.get().find((row) => row.rowKey === rowKey)
    const ids = row?.row && daemonPlacementCellIds(row.row, nodeId)
    return ids && ids.length > 0 && row?.row
      ? { rowKey, agentType: row.row.agentType, nodeId, agentIds: ids }
      : null
  }
  const available = computedSignal(
    () =>
      controller.frame.body.get() !== 'unavailable' &&
      controller.frozenSince.get() === null,
  )
  const valid = computedSignal(() => {
    const cell = chosen.get()
    if (cell === null) return true
    if (!knownNode(cell.nodeId) || !available.get()) return false
    // Loading hides the panel but cannot judge the previous search's rows.
    return (
      controller.frame.body.get() === 'loading' ||
      resolveCell(cell.rowKey, cell.nodeId) !== null
    )
  })
  const selectedCell = computedSignal(() => {
    const cell = chosen.get()
    return cell !== null &&
      valid.get() &&
      controller.frame.body.get() !== 'loading' &&
      controller.rows
        .get()
        .some((row) => row.rowKey === cell.rowKey && row.expanded)
      ? resolveCell(cell.rowKey, cell.nodeId)
      : null
  })
  const closeCell = (): void => {
    const cell = chosen.get()
    if (cell !== null) controller.expandRow(cell.rowKey, false)
    chosen.set(null)
  }
  const teardown: Array<() => void> = []

  return {
    controller,
    nodes,
    selectedCell,
    openCell(rowKey, nodeId) {
      if (
        knownNode(nodeId) &&
        available.get() &&
        controller.frame.body.get() !== 'loading' &&
        resolveCell(rowKey, nodeId) !== null
      ) {
        const previous = chosen.get()
        if (
          previous?.rowKey === rowKey &&
          previous.nodeId === nodeId &&
          controller.rows
            .get()
            .some((row) => row.rowKey === rowKey && row.expanded)
        ) {
          closeCell()
          return
        }
        if (previous !== null && previous.rowKey !== rowKey) {
          controller.expandRow(previous.rowKey, false)
        }
        chosen.set({ rowKey, nodeId })
        controller.expandRow(rowKey, true)
      }
    },
    closeCell,
    start() {
      if (teardown.length > 0) return
      teardown.push(
        subscribeSignal(valid, (current) => {
          if (!current) closeCell()
        }),
      )
      table.start()
    },
    dispose() {
      for (const off of teardown.splice(0)) off()
      table.dispose()
      closeCell()
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
