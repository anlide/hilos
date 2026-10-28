import { HilosPages } from '../../routing/hilosPages.js'
import { readNumber, readString } from '../../state/fieldReaders.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { type HilosTableColumnOf } from '../../table/hilosTableColumn.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import {
  CHANGE_LOG_FILTER_TABLE,
  type HilosChangeLogContext,
} from './hilosChangeLog.js'
import { type HilosChangeLogTableTrigger } from './hilosChangeLogTable.js'

const CHANGE_LOG_TABLE_TRIGGERS_TABLE = 'hilosChangeLogTableTriggers'
const CHANGE_LOG_TABLE_TRIGGERS_SLOT = 'trigger'

/** Row payload key of the trigger's name. */
export const CHANGE_LOG_TABLE_TRIGGERS_NAME_FIELD = 'name'
/** Row payload key of the trigger's service owner. */
export const CHANGE_LOG_TABLE_TRIGGERS_OWNER_FIELD = 'owner'
/** Row payload key of the event firing the trigger. */
export const CHANGE_LOG_TABLE_TRIGGERS_EVENT_FIELD = 'event'
/** Row payload key of the trigger's journal destination. */
export const CHANGE_LOG_TABLE_TRIGGERS_WRITES_TO_FIELD = 'writesTo'
/** Row payload key of the migration introducing the trigger. */
export const CHANGE_LOG_TABLE_TRIGGERS_MIGRATION_FIELD = 'validFromMigration'
/** Row payload key of the trigger's source table. */
const CHANGE_LOG_TABLE_TRIGGERS_TABLE_FIELD = 'table'

/** One service trigger's public interface, without its SQL body. */
export interface HilosChangeLogTableTriggersRow extends HilosChangeLogTableTrigger {
  readonly rowKey: string
  readonly owner: 'service'
  readonly writesTo: 'journal'
}

/** Browser controller and mount lifecycle of one table's service triggers. */
export interface HilosChangeLogTableTriggersTable {
  readonly controller: TableViewportController<HilosChangeLogTableTriggersRow>
  start(): void
  dispose(): void
}

/**
 * Resolve one inline service trigger; its key belongs to the envelope.
 *
 * @param row Raw table row in the page scope.
 */
export function resolveHilosChangeLogTableTriggersRow(
  row: TableRow,
): HilosChangeLogTableTriggersRow {
  const raw = row.slots[CHANGE_LOG_TABLE_TRIGGERS_SLOT]
  const slot =
    typeof raw === 'object' && raw !== null && !Array.isArray(raw)
      ? (raw as Record<string, unknown>)
      : {}
  const event = readString(slot, CHANGE_LOG_TABLE_TRIGGERS_EVENT_FIELD)
  return {
    rowKey: String(row.rowKey),
    name: readString(slot, CHANGE_LOG_TABLE_TRIGGERS_NAME_FIELD),
    owner: 'service',
    event: event === 'UPDATE' || event === 'DELETE' ? event : 'INSERT',
    table: readString(slot, CHANGE_LOG_TABLE_TRIGGERS_TABLE_FIELD),
    writesTo: 'journal',
    validFromMigration: readNumber(
      slot,
      CHANGE_LOG_TABLE_TRIGGERS_MIGRATION_FIELD,
    ),
  }
}

/**
 * Build the service-trigger viewport over the selected table's Triggers page.
 *
 * @param context Connection and page scope.
 * @param options Table named by the page address.
 */
export function createHilosChangeLogTableTriggersTable(
  context: HilosChangeLogContext,
  options: { readonly table: string },
): HilosChangeLogTableTriggersTable {
  const columns: HilosTableColumnOf<HilosChangeLogTableTriggersRow>[] = [
    {
      key: CHANGE_LOG_TABLE_TRIGGERS_NAME_FIELD,
      label: 'Trigger',
      card: 'title',
    },
    { key: CHANGE_LOG_TABLE_TRIGGERS_OWNER_FIELD, label: 'Owner' },
    {
      key: CHANGE_LOG_TABLE_TRIGGERS_EVENT_FIELD,
      label: 'Fires',
      reads: [CHANGE_LOG_TABLE_TRIGGERS_TABLE_FIELD],
    },
    { key: CHANGE_LOG_TABLE_TRIGGERS_WRITES_TO_FIELD, label: 'Writes to' },
    { key: CHANGE_LOG_TABLE_TRIGGERS_MIGRATION_FIELD, label: 'In effect' },
  ]
  const controller =
    new TableViewportController<HilosChangeLogTableTriggersRow>({
      resolve: resolveHilosChangeLogTableTriggersRow,
      sendViewport: (descriptor) =>
        context.connection.sendTableViewport(
          HilosPages.CHANGE_LOG_TABLE_TRIGGERS,
          CHANGE_LOG_TABLE_TRIGGERS_TABLE,
          descriptor,
        ),
      sendRendered: (rendered) =>
        context.connection.sendTableRendered(
          HilosPages.CHANGE_LOG_TABLE_TRIGGERS,
          CHANGE_LOG_TABLE_TRIGGERS_TABLE,
          rendered,
        ),
      initialFilter: { [CHANGE_LOG_FILTER_TABLE]: options.table },
      frame: { columns },
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
            page: HilosPages.CHANGE_LOG_TABLE_TRIGGERS,
            tableKey: CHANGE_LOG_TABLE_TRIGGERS_TABLE,
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
