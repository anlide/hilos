// SCAFFOLD: the Tracked tables view mounts this controller in HIL-1461.

import { HilosPages } from '../../routing/hilosPages.js'
import {
  readBoolean,
  readNumberOrNull,
  readString,
  readStringOrNull,
} from '../../state/fieldReaders.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import {
  HILOS_TABLE_ACTIONS_KEY,
  type HilosTableColumnOf,
} from '../../table/hilosTableColumn.js'
import {
  type HilosTableFilterOption,
  type HilosTableFrame,
} from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import {
  formatChangeLogTime,
  type HilosChangeLogContext,
} from './hilosChangeLog.js'

const CHANGE_LOG_TABLES_TABLE = 'hilosChangeLogTables'
const CHANGE_LOG_TABLES_SLOT = 'table'

/** Filter key selecting tables by their journal declaration. */
export const CHANGE_LOG_TABLES_FILTER_JOURNAL = 'journal'

/** Journal declarations understood by the tables viewport. */
export const CHANGE_LOG_JOURNAL_OPTIONS: readonly HilosTableFilterOption[] = [
  { value: 'tracked', label: 'Tracked' },
  { value: 'untracked', label: 'Untracked' },
]

/** Row payload key of the live table name. */
export const CHANGE_LOG_TABLES_NAME_FIELD = 'name'
/** Row payload key of the table's owner. */
export const CHANGE_LOG_TABLES_OWNER_FIELD = 'owner'
/** Row payload key of the table's journal declaration. */
export const CHANGE_LOG_TABLES_JOURNALED_FIELD = 'journaled'
/** Row payload key of the column counts by journal mode. */
export const CHANGE_LOG_TABLES_COLUMNS_FIELD = 'columns'
/** Row payload key of the writes in the last 24 hours. */
export const CHANGE_LOG_TABLES_CHANGES_FIELD = 'changesLast24h'
/** Row payload key of the last write instant. */
export const CHANGE_LOG_TABLES_LAST_CHANGE_FIELD = 'lastChangeAt'

/** Row payload key of the value-mode column count. */
const CHANGE_LOG_COLUMNS_VALUE_FIELD = 'value'
/** Row payload key of the personal-mode column count. */
const CHANGE_LOG_COLUMNS_PERSONAL_FIELD = 'personal'
/** Row payload key of the binary-mode column count. */
const CHANGE_LOG_COLUMNS_BINARY_FIELD = 'binary'
/** Row payload key of the secret-mode column count. */
const CHANGE_LOG_COLUMNS_SECRET_FIELD = 'secret'
/** Row payload key of the noise-mode column count. */
const CHANGE_LOG_COLUMNS_NOISE_FIELD = 'noise'

/** Explanation shown below the name of an untracked table. */
export const CHANGE_LOG_TABLES_UNTRACKED_NOTE = 'No journal label — not written'

/** Column counts excluding the record key, kept in their declared modes. */
export interface HilosChangeLogColumnCounts {
  readonly value: number
  readonly personal: number
  readonly binary: number
  readonly secret: number
  readonly noise: number
}

/** One live table's journal declaration and activity, keyed by its name. */
export interface HilosChangeLogTablesRow {
  readonly rowKey: string
  readonly name: string
  readonly owner: 'framework' | 'project'
  readonly journaled: boolean
  readonly columns: HilosChangeLogColumnCounts | null
  readonly changesLast24h: number | null
  readonly lastChangeAt: string | null
}

/** Browser controller and mount lifecycle of the tables list. */
export interface HilosChangeLogTablesTable {
  readonly controller: TableViewportController<HilosChangeLogTablesRow>
  start(): void
  dispose(): void
}

/** Read a row slot as an inline record, or an empty record if malformed. */
function recordSlot(slot: unknown): Record<string, unknown> {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : {}
}

/** Read all five column counts, or null when any count is malformed. */
function columnCounts(columns: unknown): HilosChangeLogColumnCounts | null {
  const slot = recordSlot(columns)
  const value = readNumberOrNull(slot, CHANGE_LOG_COLUMNS_VALUE_FIELD)
  const personal = readNumberOrNull(slot, CHANGE_LOG_COLUMNS_PERSONAL_FIELD)
  const binary = readNumberOrNull(slot, CHANGE_LOG_COLUMNS_BINARY_FIELD)
  const secret = readNumberOrNull(slot, CHANGE_LOG_COLUMNS_SECRET_FIELD)
  const noise = readNumberOrNull(slot, CHANGE_LOG_COLUMNS_NOISE_FIELD)
  return value === null ||
    personal === null ||
    binary === null ||
    secret === null ||
    noise === null
    ? null
    : { value, personal, binary, secret, noise }
}

/**
 * Resolve one inline table slot; the row key belongs to the fragment.
 *
 * @param row Raw table row in the page scope.
 */
