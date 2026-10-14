import { z } from 'zod'
import {
  ActionError,
  type ActionFailureOutcome,
  type ActionLifecycle,
} from '../../connection/actionLifecycle.js'
import { hideable } from '../../state/hideableSchema.js'
import { type Hideable, isHiddenValue } from '../../state/hiddenValue.js'
import { createSignal, type ReadonlySignal } from '../../state/signal.js'
import {
  changeLogChannelBadge,
  formatChangeLogMutation,
  formatChangeLogOnBehalfOf,
  formatChangeLogRecordKey,
  formatChangeLogTime,
  formatChangeLogWho,
  type HilosChangeLogContext,
  type HilosChangeLogPersonFields,
} from './hilosChangeLog.js'
import { type HilosChangeLogFieldChange } from './hilosChangeLogHistory.js'

/** Reading action for one receipt portion on the Change Log dashboard. */
export const HILOS_CHANGE_LOG_RECEIPT_READ = 'hilos_change_log_receipt_read'
/** Reading action for a full entry that has no application receipt. */
export const HILOS_CHANGE_LOG_ENTRY_READ = 'hilos_change_log_entry_read'

const RECEIPT_PORTION_SIZE = 50
const positiveIdSchema = z.number().int().positive()
const fieldChangeSchema: z.ZodType<HilosChangeLogFieldChange> = z.strictObject({
  field: z.string(),
  kind: z.enum(['inline', 'fact', 'long']),
  oldPresent: z.boolean(),
  newPresent: z.boolean(),
  oldValue: z.string().nullable(),
  newValue: z.string().nullable(),
  bodyOmitted: z.boolean(),
})
const receiptHeaderSchema = z.strictObject({
  receiptId: positiveIdSchema,
  createdAt: z.string(),
  actorId: positiveIdSchema.nullable(),
  actorLabel: hideable(z.string().nullable()),
  actorDeleted: z.boolean(),
  subjectId: positiveIdSchema.nullable(),
  subjectLabel: hideable(z.string().nullable()),
  subjectDeleted: z.boolean(),
  sessionId: positiveIdSchema.nullable(),
  channel: z.string(),
  action: z.string(),
  agent: z.string().nullable(),
  source: z.string().nullable(),
  entryCount: positiveIdSchema,
})
const entrySchema = z.strictObject({
  entryId: positiveIdSchema,
  createdAt: z.string(),
  receiptId: positiveIdSchema.nullable(),
  table: z.string(),
  recordKey: z
    .array(z.union([z.number().int(), z.string(), z.null()]))
    .nullable(),
  mutation: z.enum(['create', 'update', 'delete']),
  changes: z.array(fieldChangeSchema),
})

/** Complete required reply fields, with both person labels preserving hidden marks. */
export const changeLogReceiptReadReplySchema = z.strictObject({
  receipt: receiptHeaderSchema,
  entries: z.array(entrySchema).min(1).max(RECEIPT_PORTION_SIZE),
  nextAfterEntryId: positiveIdSchema.nullable(),
})
/** Full entry reply; its lack of a receipt is also checked against the requested address. */
export const changeLogEntryReadReplySchema = z.strictObject({
  entry: entrySchema,
})

/** Current attribution and the number of journal entries belonging to the action. */
export interface HilosChangeLogReceiptHeader extends HilosChangeLogPersonFields {
  readonly receiptId: number
  readonly createdAt: string
  readonly sessionId: number | null
  readonly channel: string
  readonly action: string
  readonly agent: string | null
  readonly source: string | null
  readonly entryCount: number
}

/** One journal entry, with complete value sides and a possibly anonymized record key. */
export interface HilosChangeLogEntry {
  readonly entryId: number
  readonly createdAt: string
  readonly receiptId: number | null
  readonly table: string
  readonly recordKey: readonly (number | string | null)[] | null
  readonly mutation: 'create' | 'update' | 'delete'
  readonly changes: readonly HilosChangeLogFieldChange[]
}

