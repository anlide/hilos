import { z } from 'zod'

import { type HilosChangeLogHistoryRow } from './hilosChangeLogHistory.js'
import { changeLogFieldValue } from './hilosChangeLogReceipt.js'
import { CHANGE_LOG_COLUMN_MODES } from './hilosChangeLogTablePolicies.js'
import { type HilosChangeLogColumnCounts } from './hilosChangeLogTables.js'

/** Page-data section containing one table's declaration and journal triggers. */
export const CHANGE_LOG_TABLE_SECTION = 'changeLogTable'
/** Route parameter carrying the live table name. */
export const CHANGE_LOG_TABLE_ROUTE_PARAM = 'tableId'
/** Optional route parameter narrowing the history to one record. */
export const CHANGE_LOG_RECORD_ROUTE_PARAM = 'record'

/** One live table's journal declaration, columns, and service triggers. */
export const changeLogTableSchema = z.object({
  name: z.string().min(1),
  owner: z.enum(['framework', 'project']),
  journaled: z.boolean(),
  changesLast24h: z.number().int().nullable(),
  lastChangeAt: z.string().nullable(),
  columns: z.array(
    z.object({
      name: z.string().min(1),
      sqlType: z.string().min(1),
      mode: z
        .enum(['value', 'personal', 'secret', 'noise', 'binary', 'record_key'])
        .nullable(),
      storage: z.enum(['inline', 'long', 'fact', 'ignored']).nullable(),
      noiseReason: z.string().nullable(),
    }),
  ),
  triggers: z.array(
    z.object({
      name: z.string().min(1),
      event: z.enum(['INSERT', 'UPDATE', 'DELETE']),
      table: z.string().min(1),
      validFromMigration: z.number().int(),
    }),
  ),
})

/** Validated declaration of one live table. */
export type HilosChangeLogTableDetail = z.infer<typeof changeLogTableSchema>
/** One column of the table in live-schema order. */
export type HilosChangeLogTableColumn =
  HilosChangeLogTableDetail['columns'][number]
/** One service trigger writing the table's journal. */
export type HilosChangeLogTableTrigger =
  HilosChangeLogTableDetail['triggers'][number]

/** Texts of one service trigger's row, without its body. */
export interface HilosChangeLogTriggerText {
  readonly fires: string
  readonly writesTo: string
  readonly validFrom: string
}

/** Words of the Tracked table page. */
export const CHANGE_LOG_TABLE_COPY = {
  journaled: 'Journaled',
  untracked:
    "The entity has no journal label — this table's history is not written.",
  columns: 'Columns',
  column: 'Column',
  storedAs: 'Stored as',
  inJournal: 'In the journal',
  noisyNote: 'An update touching only noisy columns is not journaled.',
  triggers: 'Journal service triggers',
  triggersLead:
    "The generator builds three per table from the entity's journal label. They only write the journal and never change the table's data.",
  trigger: 'Trigger',
  owner: 'Owner',
  service: 'Service',
  fires: 'Fires',
  writesTo: 'Writes to',
  validFrom: 'In effect',
  triggersFootnote:
    'A trigger is shown by its interface, without its body. Each has its own file with the latest version, laid out by the generator. After the migrations a node recreates every trigger from its file, so a database copy carries none. A trigger in the database without a file stops the node from starting.',
  onlyRecord: 'Only record',
  showAllRecords: 'Show all records',
  historyNote:
    'A record number narrows the history to that record. A receipt shows the whole action, including what it changed in other tables.',
  receipt: 'Receipt',
  noReceipt: 'no receipt',
  pastApplication: 'change past the application',
} as const

/**
 * Validate the page-data section without changing declared names or modes.
 *
 * @param raw Untrusted page-data section.
 */
export function readHilosChangeLogTable(
  raw: unknown,
): HilosChangeLogTableDetail | null {
  const parsed = changeLogTableSchema.safeParse(raw)
  return parsed.success ? parsed.data : null
}

/**
 * Find the earliest migration among the table's service triggers.
 *
 * @param detail Validated table declaration.
 */
