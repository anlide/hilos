import { HilosPages } from '../../routing/hilosPages.js'
import {
  readBoolean,
  readHideableStringOrNull,
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
  CHANGE_LOG_FILTER_PERIOD,
  CHANGE_LOG_FILTER_TABLE,
  CHANGE_LOG_PERIODS,
  type HilosChangeLogContext,
  type HilosChangeLogPersonFields,
} from './hilosChangeLog.js'

const CHANGE_LOG_HISTORY_TABLE = 'hilosChangeLogHistory'
const CHANGE_LOG_HISTORY_SLOT = 'history'

/** Filter key selecting one record from the page address. */
export const CHANGE_LOG_HISTORY_FILTER_RECORD = 'record'
/** Filter key selecting a changed column. */
export const CHANGE_LOG_HISTORY_FILTER_FIELD = 'field'

/** Row payload key of the change instant. */
export const CHANGE_LOG_HISTORY_CREATED_AT_FIELD = 'createdAt'
/** Row payload key of the changed record's primary key. */
export const CHANGE_LOG_HISTORY_RECORD_KEY_FIELD = 'recordKey'
/** Row payload key of the journal mutation. */
export const CHANGE_LOG_HISTORY_MUTATION_FIELD = 'mutation'
/** Row payload key of changed fields. */
export const CHANGE_LOG_HISTORY_CHANGES_FIELD = 'changes'
/** Row payload key of the receipt number. */
const CHANGE_LOG_HISTORY_RECEIPT_ID_FIELD = 'receiptId'
/** Row payload key of the actor number. */
const CHANGE_LOG_HISTORY_ACTOR_ID_FIELD = 'actorId'
/** Row payload key of the actor label. */
export const CHANGE_LOG_HISTORY_ACTOR_LABEL_FIELD = 'actorLabel'
/** Row payload key of the deleted-actor flag. */
const CHANGE_LOG_HISTORY_ACTOR_DELETED_FIELD = 'actorDeleted'
/** Row payload key of the subject number. */
const CHANGE_LOG_HISTORY_SUBJECT_ID_FIELD = 'subjectId'
/** Row payload key of the subject label. */
const CHANGE_LOG_HISTORY_SUBJECT_LABEL_FIELD = 'subjectLabel'
/** Row payload key of the deleted-subject flag. */
const CHANGE_LOG_HISTORY_SUBJECT_DELETED_FIELD = 'subjectDeleted'
/** Row payload key of the receipt channel. */
const CHANGE_LOG_HISTORY_CHANNEL_FIELD = 'channel'

/** Row payload key of a changed field name. */
const CHANGE_LOG_CHANGE_FIELD_FIELD = 'field'
/** Row payload key of a changed field's value kind. */
const CHANGE_LOG_CHANGE_KIND_FIELD = 'kind'
/** Row payload key of the old-side presence flag. */
const CHANGE_LOG_CHANGE_OLD_PRESENT_FIELD = 'oldPresent'
/** Row payload key of the new-side presence flag. */
const CHANGE_LOG_CHANGE_NEW_PRESENT_FIELD = 'newPresent'
/** Row payload key of the old inline value. */
const CHANGE_LOG_CHANGE_OLD_VALUE_FIELD = 'oldValue'
/** Row payload key of the new inline value. */
const CHANGE_LOG_CHANGE_NEW_VALUE_FIELD = 'newValue'
/** Row payload key of the omitted-long-body flag. */
const CHANGE_LOG_CHANGE_BODY_OMITTED_FIELD = 'bodyOmitted'

/** One changed field from the journal reader, with long bodies omitted. */
export interface HilosChangeLogFieldChange {
  readonly field: string
  readonly kind: 'inline' | 'fact' | 'long'
  readonly oldPresent: boolean
  readonly newPresent: boolean
  readonly oldValue: string | null
  readonly newValue: string | null
  readonly bodyOmitted: boolean
}