/** Header and one server-sized portion, delivered on the tracked acknowledgement. */
export interface HilosChangeLogReceiptReadReply {
  readonly receipt: HilosChangeLogReceiptHeader
  readonly entries: readonly HilosChangeLogEntry[]
  readonly nextAfterEntryId: number | null
}

/** One full bare entry, delivered on the tracked acknowledgement. */
export interface HilosChangeLogEntryReadReply {
  readonly entry: HilosChangeLogEntry
}

/** The dashboard's existing context plus its shared tracked action lifecycle. */
export interface HilosChangeLogReceiptContext extends HilosChangeLogContext {
  readonly actions: ActionLifecycle
}

type DialogAddress =
  | { readonly kind: 'receipt'; readonly receiptId: number }
  | { readonly kind: 'entry'; readonly entryId: number }

/** Browser-local reading state, discarded on close, replacement, or disposal. */
export type HilosChangeLogReceiptDialog = DialogAddress & {
  readonly busyInitial: boolean
  readonly busyMore: boolean
  readonly refusal: string | null
  readonly outcome: ActionFailureOutcome | null
  readonly header: HilosChangeLogReceiptHeader | null
  readonly entries: readonly HilosChangeLogEntry[]
  readonly nextAfterEntryId: number | null
  readonly expanded: boolean
}

/** Intents of the read-only dialog; every read settles without throwing into the view. */
export interface HilosChangeLogReceiptReader {
  readonly dialog: ReadonlySignal<HilosChangeLogReceiptDialog | null>
  /** Open a receipt at its first portion, invalidating the previous read. */
  openReceipt(id: number): Promise<void>
  /** Open one full entry without an application receipt. */
  openEntry(id: number): Promise<void>
  /** Append one portion; concurrent continuation intents send only one request. */
  more(): Promise<void>
  /** Restore the first portion and invalidate any pending continuation. */
  collapse(): void
  /** Repeat the failed initial read or the same failed continuation cursor. */
  retry(): Promise<void>
  /** Clear all dialog data and invalidate its pending reply. */
  close(): void
  /** Close permanently when the page scope or its viewer is replaced. */
  dispose(): void
}

/** Shared words of the read-only receipt and bare-entry surfaces. */
export const CHANGE_LOG_RECEIPT_COPY = {
  when: 'When',
  who: 'Who',
  onBehalfOf: 'On behalf of',
  channel: 'Channel',
  source: 'Source',
  action: 'Action',
  agent: 'Agent',
  whatChanged: 'What changed',
  oldValue: 'Was',
  newValue: 'Now',
  showMore: 'Show more',
  hide: 'Hide',
  retry: 'Retry',
  close: 'Close',
  loading: 'Reading changes…',
  loadingMore: 'Reading more changes…',
  readFailed: 'Could not read these changes. Try again.',
  invalidReply: 'The server returned an invalid change-log reply. Try again.',
  invalidAddress: 'The change-log number must be a positive integer.',
  deletedPerson: 'This person was deleted; the receipt keeps their number.',
  hiddenPerson: "The person's name is hidden in view mode.",
  guest: 'Guest — no account yet.',
  system: 'System — no person.',
  impersonation: 'Impersonation: at the keyboard',
  noReceipt:
    'No receipt: this change was made past the application, for example directly in the database.',
  created: 'Created as a fact; values belong to the record itself.',
  deleted: 'Snapshot before deletion:',
  personal: 'Personal — no value',
  absent: 'Absent',
  nullValue: 'NULL',
  emptyString: 'Empty string',
  omitted: 'Value omitted',
  migrationRunner: 'Migration runner',
  unknown: '—',
} as const

/**
 * Keep only the first occurrence of each entry, preserving journal order.
 *
 * @param entries Entries in the server's time-and-id order.
 */
function uniqueEntries(
  entries: readonly HilosChangeLogEntry[],
): HilosChangeLogEntry[] {
  const seen = new Set<number>()
  return entries.filter((entry) => {
    if (seen.has(entry.entryId)) return false
    seen.add(entry.entryId)
    return true
  })
}

