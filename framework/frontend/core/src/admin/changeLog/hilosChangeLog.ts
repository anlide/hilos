import { type HilosConnection } from '../../connection/HilosConnection.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { type Hideable, isHiddenValue } from '../../state/hiddenValue.js'
import { type HilosTableFilterOption } from '../../table/tableFrame.js'

/** Filter key selecting a journaled table. */
export const CHANGE_LOG_FILTER_TABLE = 'table'
/** Filter key selecting a relative UTC period. */
export const CHANGE_LOG_FILTER_PERIOD = 'period'

/** Periods understood by both journal tables. */
export const CHANGE_LOG_PERIODS: readonly HilosTableFilterOption[] = [
  { value: 'hour', label: 'Last hour' },
  { value: 'day', label: 'Last 24 hours' },
  { value: 'week', label: 'Last 7 days' },
  { value: 'month', label: 'Last 30 days' },
]

/** Receipt channels understood by the change-log reader. */
export const CHANGE_LOG_CHANNELS: readonly HilosTableFilterOption[] = [
  { value: 'web', label: 'Web' },
  { value: 'migration', label: 'Migration' },
  { value: 'agent', label: 'Agent' },
  { value: 'cli', label: 'CLI' },
  { value: 'cron', label: 'Cron' },
  { value: 'mcp', label: 'MCP' },
]

/** Explanation for a journal row without an application receipt. */
export const CHANGE_LOG_NO_RECEIPT_TEXT =
  'Changed past the application — no receipt'

/**
 * Show a journal moment in the reader's local date and time.
 *
 * @param at UTC journal moment.
 */
export function formatChangeLogTime(at: string): string {
  const date = new Date(at)
  return Number.isNaN(date.getTime())
    ? at
    : date.toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'medium',
      })
}

/**
 * Look up the label and Bootstrap icon of a known receipt channel.
 *
 * @param channel Receipt channel, or null for a bare journal entry.
 */
export function changeLogChannelBadge(
  channel: string | null,
): { label: string; icon: string } | null {
  const label = CHANGE_LOG_CHANNELS.find(
    (option) => option.value === channel,
  )?.label
  if (label === undefined) return null

  switch (channel) {
    case 'web':
      return { label, icon: 'bi-globe' }
    case 'migration':
      return { label, icon: 'bi-database-up' }
    case 'agent':
      return { label, icon: 'bi-cpu' }
    case 'cli':
      return { label, icon: 'bi-terminal' }
    case 'cron':
      return { label, icon: 'bi-alarm' }
    case 'mcp':
      return { label, icon: 'bi-robot' }
    default:
      return null
  }
}

/** Attribution fields carried by either journal row. */
export interface HilosChangeLogPersonFields {
  readonly receiptId: number | null
  readonly actorId: number | null
  readonly actorLabel: Hideable<string | null>
  readonly actorDeleted: boolean
  readonly subjectId: number | null
  readonly subjectLabel: Hideable<string | null>
  readonly subjectDeleted: boolean
  readonly channel: string | null
}

/** Shared connection and page store for the two journal viewports. */
export interface HilosChangeLogContext {
  readonly connection: HilosConnection
  readonly scopes: ScopeManager
}

/** One table summary in receipt first-touch order. */
export interface HilosChangeLogTouched {
  readonly table: string
  readonly records: number
  readonly recordKey: readonly (number | string | null)[] | null
  readonly mutation: 'create' | 'update' | 'delete' | null
  readonly changedFields: number | null
}

/**
 * Name the person at the keyboard, or the source of a receipt without one.
 *
 * @param row Row carrying receipt attribution.
 */
export function formatChangeLogWho(
  row: HilosChangeLogPersonFields,
): Hideable<string> {
  if (row.actorId !== null && isHiddenValue(row.actorLabel)) {
    return row.actorLabel
  }
  if (row.actorId !== null && row.actorLabel !== null) {
    return row.actorLabel
  }
  if (row.receiptId === null) {
    return 'Unknown'
  }
  return row.channel === 'web' ? 'Guest' : 'System'
}

/**
 * Name the person for whom the actor acted, when present.
 *
 * @param row Row carrying receipt attribution.
 */
export function formatChangeLogOnBehalfOf(
  row: HilosChangeLogPersonFields,
): Hideable<string> | null {
  return row.subjectId === null ? null : row.subjectLabel
}

/**
 * Label a record key, preserving a masked key from an anonymized restore.
 *
 * @param key Primary-key parts in column order, or null when masked.
 */
export function formatChangeLogRecordKey(
  key: readonly (number | string | null)[] | null,
): string {
  return key === null || key.length === 0
    ? '#?'
    : `#${key.map((part) => part ?? '?').join(', ')}`
}

/**
 * Put the journal's mutation name in past tense.
 *
 * @param mutation Mutation kind carried by the journal.
 */
export function formatChangeLogMutation(
  mutation: 'create' | 'update' | 'delete',
): 'created' | 'updated' | 'deleted' {
  switch (mutation) {
    case 'create':
      return 'created'
    case 'update':
      return 'updated'
    case 'delete':
      return 'deleted'
  }
}

/**
 * Summarize a receipt's touched tables in first-touch order.
 *
 * @param touched Reader summaries in receipt order.
 */
export function formatChangeLogTouched(
  touched: readonly HilosChangeLogTouched[],
): string {
  return touched
    .map((item) => {
      if (item.records !== 1) {
        return `${item.table} · ${item.records} records`
      }
      const parts = [
        `${item.table} ${formatChangeLogRecordKey(item.recordKey)}`,
      ]
      if (item.mutation !== null) {
        parts.push(formatChangeLogMutation(item.mutation))
      }
      if (item.mutation === 'update' && item.changedFields !== null) {
        parts.push(
          `${item.changedFields} ${item.changedFields === 1 ? 'field' : 'fields'}`,
        )
      }
      return parts.join(' · ')
    })
    .join(', ')
}