export function changeLogTableJournalSince(
  detail: HilosChangeLogTableDetail,
): number | null {
  let since: number | null = null
  for (const trigger of detail.triggers) {
    if (since === null || trigger.validFromMigration < since) {
      since = trigger.validFromMigration
    }
  }
  return since
}

/**
 * Count declared journal modes, excluding record keys and untracked columns.
 *
 * @param detail Validated table declaration.
 */
export function changeLogTableColumnCounts(
  detail: HilosChangeLogTableDetail,
): HilosChangeLogColumnCounts {
  const counts = { value: 0, personal: 0, binary: 0, secret: 0, noise: 0 }
  for (const { mode } of detail.columns) {
    if (mode !== null && mode !== 'record_key') counts[mode]++
  }
  return counts
}

/**
 * Put a record key into its route segment; the router encodes it once more.
 *
 * @param key Unmasked record key parts in primary-key order.
 */
export function changeLogRecordRouteParam(
  key: readonly (number | string)[],
): string {
  return key.map((part) => encodeURIComponent(String(part))).join(',')
}

/**
 * Split the record segment back into key parts; a part that cannot be decoded
 * travels as it is, and the server judges it.
 *
 * @param raw Route parameter as the router decoded it, if present.
 */
export function readChangeLogRecordRouteParam(
  raw: string | undefined,
): string[] | null {
  if (raw === undefined || raw === '') return null
  return raw.split(',').map((part) => {
    try {
      return decodeURIComponent(part)
    } catch {
      return part
    }
  })
}

/**
 * Name how a value column is stored, by its storage and SQL type.
 *
 * @param column Column of the table section.
 */
export function formatChangeLogColumnStorage(
  column: HilosChangeLogTableColumn,
): string {
  if (column.mode !== 'value') return '—'
  if (column.storage === 'long') return 'Long text'
  switch (column.sqlType) {
    // The framework and the demos declare TINYINT only as TINYINT(1) flags,
    // and the reader hands the type over without its length.
    case 'tinyint':
      return 'Yes / no'
    case 'smallint':
    case 'mediumint':
    case 'int':
    case 'bigint':
    case 'decimal':
    case 'float':
    case 'double':
      return 'Number'
    case 'char':
    case 'varchar':
      return 'String'
    case 'date':
      return 'Date'
    case 'datetime':
    case 'timestamp':
      return 'Date and time'
    case 'time':
      return 'Time'
    case 'year':
      return 'Year'
    case 'enum':
    case 'set':
      return 'Choice'
    default:
      return column.sqlType
  }
}

/**
 * Find the reference card of a column's journal mode.
 *
 * @param mode Declared mode, or null for an untracked table.
 */
export function changeLogColumnModeCard(
  mode: HilosChangeLogTableColumn['mode'],
): (typeof CHANGE_LOG_COLUMN_MODES)[number] | null {
  if (mode === null) return null
  return CHANGE_LOG_COLUMN_MODES.find(({ key }) => key === mode) ?? null
}

/**
 * Describe a service trigger by its interface.
 *
 * @param trigger Service trigger of the table section.
 */
export function formatChangeLogTrigger(
  trigger: HilosChangeLogTableTrigger,
): HilosChangeLogTriggerText {
  return {
    fires: `AFTER ${trigger.event} on ${trigger.table}`,
    writesTo: 'Journal',
    validFrom: `from migration ${String(trigger.validFromMigration).padStart(3, '0')}`,
  }
}

/**
 * Draw the changes cell of a history row; a side without a value to show is
 * named once.
 *
 * @param row Resolved history row.
 */
export function formatChangeLogHistoryChanges(
  row: HilosChangeLogHistoryRow,
): string[] {
  switch (row.mutation) {
    case 'create':
      return ['Created as a fact']
    case 'delete':
      return [
        row.receiptId === null
          ? 'Row snapshot'
          : 'Row snapshot — in the receipt',
      ]
    case 'update':
      return row.changes.map((change) => {
        const before = changeLogFieldValue(change, 'old')
        const after = changeLogFieldValue(change, 'new')
        return before.kind === after.kind &&
          (before.kind === 'fact' || before.kind === 'omitted')
          ? `${change.field}: ${before.text}`
          : `${change.field}: ${before.text} → ${after.text}`
      })
  }
}