/**
 * Read lazily through the shared action lifecycle; generations invalidate replies without
 * altering the dashboard subscription. Only this browser's open dialog holds the snapshot.
 *
 * @param context Dashboard connection, page scope, and tracked actions.
 */
export function createHilosChangeLogReceiptReader(
  context: HilosChangeLogReceiptContext,
): HilosChangeLogReceiptReader {
  const dialog = createSignal<HilosChangeLogReceiptDialog | null>(null)
  let generation = 0
  let disposed = false
  let firstPortion: {
    readonly entries: readonly HilosChangeLogEntry[]
    readonly nextAfterEntryId: number | null
  } | null = null

  async function read(
    base: HilosChangeLogReceiptDialog,
    afterEntryId: number | null,
  ): Promise<void> {
    const mine = ++generation
    const continuation = afterEntryId !== null
    const pending = {
      ...base,
      busyInitial: !continuation,
      busyMore: continuation,
      refusal: null,
      outcome: null,
    }
    dialog.set(pending)
    const action =
      base.kind === 'receipt'
        ? HILOS_CHANGE_LOG_RECEIPT_READ
        : HILOS_CHANGE_LOG_ENTRY_READ
    try {
      if (base.kind === 'receipt') {
        if (!positiveIdSchema.safeParse(base.receiptId).success) {
          throw new ActionError(
            action,
            'fail',
            CHANGE_LOG_RECEIPT_COPY.invalidAddress,
          )
        }
        const answer = await context.actions.dispatch(
          action,
          {
            receiptId: base.receiptId,
            afterEntryId,
          },
          { replySchema: changeLogReceiptReadReplySchema },
        ).done
        if (mine !== generation) return
        const reply = answer.reply
        if (
          reply === undefined ||
          reply.receipt.receiptId !== base.receiptId ||
          reply.entries.some((entry) => entry.receiptId !== base.receiptId) ||
          (reply.nextAfterEntryId !== null &&
            (reply.nextAfterEntryId !== reply.entries.at(-1)?.entryId ||
              reply.nextAfterEntryId === afterEntryId))
        ) {
          throw new ActionError(
            action,
            'invalid-reply',
            CHANGE_LOG_RECEIPT_COPY.invalidReply,
          )
        }
        const entries = uniqueEntries([...base.entries, ...reply.entries])
        if (!continuation) {
          firstPortion = { entries, nextAfterEntryId: reply.nextAfterEntryId }
        }
        dialog.set({
          ...pending,
          busyInitial: false,
          busyMore: false,
          header: reply.receipt,
          entries,
          nextAfterEntryId: reply.nextAfterEntryId,
          expanded:
            entries.length > (firstPortion?.entries.length ?? entries.length),
        })
      } else {
        if (!positiveIdSchema.safeParse(base.entryId).success) {
          throw new ActionError(
            action,
            'fail',
            CHANGE_LOG_RECEIPT_COPY.invalidAddress,
          )
        }
        const answer = await context.actions.dispatch(
          action,
          { entryId: base.entryId },
          {
            replySchema: changeLogEntryReadReplySchema,
          },
        ).done
        if (mine !== generation) return
        if (
          answer.reply === undefined ||
          answer.reply.entry.entryId !== base.entryId ||
          answer.reply.entry.receiptId !== null
        ) {
          throw new ActionError(
            action,
            'invalid-reply',
            CHANGE_LOG_RECEIPT_COPY.invalidReply,
          )
        }
        dialog.set({
          ...pending,
          busyInitial: false,
          entries: [answer.reply.entry],
        })
      }
    } catch (error) {
      if (mine !== generation) return
      const outcome = error instanceof ActionError ? error.outcome : 'fail'
      dialog.set({
        ...pending,
        busyInitial: false,
        busyMore: false,
        outcome,
        refusal:
          outcome === 'invalid-reply'
            ? CHANGE_LOG_RECEIPT_COPY.invalidReply
            : error instanceof Error
              ? error.message
              : CHANGE_LOG_RECEIPT_COPY.readFailed,
      })
    }
  }

  function open(address: DialogAddress): Promise<void> {
    if (disposed) return Promise.resolve()
    firstPortion = null
    return read(
      {
        ...address,
        busyInitial: false,
        busyMore: false,
        refusal: null,
        outcome: null,
        header: null,
        entries: [],
        nextAfterEntryId: null,
        expanded: false,
      },
      null,
    )
  }

  function more(): Promise<void> {
    const current = dialog.get()
    if (
      disposed ||
      current === null ||
      current.kind !== 'receipt' ||
      current.busyInitial ||
      current.busyMore ||
      current.header === null ||
      current.nextAfterEntryId === null
    )
      return Promise.resolve()
    return read(current, current.nextAfterEntryId)
  }

  function close(): void {
    generation += 1
    firstPortion = null
    dialog.set(null)
  }

  return {
    dialog,
    openReceipt: (id) => open({ kind: 'receipt', receiptId: id }),
    openEntry: (id) => open({ kind: 'entry', entryId: id }),
    more,
    collapse() {
      const current = dialog.get()
      if (
        current === null ||
        current.kind !== 'receipt' ||
        firstPortion === null
      )
        return
      generation += 1
      dialog.set({
        ...current,
        ...firstPortion,
        busyMore: false,
        refusal: null,
        outcome: null,
        expanded: false,
      })
    },
    retry() {
      const current = dialog.get()
      if (
        current === null ||
        current.refusal === null ||
        current.busyInitial ||
        current.busyMore
      )
        return Promise.resolve()
      return current.header === null ? open(current) : more()
    },
    close,
    dispose() {
      disposed = true
      close()
    },
  }
}

