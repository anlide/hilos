import { HilosPages } from '../../routing/hilosPages.js'
import {
  readBoolean,
  readHideableStringOrNull,
  readNumber,
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
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import {
  CHANGE_LOG_CHANNELS,
  CHANGE_LOG_FILTER_PERIOD,
  CHANGE_LOG_FILTER_TABLE,
  CHANGE_LOG_PERIODS,
  type HilosChangeLogContext,
  type HilosChangeLogPersonFields,
  type HilosChangeLogTouched,
} from './hilosChangeLog.js'

const CHANGE_LOG_FEED_TABLE = 'hilosChangeLogFeed'
const CHANGE_LOG_FEED_SLOT = 'feed'

/** Filter key selecting a receipt channel. */
export const CHANGE_LOG_FEED_FILTER_CHANNEL = 'channel'

/** Row payload key of the feed item kind. */
const CHANGE_LOG_FEED_KIND_FIELD = 'kind'
/** Row payload key of the receipt number. */
const CHANGE_LOG_FEED_RECEIPT_ID_FIELD = 'receiptId'
/** Row payload key of the bare journal entry number. */
const CHANGE_LOG_FEED_ENTRY_ID_FIELD = 'entryId'
/** Row payload key of the creation instant. */
export const CHANGE_LOG_FEED_CREATED_AT_FIELD = 'createdAt'
/** Row payload key of the actor number. */
const CHANGE_LOG_FEED_ACTOR_ID_FIELD = 'actorId'
/** Row payload key of the actor label. */
export const CHANGE_LOG_FEED_ACTOR_LABEL_FIELD = 'actorLabel'
/** Row payload key of the deleted-actor flag. */
const CHANGE_LOG_FEED_ACTOR_DELETED_FIELD = 'actorDeleted'
/** Row payload key of the subject number. */
const CHANGE_LOG_FEED_SUBJECT_ID_FIELD = 'subjectId'
/** Row payload key of the subject label. */
const CHANGE_LOG_FEED_SUBJECT_LABEL_FIELD = 'subjectLabel'
/** Row payload key of the deleted-subject flag. */
const CHANGE_LOG_FEED_SUBJECT_DELETED_FIELD = 'subjectDeleted'
/** Row payload key of the receipt channel. */
const CHANGE_LOG_FEED_CHANNEL_FIELD = 'channel'
/** Row payload key of the server action. */
export const CHANGE_LOG_FEED_ACTION_FIELD = 'action'
/** Row payload key of the touched table summaries. */
export const CHANGE_LOG_FEED_TOUCHED_FIELD = 'touched'

/** Row payload key of a touched table name. */
const CHANGE_LOG_TOUCHED_TABLE_FIELD = 'table'
/** Row payload key of a touched table's record count. */
const CHANGE_LOG_TOUCHED_RECORDS_FIELD = 'records'
/** Row payload key of a single touched record's key. */
const CHANGE_LOG_TOUCHED_RECORD_KEY_FIELD = 'recordKey'
/** Row payload key of a single touched record's mutation. */
const CHANGE_LOG_TOUCHED_MUTATION_FIELD = 'mutation'
/** Row payload key of a single touched record's changed-field count. */
const CHANGE_LOG_TOUCHED_CHANGED_FIELDS_FIELD = 'changedFields'

/** One nonempty receipt or one unattributed journal entry. */
export interface HilosChangeLogFeedRow extends HilosChangeLogPersonFields {
  readonly rowKey: string
  readonly kind: 'receipt' | 'entry'
  readonly entryId: number | null
  readonly createdAt: string
  readonly action: string | null
  readonly touched: readonly HilosChangeLogTouched[]
}

/** Browser controller and mount lifecycle of the feed. */
export interface HilosChangeLogFeedTable {
  readonly controller: TableViewportController<HilosChangeLogFeedRow>
  start(): void
  dispose(): void
}

/** Selectable table names supplied by the dashboard page. */
export interface HilosChangeLogFeedOptions {
  readonly tables: () => readonly string[]
}

/** Read a row slot as an inline record, or an empty record if malformed. */
function recordSlot(slot: unknown): Record<string, unknown> {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : {}
}

/** Read a nullable record key from a touched summary. */
function recordKey(value: unknown): readonly (number | string | null)[] | null {
  return Array.isArray(value) &&
    value.every(
      (part) =>
        part === null || typeof part === 'number' || typeof part === 'string',
    )
    ? value
    : null
}

/** Read the receipt summary list, dropping malformed elements. */
function touchedList(value: unknown): readonly HilosChangeLogTouched[] {
  if (!Array.isArray(value)) {
    return []
  }
  return value
    .filter(
      (item): item is Record<string, unknown> =>
        typeof item === 'object' && item !== null && !Array.isArray(item),
    )
    .map((item): HilosChangeLogTouched => {
      const mutation = readStringOrNull(item, CHANGE_LOG_TOUCHED_MUTATION_FIELD)
      return {
        table: readString(item, CHANGE_LOG_TOUCHED_TABLE_FIELD),
        records: readNumber(item, CHANGE_LOG_TOUCHED_RECORDS_FIELD),
        recordKey: recordKey(item[CHANGE_LOG_TOUCHED_RECORD_KEY_FIELD]),
        mutation:
          mutation === 'create' ||
          mutation === 'update' ||
          mutation === 'delete'
            ? mutation
            : null,
        changedFields: readNumberOrNull(
          item,
          CHANGE_LOG_TOUCHED_CHANGED_FIELDS_FIELD,
        ),
      }
    })
}

/**
 * Resolve one inline feed slot; the row key belongs to the fragment.
 *
 * @param row Raw table row in the page scope.
 */
export function resolveHilosChangeLogFeedRow(
  row: TableRow,
): HilosChangeLogFeedRow {
  const slot = recordSlot(row.slots[CHANGE_LOG_FEED_SLOT])
  return {
    rowKey: String(row.rowKey),
    kind:
      readString(slot, CHANGE_LOG_FEED_KIND_FIELD) === 'receipt'
        ? 'receipt'
        : 'entry',
    receiptId: readNumberOrNull(slot, CHANGE_LOG_FEED_RECEIPT_ID_FIELD),
    entryId: readNumberOrNull(slot, CHANGE_LOG_FEED_ENTRY_ID_FIELD),
    createdAt: readString(slot, CHANGE_LOG_FEED_CREATED_AT_FIELD),
    actorId: readNumberOrNull(slot, CHANGE_LOG_FEED_ACTOR_ID_FIELD),
    actorLabel: readHideableStringOrNull(
      slot,
      CHANGE_LOG_FEED_ACTOR_LABEL_FIELD,
    ),
    actorDeleted: readBoolean(slot, CHANGE_LOG_FEED_ACTOR_DELETED_FIELD),
    subjectId: readNumberOrNull(slot, CHANGE_LOG_FEED_SUBJECT_ID_FIELD),
    subjectLabel: readHideableStringOrNull(
      slot,
      CHANGE_LOG_FEED_SUBJECT_LABEL_FIELD,
    ),
    subjectDeleted: readBoolean(slot, CHANGE_LOG_FEED_SUBJECT_DELETED_FIELD),
    channel: readStringOrNull(slot, CHANGE_LOG_FEED_CHANNEL_FIELD),
    action: readStringOrNull(slot, CHANGE_LOG_FEED_ACTION_FIELD),
    touched: touchedList(slot[CHANGE_LOG_FEED_TOUCHED_FIELD]),
  }
}

/**
 * Build the feed viewport over the change-log dashboard page.
 *
 * @param context Connection and page scope.
 * @param options Current journaled table names for the filter.
 */
export function createHilosChangeLogFeedTable(
  context: HilosChangeLogContext,
  options: HilosChangeLogFeedOptions,
): HilosChangeLogFeedTable {
  const columns: HilosTableColumnOf<HilosChangeLogFeedRow>[] = [
    {
      key: CHANGE_LOG_FEED_CREATED_AT_FIELD,
      label: 'When',
      card: 'title',
      cellClass: 'text-nowrap',
    },
    {
      key: CHANGE_LOG_FEED_ACTOR_LABEL_FIELD,
      label: 'Who',
      reads: [
        CHANGE_LOG_FEED_ACTOR_ID_FIELD,
        CHANGE_LOG_FEED_ACTOR_DELETED_FIELD,
        CHANGE_LOG_FEED_SUBJECT_ID_FIELD,
        CHANGE_LOG_FEED_SUBJECT_LABEL_FIELD,
        CHANGE_LOG_FEED_SUBJECT_DELETED_FIELD,
        CHANGE_LOG_FEED_CHANNEL_FIELD,
        CHANGE_LOG_FEED_KIND_FIELD,
      ],
    },
    {
      key: CHANGE_LOG_FEED_ACTION_FIELD,
      label: 'Action',
      reads: [CHANGE_LOG_FEED_KIND_FIELD],
    },
    { key: CHANGE_LOG_FEED_TOUCHED_FIELD, label: 'Changed' },
    {
      key: HILOS_TABLE_ACTIONS_KEY,
      label: '',
      reads: [
        CHANGE_LOG_FEED_KIND_FIELD,
        CHANGE_LOG_FEED_RECEIPT_ID_FIELD,
        CHANGE_LOG_FEED_ENTRY_ID_FIELD,
      ],
    },
  ]
  const frame: HilosTableFrame = {
    title: 'Recent actions',
    search: { placeholder: 'Who…' },
    filters: [
      {
        kind: 'select',
        key: CHANGE_LOG_FEED_FILTER_CHANNEL,
        label: 'Channel',
        options: () => CHANGE_LOG_CHANNELS,
      },
      {
        kind: 'select',
        key: CHANGE_LOG_FILTER_TABLE,
        label: 'Table',
        options: () =>
          options.tables().map((table) => ({ value: table, label: table })),
      },
      {
        kind: 'select',
        key: CHANGE_LOG_FILTER_PERIOD,
        label: 'Period',
        options: () => CHANGE_LOG_PERIODS,
        anyLabel: 'All time',
      },
    ],
    columns,
  }
  const controller = new TableViewportController<HilosChangeLogFeedRow>({
    resolve: resolveHilosChangeLogFeedRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.CHANGE_LOG,
        CHANGE_LOG_FEED_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        HilosPages.CHANGE_LOG,
        CHANGE_LOG_FEED_TABLE,
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
          { page: HilosPages.CHANGE_LOG, tableKey: CHANGE_LOG_FEED_TABLE },
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