/** One journal row in the history of a selected table. */
export interface HilosChangeLogHistoryRow extends HilosChangeLogPersonFields {
  readonly rowKey: number
  readonly createdAt: string
  readonly recordKey: readonly (number | string | null)[] | null
  readonly mutation: 'create' | 'update' | 'delete'
  readonly changes: readonly HilosChangeLogFieldChange[]
}

/** Browser controller and mount lifecycle of a table's history. */
export interface HilosChangeLogHistoryTable {
  readonly controller: TableViewportController<HilosChangeLogHistoryRow>
  start(): void
  dispose(): void
}

/** Table address and optional record address supplied by the page. */
export interface HilosChangeLogHistoryOptions {
  readonly table: string
  readonly record?: readonly (number | string)[] | null
  readonly fields: () => readonly string[]
}

/** Read a row slot as an inline record, or an empty record if malformed. */
function recordSlot(slot: unknown): Record<string, unknown> {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : {}
}

/** Read a nullable record key while keeping a masked part. */
function recordKey(value: unknown): readonly (number | string | null)[] | null {
  return Array.isArray(value) &&
    value.every(
      (part) =>
        part === null || typeof part === 'number' || typeof part === 'string',
    )
    ? value
    : null
}

/** Read the field-change list, dropping malformed elements. */
function changesList(value: unknown): readonly HilosChangeLogFieldChange[] {
  if (!Array.isArray(value)) {
    return []
  }
  return value
    .filter(
      (item): item is Record<string, unknown> =>
        typeof item === 'object' && item !== null && !Array.isArray(item),
    )
    .map((item): HilosChangeLogFieldChange => {
      const kind = readString(item, CHANGE_LOG_CHANGE_KIND_FIELD)
      return {
        field: readString(item, CHANGE_LOG_CHANGE_FIELD_FIELD),
        kind:
          kind === 'long' || kind === 'fact' || kind === 'inline'
            ? kind
            : 'fact',
        oldPresent: readBoolean(item, CHANGE_LOG_CHANGE_OLD_PRESENT_FIELD),
        newPresent: readBoolean(item, CHANGE_LOG_CHANGE_NEW_PRESENT_FIELD),
        oldValue: readStringOrNull(item, CHANGE_LOG_CHANGE_OLD_VALUE_FIELD),
        newValue: readStringOrNull(item, CHANGE_LOG_CHANGE_NEW_VALUE_FIELD),
        bodyOmitted: readBoolean(item, CHANGE_LOG_CHANGE_BODY_OMITTED_FIELD),
      }
    })
}

/**
 * Resolve one inline history slot; the row key belongs to the fragment.
 *
 * @param row Raw table row in the page scope.
 */
export function resolveHilosChangeLogHistoryRow(
  row: TableRow,
): HilosChangeLogHistoryRow {
  const slot = recordSlot(row.slots[CHANGE_LOG_HISTORY_SLOT])
  const mutation = readString(slot, CHANGE_LOG_HISTORY_MUTATION_FIELD)
  return {
    rowKey: Number(row.rowKey),
    createdAt: readString(slot, CHANGE_LOG_HISTORY_CREATED_AT_FIELD),
    recordKey: recordKey(slot[CHANGE_LOG_HISTORY_RECORD_KEY_FIELD]),
    mutation:
      mutation === 'create' || mutation === 'delete' ? mutation : 'update',
    changes: changesList(slot[CHANGE_LOG_HISTORY_CHANGES_FIELD]),
    receiptId: readNumberOrNull(slot, CHANGE_LOG_HISTORY_RECEIPT_ID_FIELD),
    actorId: readNumberOrNull(slot, CHANGE_LOG_HISTORY_ACTOR_ID_FIELD),
    actorLabel: readHideableStringOrNull(
      slot,
      CHANGE_LOG_HISTORY_ACTOR_LABEL_FIELD,
    ),
    actorDeleted: readBoolean(slot, CHANGE_LOG_HISTORY_ACTOR_DELETED_FIELD),
    subjectId: readNumberOrNull(slot, CHANGE_LOG_HISTORY_SUBJECT_ID_FIELD),
    subjectLabel: readHideableStringOrNull(
      slot,
      CHANGE_LOG_HISTORY_SUBJECT_LABEL_FIELD,
    ),
    subjectDeleted: readBoolean(slot, CHANGE_LOG_HISTORY_SUBJECT_DELETED_FIELD),
    channel: readStringOrNull(slot, CHANGE_LOG_HISTORY_CHANNEL_FIELD),
  }
}