/** A person label with its retained number and a visible explanation, never an invented name. */
export interface HilosChangeLogReceiptPerson {
  readonly id: number | null
  readonly label: Hideable<string>
  readonly explanation: string | null
}

/** Header projection, preserving hidden labels for HilosHiddenMark beside the number. */
export interface HilosChangeLogReceiptAttribution {
  readonly when: string
  readonly actor: HilosChangeLogReceiptPerson
  readonly subject: HilosChangeLogReceiptPerson | null
  readonly channel: ReturnType<typeof changeLogChannelBadge>
  readonly action: string
  readonly agent: string
  readonly source: string
}

/**
 * Project the receipt's recorded writer and initiator without interpreting action names.
 *
 * @param header Header from this read, including current person labels.
 */
export function changeLogReceiptAttribution(
  header: HilosChangeLogReceiptHeader,
): HilosChangeLogReceiptAttribution {
  const actorLabel = formatChangeLogWho(header)
  const explanation = (label: Hideable<string>, deleted: boolean) =>
    isHiddenValue(label)
      ? CHANGE_LOG_RECEIPT_COPY.hiddenPerson
      : deleted
        ? CHANGE_LOG_RECEIPT_COPY.deletedPerson
        : null
  const subjectLabel = formatChangeLogOnBehalfOf(header)
  return {
    when: formatChangeLogTime(header.createdAt),
    actor: {
      id: header.actorId,
      label: actorLabel,
      explanation:
        header.actorId === null
          ? header.channel === 'web'
            ? CHANGE_LOG_RECEIPT_COPY.guest
            : CHANGE_LOG_RECEIPT_COPY.system
          : explanation(actorLabel, header.actorDeleted),
    },
    subject:
      header.subjectId === null || header.subjectId === header.actorId
        ? null
        : {
            id: header.subjectId,
            label: subjectLabel ?? CHANGE_LOG_RECEIPT_COPY.unknown,
            explanation: explanation(
              subjectLabel ?? CHANGE_LOG_RECEIPT_COPY.unknown,
              header.subjectDeleted,
            ),
          },
    channel: changeLogChannelBadge(header.channel),
    action: header.action,
    agent:
      header.agent ??
      (header.channel === 'migration'
        ? CHANGE_LOG_RECEIPT_COPY.migrationRunner
        : CHANGE_LOG_RECEIPT_COPY.unknown),
    source: header.source ?? CHANGE_LOG_RECEIPT_COPY.unknown,
  }
}