export function resolveHilosChangeLogTablesRow(
  row: TableRow,
): HilosChangeLogTablesRow {
  const slot = recordSlot(row.slots[CHANGE_LOG_TABLES_SLOT])
  return {
    rowKey: String(row.rowKey),
    name: readString(slot, CHANGE_LOG_TABLES_NAME_FIELD),
    owner:
      readString(slot, CHANGE_LOG_TABLES_OWNER_FIELD) === 'project'
        ? 'project'
        : 'framework',
    journaled: readBoolean(slot, CHANGE_LOG_TABLES_JOURNALED_FIELD),
    columns: columnCounts(slot[CHANGE_LOG_TABLES_COLUMNS_FIELD]),
    changesLast24h: readNumberOrNull(slot, CHANGE_LOG_TABLES_CHANGES_FIELD),
    lastChangeAt: readStringOrNull(slot, CHANGE_LOG_TABLES_LAST_CHANGE_FIELD),
  }
}

/**
 * Label the owner of a live table.
 *
 * @param owner Framework or project declaration.
 */
export function formatChangeLogTableOwner(
  owner: HilosChangeLogTablesRow['owner'],
): 'Framework' | 'Project' {
  return owner === 'project' ? 'Project' : 'Framework'
}

/**
 * Summarize column modes, combining personal and binary columns as facts.
 *
 * @param columns Declared counts, or null for an untracked table.
 */
export function formatChangeLogColumnCounts(
  columns: HilosChangeLogColumnCounts | null,
): string {
  if (columns === null) return '—'

  const parts: string[] = []
  if (columns.value > 0) parts.push(`with value ${columns.value}`)
  const facts = columns.personal + columns.binary
  if (facts > 0) parts.push(`as fact ${facts}`)
  if (columns.noise > 0) parts.push(`noisy ${columns.noise}`)
  if (columns.secret > 0) parts.push(`secret ${columns.secret}`)
  return parts.length === 0 ? '—' : parts.join(' · ')
}

/**
 * Label the writes in the last 24 hours, preserving a tracked table's zero.
 *
 * @param n Recent write count, or null for an untracked table.
 */
export function formatChangeLogTableChanges(n: number | null): string {
  return n === null ? '—' : String(n)
}

/**
 * Show the last write in local time, or a dash when none was recorded.
 *
 * @param at UTC journal moment, or null without a write.
 */
export function formatChangeLogTableLastChange(at: string | null): string {
  return at === null ? '—' : formatChangeLogTime(at)
}

/**
 * Build the tables viewport over the Tracked tables page.
 *
 * @param context Connection and page scope.
 */
export function createHilosChangeLogTablesTable(
  context: HilosChangeLogContext,
): HilosChangeLogTablesTable {
  const columns: HilosTableColumnOf<HilosChangeLogTablesRow>[] = [
    {
      key: CHANGE_LOG_TABLES_NAME_FIELD,
      label: 'Table',
      sortable: true,
      card: 'title',
      reads: [CHANGE_LOG_TABLES_JOURNALED_FIELD],
    },
    { key: CHANGE_LOG_TABLES_OWNER_FIELD, label: 'Owner' },
    {
      key: CHANGE_LOG_TABLES_COLUMNS_FIELD,
      label: 'Journaled columns',
      reads: [CHANGE_LOG_TABLES_JOURNALED_FIELD],
    },
    {
      key: CHANGE_LOG_TABLES_CHANGES_FIELD,
      label: 'Last 24 hours',
      sortable: true,
      headerClass: 'text-end',
      cellClass: 'text-end',
    },
    {
      key: CHANGE_LOG_TABLES_LAST_CHANGE_FIELD,
      label: 'Last change',
      sortable: true,
      cellClass: 'text-nowrap',
    },
    {
      key: HILOS_TABLE_ACTIONS_KEY,
      label: '',
      reads: [CHANGE_LOG_TABLES_JOURNALED_FIELD, CHANGE_LOG_TABLES_NAME_FIELD],
    },
  ]
  const frame: HilosTableFrame = {
    title: 'Tables',
    search: { placeholder: 'Table…' },
    filters: [
      {
        kind: 'select',
        key: CHANGE_LOG_TABLES_FILTER_JOURNAL,
        label: 'Journal label',
        options: () => CHANGE_LOG_JOURNAL_OPTIONS,
        anyLabel: 'All',
      },
    ],
    columns,
  }
  const controller = new TableViewportController<HilosChangeLogTablesRow>({
    resolve: resolveHilosChangeLogTablesRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.CHANGE_LOG_TABLES,
        CHANGE_LOG_TABLES_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        HilosPages.CHANGE_LOG_TABLES,
        CHANGE_LOG_TABLES_TABLE,
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
            page: HilosPages.CHANGE_LOG_TABLES,
            tableKey: CHANGE_LOG_TABLES_TABLE,
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
