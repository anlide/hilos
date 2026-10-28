import { HilosPages } from '../../routing/hilosPages.js'
import { readString, readStringOrNull } from '../../state/fieldReaders.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { type HilosTableColumnOf } from '../../table/hilosTableColumn.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import {
  CHANGE_LOG_FILTER_TABLE,
  type HilosChangeLogContext,
} from './hilosChangeLog.js'
import { type HilosChangeLogTableColumn } from './hilosChangeLogTable.js'

const CHANGE_LOG_TABLE_COLUMNS_TABLE = 'hilosChangeLogTableColumns'
const CHANGE_LOG_TABLE_COLUMNS_SLOT = 'column'

/** Row payload key of the column's name. */
export const CHANGE_LOG_TABLE_COLUMNS_NAME_FIELD = 'name'
/** Row payload key of the column's storage. */
export const CHANGE_LOG_TABLE_COLUMNS_STORAGE_FIELD = 'storage'
/** Row payload key of the column's journal mode. */
export const CHANGE_LOG_TABLE_COLUMNS_MODE_FIELD = 'mode'
/** Row payload key of the column's SQL type. */
const CHANGE_LOG_TABLE_COLUMNS_SQL_TYPE_FIELD = 'sqlType'
/** Row payload key of the reason a column is noisy. */
const CHANGE_LOG_TABLE_COLUMNS_NOISE_REASON_FIELD = 'noiseReason'

/** One live-schema column, keyed by its envelope within the selected table. */
export interface HilosChangeLogTableColumnsRow extends HilosChangeLogTableColumn {
  readonly rowKey: string
}

/** Browser controller and mount lifecycle of one table's column declarations. */
export interface HilosChangeLogTableColumnsTable {
  readonly controller: TableViewportController<HilosChangeLogTableColumnsRow>
  start(): void
  dispose(): void
}

/**
 * Resolve one inline column declaration, preserving nullable journal metadata.
 *
 * @param row Raw table row in the page scope.
 */
export function resolveHilosChangeLogTableColumnsRow(
  row: TableRow,
): HilosChangeLogTableColumnsRow {
  const raw = row.slots[CHANGE_LOG_TABLE_COLUMNS_SLOT]
  const slot =
    typeof raw === 'object' && raw !== null && !Array.isArray(raw)
      ? (raw as Record<string, unknown>)
      : {}
  const mode = readStringOrNull(slot, CHANGE_LOG_TABLE_COLUMNS_MODE_FIELD)
  const storage = readStringOrNull(slot, CHANGE_LOG_TABLE_COLUMNS_STORAGE_FIELD)
  return {
    rowKey: String(row.rowKey),
    name: readString(slot, CHANGE_LOG_TABLE_COLUMNS_NAME_FIELD),
    sqlType: readString(slot, CHANGE_LOG_TABLE_COLUMNS_SQL_TYPE_FIELD),
    mode:
      mode === 'value' ||
      mode === 'personal' ||
      mode === 'secret' ||
      mode === 'noise' ||
      mode === 'binary' ||
      mode === 'record_key'
        ? mode
        : null,
    storage:
      storage === 'inline' ||
      storage === 'long' ||
      storage === 'fact' ||
      storage === 'ignored'
        ? storage
        : null,
    noiseReason: readStringOrNull(
      slot,
      CHANGE_LOG_TABLE_COLUMNS_NOISE_REASON_FIELD,
    ),
  }
}

/**
 * Build the schema-order viewport over the selected table's Columns page.
 *
 * @param context Connection and page scope.
 * @param options Table named by the page address.
 */
export function createHilosChangeLogTableColumnsTable(
  context: HilosChangeLogContext,
  options: { readonly table: string },
): HilosChangeLogTableColumnsTable {
  const columns: HilosTableColumnOf<HilosChangeLogTableColumnsRow>[] = [
    {
      key: CHANGE_LOG_TABLE_COLUMNS_NAME_FIELD,
      label: 'Column',
      card: 'title',
    },
    {
      key: CHANGE_LOG_TABLE_COLUMNS_STORAGE_FIELD,
      label: 'Stored as',
      reads: [
        CHANGE_LOG_TABLE_COLUMNS_MODE_FIELD,
        CHANGE_LOG_TABLE_COLUMNS_SQL_TYPE_FIELD,
      ],
    },
    { key: CHANGE_LOG_TABLE_COLUMNS_MODE_FIELD, label: 'In the journal' },
  ]
  const controller = new TableViewportController<HilosChangeLogTableColumnsRow>(
    {
      resolve: resolveHilosChangeLogTableColumnsRow,
      sendViewport: (descriptor) =>
        context.connection.sendTableViewport(
          HilosPages.CHANGE_LOG_TABLE_COLUMNS,
          CHANGE_LOG_TABLE_COLUMNS_TABLE,
          descriptor,
        ),
      sendRendered: (rendered) =>
        context.connection.sendTableRendered(
          HilosPages.CHANGE_LOG_TABLE_COLUMNS,
          CHANGE_LOG_TABLE_COLUMNS_TABLE,
          rendered,
        ),
      initialFilter: { [CHANGE_LOG_FILTER_TABLE]: options.table },
      frame: { columns },
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
            page: HilosPages.CHANGE_LOG_TABLE_COLUMNS,
            tableKey: CHANGE_LOG_TABLE_COLUMNS_TABLE,
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