/**
 * Build the history viewport with its table and optional record preset.
 *
 * @param context Connection and page scope.
 * @param options Addressed table, record, and field names.
 */
export function createHilosChangeLogHistoryTable(
  context: HilosChangeLogContext,
  options: HilosChangeLogHistoryOptions,
): HilosChangeLogHistoryTable {
  const columns: HilosTableColumnOf<HilosChangeLogHistoryRow>[] = [
    {
      key: CHANGE_LOG_HISTORY_CREATED_AT_FIELD,
      label: 'When',
      sortable: true,
      card: 'title',
      cellClass: 'text-nowrap',
    },
    { key: CHANGE_LOG_HISTORY_RECORD_KEY_FIELD, label: 'Record' },
    { key: CHANGE_LOG_HISTORY_MUTATION_FIELD, label: 'Operation' },
    { key: CHANGE_LOG_HISTORY_CHANGES_FIELD, label: 'Changes' },
    {
      key: CHANGE_LOG_HISTORY_ACTOR_LABEL_FIELD,
      label: 'Who',
      reads: [
        CHANGE_LOG_HISTORY_ACTOR_ID_FIELD,
        CHANGE_LOG_HISTORY_ACTOR_DELETED_FIELD,
        CHANGE_LOG_HISTORY_SUBJECT_ID_FIELD,
        CHANGE_LOG_HISTORY_SUBJECT_LABEL_FIELD,
        CHANGE_LOG_HISTORY_SUBJECT_DELETED_FIELD,
        CHANGE_LOG_HISTORY_CHANNEL_FIELD,
        CHANGE_LOG_HISTORY_RECEIPT_ID_FIELD,
      ],
    },
    {
      key: HILOS_TABLE_ACTIONS_KEY,
      label: '',
      reads: [CHANGE_LOG_HISTORY_RECEIPT_ID_FIELD],
    },
  ]
  const frame: HilosTableFrame = {
    title: 'History',
    search: { placeholder: 'Who…' },
    filters: [
      {
        kind: 'select',
        key: CHANGE_LOG_HISTORY_FILTER_FIELD,
        label: 'Field',
        options: () =>
          options.fields().map((field) => ({ value: field, label: field })),
      },
      {
        kind: 'select',
        key: CHANGE_LOG_FILTER_PERIOD,
        label: 'Period',
        options: () =>
          CHANGE_LOG_PERIODS.filter(({ value }) => value !== 'hour'),
        anyLabel: 'All time',
      },
    ],
    columns,
    empty: { title: 'No changes recorded for this table yet' },
  }
  const initialFilter: Record<string, unknown> = {
    [CHANGE_LOG_FILTER_TABLE]: options.table,
  }
  if (options.record !== undefined && options.record !== null) {
    initialFilter[CHANGE_LOG_HISTORY_FILTER_RECORD] = [...options.record]
  }
  const controller = new TableViewportController<HilosChangeLogHistoryRow>({
    resolve: resolveHilosChangeLogHistoryRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.CHANGE_LOG_TABLE,
        CHANGE_LOG_HISTORY_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        HilosPages.CHANGE_LOG_TABLE,
        CHANGE_LOG_HISTORY_TABLE,
        rendered,
      ),
    initialFilter,
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
            page: HilosPages.CHANGE_LOG_TABLE,
            tableKey: CHANGE_LOG_HISTORY_TABLE,
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
