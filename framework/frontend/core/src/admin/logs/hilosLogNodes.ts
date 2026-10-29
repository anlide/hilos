// The overview's node table uses the ordinary page-scoped window store. The full
// overview header remains the source of the forecast and the takeout banner.

import { HilosPages } from '../../routing/hilosPages.js'
import {
  readBoolean,
  readNumberOrNull,
  readString,
} from '../../state/fieldReaders.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { type HilosTableColumnOf } from '../../table/hilosTableColumn.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import { type HilosLogsOverviewContext } from './hilosLogsOverview.js'

/** One node's log figures; an unreadable store leaves every measurement unknown. */
export interface HilosLogNodeRow {
  /** Stable node identity, also used as the table row key. */
  readonly nodeId: string
  /** Whether the node could read its log store. */
  readonly available: boolean
  /** Newest rotation in UTC epoch milliseconds, or null before any rotation. */
  readonly lastRotationAt: number | null
  /** Weight of all live streams, or null when unknown. */
  readonly liveBytes: number | null
  /** Weight of all archived streams, or null when unknown. */
  readonly archiveBytes: number | null
  /** Daily growth, or null until a full day has been measured. */
  readonly growthBytesPerDay: number | null
  /** Batches recommended for takeout, or null when unknown. */
  readonly batchesDueForTakeout: number | null
}

const NODES_TABLE = 'hilosLogNodes'
const NODE_SLOT = 'node'

/** Row payload key of the node identity. */
export const NODE_ID_FIELD = 'nodeId'

/** Row payload key of the store's availability. */
const NODE_AVAILABLE_FIELD = 'available'

/** Row payload key of the newest rotation in UTC epoch milliseconds. */
export const NODE_LAST_ROTATION_AT_FIELD = 'lastRotationAt'

/** Row payload key of the live stream weight. */
export const NODE_LIVE_BYTES_FIELD = 'liveBytes'

/** Row payload key of the archived stream weight. */
export const NODE_ARCHIVE_BYTES_FIELD = 'archiveBytes'

/** Row payload key of the daily growth. */
export const NODE_GROWTH_BYTES_PER_DAY_FIELD = 'growthBytesPerDay'

/** Row payload key of the batches recommended for takeout. */
export const NODE_BATCHES_DUE_FOR_TAKEOUT_FIELD = 'batchesDueForTakeout'

/** The overview connection plus the stores owning its node table window. */
export interface HilosLogNodesContext extends HilosLogsOverviewContext {
  /** The scope manager owning the page-scoped node rows. */
  readonly scopes: ScopeManager
}

/**
 * Resolve the inline node slot. Its identity is named nodeId, never id, so the
 * normalizer preserves the measurements rather than making an entity reference.
 *
 * @param row The normalized row from the page-scoped table store.
 */
export function resolveHilosLogNodeRow(row: TableRow): HilosLogNodeRow {
  const slot = row.slots[NODE_SLOT]
  const fields =
    typeof slot === 'object' && slot !== null && !Array.isArray(slot)
      ? (slot as Record<string, unknown>)
      : {}

  return {
    nodeId: readString(fields, NODE_ID_FIELD),
    available: readBoolean(fields, NODE_AVAILABLE_FIELD),
    lastRotationAt: readNumberOrNull(fields, NODE_LAST_ROTATION_AT_FIELD),
    liveBytes: readNumberOrNull(fields, NODE_LIVE_BYTES_FIELD),
    archiveBytes: readNumberOrNull(fields, NODE_ARCHIVE_BYTES_FIELD),
    growthBytesPerDay: readNumberOrNull(
      fields,
      NODE_GROWTH_BYTES_PER_DAY_FIELD,
    ),
    batchesDueForTakeout: readNumberOrNull(
      fields,
      NODE_BATCHES_DUE_FOR_TAKEOUT_FIELD,
    ),
  }
}

/** The node table controller and its connection binding lifecycle. */
export interface HilosLogNodesTable {
  /** The shared controller the view renders. */
  readonly controller: TableViewportController<HilosLogNodeRow>
  /** Bind the page's first window and later updates; no mount request is sent. */
  start(): void
  /** Release the connection binding on unmount. */
  dispose(): void
}

/**
 * Create the overview's node table. The backend declares its first window; later
 * windows follow the same viewport binding as every other admin table.
 *
 * @param context The overview connection and page scope stores.
 */
export function createHilosLogNodesTable(
  context: HilosLogNodesContext,
): HilosLogNodesTable {
  const columns: HilosTableColumnOf<HilosLogNodeRow>[] = [
    { key: NODE_ID_FIELD, label: 'Node', card: 'title' },
    {
      key: NODE_LAST_ROTATION_AT_FIELD,
      label: 'Last rotation',
      card: 'field',
      reads: [NODE_AVAILABLE_FIELD],
    },
    {
      key: NODE_LIVE_BYTES_FIELD,
      label: 'Live',
      card: 'field',
      headerClass: 'text-end',
      cellClass: 'text-end',
    },
    {
      key: NODE_ARCHIVE_BYTES_FIELD,
      label: 'Archive',
      card: 'field',
      headerClass: 'text-end',
      cellClass: 'text-end',
    },
    {
      key: NODE_GROWTH_BYTES_PER_DAY_FIELD,
      label: 'Per day',
      card: 'field',
      headerClass: 'text-end',
      cellClass: 'text-end',
    },
    {
      key: NODE_BATCHES_DUE_FOR_TAKEOUT_FIELD,
      label: 'To take out',
      card: 'field',
      headerClass: 'text-end',
      cellClass: 'text-end',
    },
  ]
  const frame: HilosTableFrame = {
    title: 'By node',
    subtitle:
      'A journal is written on the node the event happened on and stays there. The tiles above add every node together; here you can see whose journal is growing faster.',
    columns,
  }
  const controller = new TableViewportController<HilosLogNodeRow>({
    resolve: resolveHilosLogNodeRow,
    frame,
    rowMessage: (row) =>
      row.available
        ? null
        : {
            fromColumn: NODE_LAST_ROTATION_AT_FIELD,
            text: 'No data — this node could not read its log store',
          },
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.LOGS,
        NODES_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        HilosPages.LOGS,
        NODES_TABLE,
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
          { page: HilosPages.LOGS, tableKey: NODES_TABLE },
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
