import { HilosPages } from '../../routing/hilosPages.js'
import { readString } from '../../state/fieldReaders.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { type HilosTableColumnOf } from '../../table/hilosTableColumn.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import { type HilosChangeLogContext } from './hilosChangeLog.js'
import { CHANGE_LOG_NOISY_COLUMNS_EMPTY } from './hilosChangeLogTablePolicies.js'

const CHANGE_LOG_NOISY_TABLE = 'hilosChangeLogNoisyColumns'
const CHANGE_LOG_NOISY_SLOT = 'noisyColumn'

/** Row payload key of the declaring table. */
const CHANGE_LOG_NOISY_TABLE_FIELD = 'table'
/** Row payload key of the noisy column's name. */
export const CHANGE_LOG_NOISY_COLUMN_FIELD = 'column'
/** Row payload key of the reason the column is noisy. */
export const CHANGE_LOG_NOISY_REASON_FIELD = 'reason'

/** One schema declaration, keyed by table and column. */
export interface HilosChangeLogNoisyColumnsRow {
  readonly rowKey: string
  readonly table: string
  readonly column: string
  readonly reason: string
}

/** Browser controller and mount lifecycle of the noisy-column list. */
export interface HilosChangeLogNoisyColumnsTable {
  readonly controller: TableViewportController<HilosChangeLogNoisyColumnsRow>
  start(): void
  dispose(): void
}

/**
 * Resolve one inline declaration; the row key belongs to the envelope.
 *
 * @param row Raw table row in the page scope.
 */
export function resolveHilosChangeLogNoisyColumnsRow(
  row: TableRow,
): HilosChangeLogNoisyColumnsRow {
  const raw = row.slots[CHANGE_LOG_NOISY_SLOT]
  const slot =
    typeof raw === 'object' && raw !== null && !Array.isArray(raw)
      ? (raw as Record<string, unknown>)
      : {}
  return {
    rowKey: String(row.rowKey),
    table: readString(slot, CHANGE_LOG_NOISY_TABLE_FIELD),
    column: readString(slot, CHANGE_LOG_NOISY_COLUMN_FIELD),
    reason: readString(slot, CHANGE_LOG_NOISY_REASON_FIELD),
  }
}

/**
 * Build the frozen schema viewport over the Noisy columns page.
 *
 * @param context Connection and page scope.
 */
export function createHilosChangeLogNoisyColumnsTable(
  context: HilosChangeLogContext,
): HilosChangeLogNoisyColumnsTable {
  const columns: HilosTableColumnOf<HilosChangeLogNoisyColumnsRow>[] = [
    {
      key: CHANGE_LOG_NOISY_COLUMN_FIELD,
      label: 'Column',
      card: 'title',
      reads: [CHANGE_LOG_NOISY_TABLE_FIELD],
    },
    { key: CHANGE_LOG_NOISY_REASON_FIELD, label: 'Why noisy' },
  ]
  const controller = new TableViewportController<HilosChangeLogNoisyColumnsRow>(
    {
      resolve: resolveHilosChangeLogNoisyColumnsRow,
      sendViewport: (descriptor) =>
        context.connection.sendTableViewport(
          HilosPages.CHANGE_LOG_NOISY,
          CHANGE_LOG_NOISY_TABLE,
          descriptor,
        ),
      sendRendered: (rendered) =>
        context.connection.sendTableRendered(
          HilosPages.CHANGE_LOG_NOISY,
          CHANGE_LOG_NOISY_TABLE,
          rendered,
        ),
      frame: { columns, empty: { title: CHANGE_LOG_NOISY_COLUMNS_EMPTY } },
    },
  )
  const teardown: Array<() => void> = []
  return {
    controller,
    start() {
      teardown.push(
        bindTableViewport(
          context.connection,
          context.scopes,
          {
            page: HilosPages.CHANGE_LOG_NOISY,
            tableKey: CHANGE_LOG_NOISY_TABLE,
          },
          controller,
        ),
      )
    },
    dispose() {
      for (const off of teardown.splice(0)) off()
    },
  }
}
