// Cluster maintenance reports use the ordinary page-scoped table window.
// Presentation stays here so every view draws the same reported facts.

import { HilosPages, type HilosPageKey } from '../../routing/hilosPages.js'
import { readBoolean, readString } from '../../state/fieldReaders.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { type HilosTableColumnOf } from '../../table/hilosTableColumn.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { type HilosTableRowMessage } from '../../table/tableRowMessage.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import { type HilosMaintenanceContext } from './hilosMaintenance.js'

/** Canonical backend table key for cluster maintenance reports. */
export const HILOS_MAINTENANCE_NODES_TABLE = 'hilosMaintenanceNodes'

/** Inline row slot carrying the six public node fields. */
export const HILOS_MAINTENANCE_NODES_SLOT = 'node'

/** Row payload key of the node identity, also the row key and sortable field. */
export const MAINTENANCE_NODE_ID_FIELD = 'nodeId'

/** Row payload key of the phase reported by this node. */
export const MAINTENANCE_NODE_PHASE_FIELD = 'phase'

/** Row payload key of the leader's acknowledgement for this node. */
export const MAINTENANCE_NODE_QUIESCED_FIELD = 'quiesced'

/** Row payload key of the leader role. */
export const MAINTENANCE_NODE_IS_LEADER_FIELD = 'isLeader'

/** Row payload key of the initiator's presence on this node. */
export const MAINTENANCE_NODE_IS_INITIATOR_FIELD = 'isInitiator'

/** Row payload key of the current link's report availability. */
export const MAINTENANCE_NODE_REPORT_STATE_FIELD = 'reportState'

/** One public node report, byte-for-byte aligned with the backend row fields. */
export interface HilosMaintenanceNodeRow {
  readonly nodeId: string
  readonly phase:
    'inactive' | 'activating' | 'active' | 'verifying' | 'deactivating' | null
  readonly quiesced: boolean | null
  readonly isLeader: boolean
  readonly isInitiator: boolean
  readonly reportState: 'current' | 'missing' | 'silent'
}

/** Shared English words for the table, its cells and unavailable reports. */
export const HILOS_MAINTENANCE_NODES_COPY = {
  title: 'Cluster nodes',
  empty: 'No cluster nodes reported.',
  yes: 'Yes',
  no: 'No',
  unknown: '—',
  leader: 'Leader',
  initiator: 'Initiator here',
  missing: 'No maintenance report received on the current connection.',
  silent: 'Node disconnected — no current maintenance report.',
} as const

/** The four cells, with text accompanying every status color. */
export interface HilosMaintenanceNodePresentation {
  readonly quiesced: {
    readonly text: string
    readonly tone: 'success' | 'danger' | null
  }
  readonly phase: {
    readonly text: string
    readonly badge: boolean
  }
  readonly role: string
}

/**
 * Present reported values without turning an unknown phase or acknowledgement
 * into an inactive phase or a negative acknowledgement.
 *
 * @param row The resolved node report.
 */
export function hilosMaintenanceNodePresentation(
  row: HilosMaintenanceNodeRow,
): HilosMaintenanceNodePresentation {
  const copy = HILOS_MAINTENANCE_NODES_COPY
  const roles = [
    ...(row.isLeader ? [copy.leader] : []),
    ...(row.isInitiator ? [copy.initiator] : []),
  ]

  return {
    quiesced: {
      text:
        row.quiesced === null
          ? copy.unknown
          : row.quiesced
            ? copy.yes
            : copy.no,
      tone: row.quiesced === null ? null : row.quiesced ? 'success' : 'danger',
    },
    phase: { text: row.phase ?? copy.unknown, badge: row.phase !== null },
    role: roles.length > 0 ? roles.join(' · ') : copy.unknown,
  }
}

/**
 * Explain an unavailable report beside the known role, preserving the
 * independent acknowledgement and phase cells before the merged row tail.
 *
 * @param row The resolved node report.
 */
export function hilosMaintenanceNodeRowMessage(
  row: HilosMaintenanceNodeRow,
): HilosTableRowMessage | null {
  if (row.reportState === 'current') return null

  const role = hilosMaintenanceNodePresentation(row).role
  const explanation = HILOS_MAINTENANCE_NODES_COPY[row.reportState]

  return {
    fromColumn: MAINTENANCE_NODE_IS_LEADER_FIELD,
    text:
      role === HILOS_MAINTENANCE_NODES_COPY.unknown
        ? explanation
        : `${role} · ${explanation}`,
  }
}