/** Pagination projection; Hide remains after the final continuation. */
export interface HilosChangeLogReceiptPagination {
  readonly count: string
  readonly showMore: boolean
  readonly showHide: boolean
}

/**
 * Offer continuation controls only for a receipt larger than the initial server portion.
 *
 * @param dialog The currently open dialog.
 */
export function changeLogReceiptPagination(
  dialog: HilosChangeLogReceiptDialog,
): HilosChangeLogReceiptPagination | null {
  if (
    dialog.header === null ||
    dialog.header.entryCount <= RECEIPT_PORTION_SIZE
  )
    return null
  return {
    count: `Shown ${dialog.entries.length} of ${dialog.header.entryCount}`,
    showMore: dialog.nextAfterEntryId !== null,
    showHide: dialog.expanded,
  }
}

/** A full value side; its kind keeps absence, SQL NULL, and the empty string distinct. */
export interface HilosChangeLogValueSide {
  readonly kind: 'absent' | 'null' | 'empty' | 'value' | 'fact' | 'omitted'
  readonly text: string
}

/**
 * Format one full side as plain text; personal facts never render a value.
 *
 * @param change The journaled field.
 * @param side The old or new side to show.
 */
export function changeLogFieldValue(
  change: HilosChangeLogFieldChange,
  side: 'old' | 'new',
): HilosChangeLogValueSide {
  if (change.kind === 'fact')
    return { kind: 'fact', text: CHANGE_LOG_RECEIPT_COPY.personal }
  const present = side === 'old' ? change.oldPresent : change.newPresent
  const value = side === 'old' ? change.oldValue : change.newValue
  if (!present) return { kind: 'absent', text: CHANGE_LOG_RECEIPT_COPY.absent }
  if (change.bodyOmitted)
    return { kind: 'omitted', text: CHANGE_LOG_RECEIPT_COPY.omitted }
  if (value === null)
    return { kind: 'null', text: CHANGE_LOG_RECEIPT_COPY.nullValue }
  return value === ''
    ? { kind: 'empty', text: CHANGE_LOG_RECEIPT_COPY.emptyString }
    : { kind: 'value', text: value }
}

/** A change card projection; creation is a fact and deletion has only snapshot sides. */
export interface HilosChangeLogEntryDetails {
  readonly record: string
  readonly mutation: ReturnType<typeof formatChangeLogMutation>
  readonly explanation: string | null
  readonly fields: readonly {
    readonly field: string
    readonly kind: HilosChangeLogFieldChange['kind']
    readonly oldValue: HilosChangeLogValueSide
    readonly newValue: HilosChangeLogValueSide | null
  }[]
}

/**
 * Draw only the saved journal snapshot, without looking up the current data record.
 *
 * @param entry Full journal entry in server order.
 */
export function changeLogEntryDetails(
  entry: HilosChangeLogEntry,
): HilosChangeLogEntryDetails {
  return {
    record: `${entry.table} ${formatChangeLogRecordKey(entry.recordKey)}`,
    mutation: formatChangeLogMutation(entry.mutation),
    explanation:
      entry.mutation === 'create'
        ? CHANGE_LOG_RECEIPT_COPY.created
        : entry.mutation === 'delete'
          ? CHANGE_LOG_RECEIPT_COPY.deleted
          : null,
    fields:
      entry.mutation === 'create'
        ? []
        : entry.changes.map((change) => ({
            field: change.field,
            kind: change.kind,
            oldValue: changeLogFieldValue(change, 'old'),
            newValue:
              entry.mutation === 'delete'
                ? null
                : changeLogFieldValue(change, 'new'),
          })),
  }
}