/** Read-only table columns shared by all views; role uses both reported flags. */
export const HILOS_MAINTENANCE_NODES_COLUMNS: readonly HilosTableColumnOf<HilosMaintenanceNodeRow>[] =
  [
    {
      key: MAINTENANCE_NODE_ID_FIELD,
      label: 'Node',
      sortable: true,
      card: 'title',
    },
    {
      key: MAINTENANCE_NODE_QUIESCED_FIELD,
      label: 'Quiesced',
      card: 'field',
    },
    {
      key: MAINTENANCE_NODE_PHASE_FIELD,
      label: 'Phase on node',
      card: 'badge',
    },
    {
      key: MAINTENANCE_NODE_IS_LEADER_FIELD,
      label: 'Role',
      card: 'field',
      reads: [
        MAINTENANCE_NODE_IS_INITIATOR_FIELD,
        MAINTENANCE_NODE_REPORT_STATE_FIELD,
      ],
    },
  ]

/**
 * Resolve the inline node slot without adding a synthetic entity id or role.
 *
 * @param row The normalized table row from the page scope.
 */
export function resolveHilosMaintenanceNodeRow(
  row: TableRow,
): HilosMaintenanceNodeRow {
  const slot = row.slots[HILOS_MAINTENANCE_NODES_SLOT]
  const fields =
    typeof slot === 'object' && slot !== null && !Array.isArray(slot)
      ? (slot as Record<string, unknown>)
      : {}
  const phase = readString(fields, MAINTENANCE_NODE_PHASE_FIELD)
  const quiesced = fields[MAINTENANCE_NODE_QUIESCED_FIELD]
  const reportState = readString(fields, MAINTENANCE_NODE_REPORT_STATE_FIELD)

  return {
    nodeId: readString(fields, MAINTENANCE_NODE_ID_FIELD) || String(row.rowKey),
    phase:
      phase === 'inactive' ||
      phase === 'activating' ||
      phase === 'active' ||
      phase === 'verifying' ||
      phase === 'deactivating'
        ? phase
        : null,
    quiesced: typeof quiesced === 'boolean' ? quiesced : null,
    isLeader: readBoolean(fields, MAINTENANCE_NODE_IS_LEADER_FIELD),
    isInitiator: readBoolean(fields, MAINTENANCE_NODE_IS_INITIATOR_FIELD),
    reportState:
      reportState === 'current' || reportState === 'silent'
        ? reportState
        : 'missing',
  }
}

/** The server-windowed controller and its mount lifecycle. */
export interface HilosMaintenanceNodesTable {
  readonly controller: TableViewportController<HilosMaintenanceNodeRow>
  /** Bind the initial page response and later table updates without a mount request. */
  start(): void
  /** Release the table's connection binding on unmount. */
  dispose(): void
}

/**
 * Create the node table. Its first order and window size come from the backend;
 * only the reader's later viewport changes send a request.
 *
 * @param context The maintenance connection and page scope stores.
 * @param page The page owning this table; defaults to maintenance Nodes.
 */
export function createHilosMaintenanceNodesTable(
  context: Pick<HilosMaintenanceContext, 'connection' | 'scopes'>,
  page: HilosPageKey = HilosPages.MAINTENANCE_NODES,
): HilosMaintenanceNodesTable {
  const frame: HilosTableFrame = {
    title: HILOS_MAINTENANCE_NODES_COPY.title,
    columns: HILOS_MAINTENANCE_NODES_COLUMNS,
    empty: { title: HILOS_MAINTENANCE_NODES_COPY.empty },
  }
  const controller = new TableViewportController<HilosMaintenanceNodeRow>({
    resolve: resolveHilosMaintenanceNodeRow,
    frame,
    rowMessage: hilosMaintenanceNodeRowMessage,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        page,
        HILOS_MAINTENANCE_NODES_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        page,
        HILOS_MAINTENANCE_NODES_TABLE,
        rendered,
      ),
  })
  const teardown: Array<() => void> = []

  return {
    controller,
    start() {
      teardown.push(
        bindTableViewport(
          context.connection,
          context.scopes,
          { page, tableKey: HILOS_MAINTENANCE_NODES_TABLE },
          controller,
        ),
      )
    },
    dispose() {
      for (const off of teardown.splice(0)) off()
    },
  }
}
