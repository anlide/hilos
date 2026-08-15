// The framework Hilos backup admin headless: the backup-list view-model, the row
// resolver, and the table factory the per-framework HilosBackupPage view renders
// from. It is framework-agnostic (imports no UI framework) and reads only
// @hilos/core primitives, so the Vue/React/Angular backup views stay thin
// (multiframework-core.md).
//
// The page is read-only and live: rows arrive over the socket from two
// framework-owned runtime sources (the stored backup index and the single
// in-progress backup), delivered through the page-scoped `hilosBackups` viewport
// table. A project supplies a HilosBackupsContext — its scope stores and its live
// connection — and the framework owns the rest. Row actions (create / delete /
// keep) are a separate page (HIL-333) and are not part of this view.

import { z } from 'zod'
import {
  type ActionHandle,
  type ActionLifecycle,
} from '../../connection/actionLifecycle.js'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { HilosPages } from '../../routing/hilosPages.js'
import {
  readBoolean,
  readNumber,
  readString,
  readStringOrNull,
} from '../../state/fieldReaders.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
} from '../../state/signal.js'
import { hilosToasts } from '../../state/toasts.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { TableViewportController } from '../../table/TableViewportController.js'

/** One row of the Hilos backup table — the framework backup view-model. */
export interface HilosBackupRow {
  /** The backup id; also the table row key (a synthetic key for the in-progress row). */
  readonly id: string
  /** ISO-8601 creation timestamp (the start time for an in-progress backup). */
  readonly createdAt: string
  /** Application environment the backup was taken in, or null when the record names none. */
  readonly env: string | null
  /** Backup scope value (`full` | `schema-seed` | `schema-only`), or null when the record names none. */
  readonly scope: string | null
  /** Archive size in bytes (0 while in progress). */
  readonly sizeBytes: number
  /** Capture duration in seconds (0 while in progress). */
  readonly durationSeconds: number
  /** Retention pin: true when the backup is excluded from rotation. */
  readonly keep: boolean
  /** Status value (`success` | `error`, or `running` for the in-progress row). */
  readonly status: string
  /**
   * Completion tri-state: true completed, false in progress (renders the live
   * progress indicator), null a recorded failure.
   */
  readonly finished: boolean | null
  /**
   * Why the run failed — the persisted diagnostic shown in the failure-detail
   * modal. Present on error rows only; null for success, the in-progress row, and
   * legacy records saved before the reason was recorded.
   */
  readonly failureReason: string | null
  /**
   * Whether the archive carries a checksum, and how it last verified. The digest
   * itself never leaves the server — the list only ever shows this state.
   */
  readonly checksumState: HilosBackupChecksumState
  /**
   * ISO-8601 instant of the last verification, or null when the archive has never
   * been checked (which includes every backup written before checksums existed).
   */
  readonly verifiedAt: string | null
  /**
   * Phase of the restore of THIS archive, or null when it was never restored. Only
   * one row in the list ever carries these: the restore runtime row is a singleton
   * about the last run, and the backend decorates only the archive it names.
   */
  readonly restorePhase: string | null
  /** Terminal status of that restore (`success` | `error`), or null while it runs or never ran. */
  readonly restoreOutcome: string | null
  /** ISO-8601 instant that restore ended, or null while it runs or never ran. */
  readonly restoreFinishedAt: string | null
  /** Why that restore failed, or null when it succeeded or never ran. */
  readonly restoreFailureReason: string | null
  /**
   * Whether a failed restore of this archive had already begun replacing the
   * database. It rides the row rather than only the live frame because it is the
   * first thing an operator needs after reloading onto a failed restore.
   */
  readonly restoreDatabaseTouched: boolean
}

/**
 * The checksum state of a stored backup, mirroring the backend BackupChecksumState:
 * no digest recorded, a digest that nobody has checked, a checked-and-matching
 * archive, and one that did not match what was recorded.
 */
export type HilosBackupChecksumState =
  | 'none'
  | 'present'
  | 'verified'
  | 'mismatch'

// Wire keys: the framework backup table and its single inline `backup` slot (the
// merged runtime fields) and the create / delete / set-keep action names. A
// project binds its backend to these keys.
const HILOS_BACKUPS_TABLE = 'hilosBackups'
const BACKUP_SLOT = 'backup'
const HILOS_BACKUPS_PAGE_SIZE = 10
const BACKUP_CREATE_ACTION = 'backup_create'
const BACKUP_DELETE_ACTION = 'backup_delete'
const BACKUP_SET_KEEP_ACTION = 'backup_set_keep'
const BACKUP_RESTORE_ACTION = 'backup_restore'
/** The page's own actions, so an addressed failure notice for one is recognized as ours. */
const BACKUP_ACTIONS = new Set<string>([
  BACKUP_CREATE_ACTION,
  BACKUP_DELETE_ACTION,
  BACKUP_SET_KEEP_ACTION,
  BACKUP_RESTORE_ACTION,
])

/** Page-data section saying what this installation offers for restoring (backend `RESTORE_SECTION`). */
const BACKUP_RESTORE_DATA = 'backupRestore'

/** Server→initiator signal `type` carrying one restore runtime snapshot (PHP `BACKUP_RESTORE_PROGRESS`). */
export const BACKUP_RESTORE_PROGRESS_SIGNAL = 'backup_restore_progress'

// Row payload keys of the backup slot. They are declared here because this module
// owns the view-model they resolve into, and exported where a view also names them
// as a column key, so the wire name has one owner instead of a copy per view.

/** Row payload key of the creation instant (also the table's default sort field). */
export const BACKUP_CREATED_AT_FIELD = 'createdAt'

/** Row payload key of the environment the backup was taken in. */
export const BACKUP_ENV_FIELD = 'env'

/** Row payload key of the capture scope. */
export const BACKUP_SCOPE_FIELD = 'scope'

/** Row payload key of the archive size. */
export const BACKUP_SIZE_BYTES_FIELD = 'sizeBytes'

/** Row payload key of the capture duration. */
export const BACKUP_DURATION_SECONDS_FIELD = 'durationSeconds'

/** Row payload key of the rotation pin. */
export const BACKUP_KEEP_FIELD = 'keep'

/** Row payload key of the run status. */
export const BACKUP_STATUS_FIELD = 'status'

/** Row payload key of the completion tri-state. */
const BACKUP_FINISHED_FIELD = 'finished'

/** Row payload key of the recorded failure reason. */
const BACKUP_FAILURE_REASON_FIELD = 'failureReason'

/**
 * Row payload key of the checksum state. Exported because it is also the table
 * column key the three views declare, so the wire name has one owner instead of a
 * copy per view.
 */
export const BACKUP_CHECKSUM_STATE_FIELD = 'checksumState'

/** Row payload key of the last verification instant. */
export const BACKUP_VERIFIED_AT_FIELD = 'verifiedAt'

/** Row payload key of the phase of the restore of this archive. */
export const BACKUP_RESTORE_PHASE_FIELD = 'restorePhase'

/**
 * Row payload key of the outcome of that restore. Exported because it is also the
 * table column key the three views declare, so the wire name has one owner.
 */
export const BACKUP_RESTORE_OUTCOME_FIELD = 'restoreOutcome'

/** Row payload key of the instant that restore ended. */
export const BACKUP_RESTORE_FINISHED_AT_FIELD = 'restoreFinishedAt'

/** Row payload key of the reason that restore failed. */
export const BACKUP_RESTORE_FAILURE_REASON_FIELD = 'restoreFailureReason'

/** Row payload key of whether that restore had begun replacing the database. */
export const BACKUP_RESTORE_DATABASE_TOUCHED_FIELD = 'restoreDatabaseTouched'

/** A selectable backup scope: its wire value and a human-readable label. */
export interface HilosBackupScopeOption {
  /** The wire scope value sent to the backend. */
  readonly value: string
  /** The label shown in the create scope picker. */
  readonly label: string
}

/**
 * The backup scopes the create picker offers, in capture-breadth order. The
 * values match the backend BackupScope enum (`full` | `schema-seed` | `schema-only`).
 */
export const HILOS_BACKUP_SCOPES: readonly HilosBackupScopeOption[] = [
  { value: 'full', label: 'Full (schema + all data)' },
  { value: 'schema-seed', label: 'Schema + seed data' },
  { value: 'schema-only', label: 'Schema only' },
]

/**
 * The project-supplied context the backup admin reads from: the scope-partitioned
 * stores that own the page-scoped backup table, and the live connection the table
 * sends its viewport over. Everything else (the table key, slot name, view-model,
 * and behavior) is the framework's; the backup data is produced on its backend.
 */
export interface HilosBackupsContext {
  /** The connection the table sends its viewport over and receives its window / deltas from. */
  readonly connection: HilosConnection
  /** The scope manager owning the page scope the table window normalizes into. */
  readonly scopes: ScopeManager
  /** The action lifecycle the create / delete / set-keep tracked actions dispatch over. */
  readonly actions: ActionLifecycle
}

/** The backup mutation surface a backup view binds to. */
export interface HilosBackupsActions {
  /**
   * Start a backup in the chosen scope, as a tracked action. Acceptance is acked
   * at once (started, or queued behind an in-progress run); the committed row
   * arrives over the live table.
   *
   * @param scope The backup scope value to capture.
   */
  sendBackupCreate(scope: string): ActionHandle
  /**
   * Delete a stored backup, as a tracked action. The in-progress backup cannot be
   * deleted; an already-removed one is a no-op.
   *
   * @param id The backup id (also the table row key).
   */
  sendBackupDelete(id: string): ActionHandle
  /**
   * Set a stored backup's rotation pin, as a tracked action. Only a successful,
   * completed backup can be pinned.
   *
   * @param id The backup id (also the table row key).
   * @param keep The desired pin (true excludes the backup from rotation).
   */
  sendBackupSetKeep(id: string, keep: boolean): ActionHandle
  /**
   * Restore a stored backup, as a tracked action. The ack answers acceptance, never
   * the run: the node freezes for the length of the restore and the outcome arrives
   * as progress frames addressed to this connection.
   *
   * @param id The backup id (also the table row key).
   */
  sendBackupRestore(id: string): ActionHandle
}

/** What this installation offers for restoring, from the page-data section. */
export interface HilosBackupRestoreGate {
  /**
   * Whether the restore button is offered at all. False on production, and on an
   * installation whose APP_ENV names no known environment — one that cannot say it is
   * not live does not get the destructive button.
   */
  readonly uiEnabled: boolean
  /** The environment this installation runs in, as the confirmation modal names it. */
  readonly targetEnv: string | null
}

/** Read a row slot as an inline record, or undefined when it is not one. */
function recordSlot(slot: unknown): Record<string, unknown> | undefined {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : undefined
}

/**
 * Narrow a raw `finished` slot value to the completion tri-state. A stored backup
 * carries true (completed) or null (a recorded failure); the in-progress row
 * carries false. Missing / non-boolean input is treated as an unknown outcome (null).
 *
 * @param value The raw `finished` value from a payload slot.
 */
function toFinished(value: unknown): boolean | null {
  return typeof value === 'boolean' ? value : null
}

/**
 * Narrow a raw `failureReason` slot value to the view-model field. A non-empty
 * string is the persisted reason; missing, non-string, or empty input is no detail
 * (null), so a reader tells "no reason recorded" from an empty string.
 *
 * @param value The raw `failureReason` value from a payload slot.
 */
function toFailureReason(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null
}

/**
 * Narrow a raw `checksumState` slot value to the view-model field. Anything the
 * frontend does not recognize — a missing key on a legacy payload, a value from a
 * newer backend — reads as `none`: a row that cannot say it was checked must not
 * look like it was.
 *
 * @param value The raw `checksumState` value from a payload slot.
 */
function toChecksumState(value: unknown): HilosBackupChecksumState {
  return value === 'present' || value === 'verified' || value === 'mismatch'
    ? value
    : 'none'
}

/**
 * Narrow a raw `verifiedAt` slot value to the view-model field. Missing,
 * non-string, or empty input is "never verified" (null).
 *
 * @param value The raw `verifiedAt` value from a payload slot.
 */
function toVerifiedAt(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null
}

/**
 * Narrow a raw optional-text slot value to the view-model field: a non-empty string,
 * or null for anything else. The restore fields are all of this shape — every row but
 * the restored one omits them, and an empty string is the same "nothing happened here".
 *
 * @param value The raw value from a payload slot.
 */
function toTextOrNull(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null
}

/**
 * Resolve one raw backup table row into its view-model. The merged runtime fields
 * ride a single inline `backup` slot (no entity reference — a backup row is
 * page-scoped, keyed by its id), so this reads the slot as a plain record.
 *
 * @param row The raw table row from the page-scoped table store.
 */
export function resolveHilosBackupRow(row: TableRow): HilosBackupRow {
  const slot = recordSlot(row.slots[BACKUP_SLOT]) ?? {}

  return {
    // Identity is the fragment's row key. It must not travel inside the slot: a slot
    // payload carrying `id` is ingested as an entity fragment and replaced by a
    // reference, which would strip every other field off the row (normalizer.ts).
    id: String(row.rowKey),
    createdAt: readString(slot, BACKUP_CREATED_AT_FIELD),
    env: readStringOrNull(slot, BACKUP_ENV_FIELD),
    scope: readStringOrNull(slot, BACKUP_SCOPE_FIELD),
    sizeBytes: readNumber(slot, BACKUP_SIZE_BYTES_FIELD),
    durationSeconds: readNumber(slot, BACKUP_DURATION_SECONDS_FIELD),
    keep: readBoolean(slot, BACKUP_KEEP_FIELD),
    status: readString(slot, BACKUP_STATUS_FIELD),
    finished: toFinished(slot[BACKUP_FINISHED_FIELD]),
    failureReason: toFailureReason(slot[BACKUP_FAILURE_REASON_FIELD]),
    checksumState: toChecksumState(slot[BACKUP_CHECKSUM_STATE_FIELD]),
    verifiedAt: toVerifiedAt(slot[BACKUP_VERIFIED_AT_FIELD]),
    restorePhase: toTextOrNull(slot[BACKUP_RESTORE_PHASE_FIELD]),
    restoreOutcome: toTextOrNull(slot[BACKUP_RESTORE_OUTCOME_FIELD]),
    restoreFinishedAt: toTextOrNull(slot[BACKUP_RESTORE_FINISHED_AT_FIELD]),
    restoreFailureReason: toTextOrNull(
      slot[BACKUP_RESTORE_FAILURE_REASON_FIELD],
    ),
    restoreDatabaseTouched: readBoolean(
      slot,
      BACKUP_RESTORE_DATABASE_TOUCHED_FIELD,
    ),
  }
}

/**
 * Human-readable archive size, or a dash when there is no archive.
 *
 * A run in progress has not written one yet, and a failed run never will — both read
 * as a dash. Shared by the three views so the column cannot drift between them.
 *
 * @param row The backup row to format.
 */
export function formatBackupSize(row: HilosBackupRow): string {
  if (isBackupInProgress(row) || row.sizeBytes <= 0) {
    return '—'
  }
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let size = row.sizeBytes
  let unit = 0
  while (size >= 1024 && unit < units.length - 1) {
    size /= 1024
    unit += 1
  }

  return `${unit === 0 ? size : size.toFixed(1)} ${units[unit]}`
}

/**
 * Human-readable capture duration, or a dash while the run is still going.
 *
 * A finished run always has a duration, and a backup that took under a second took
 * `0s` — the dash is reserved for the in-progress row, where the number is not known
 * yet. Reporting a completed run as "no duration" reads as missing data.
 *
 * @param row The backup row to format.
 */
export function formatBackupDuration(row: HilosBackupRow): string {
  if (isBackupInProgress(row)) {
    return '—'
  }

  const seconds = Math.max(0, row.durationSeconds)
  if (seconds < 60) {
    return `${seconds}s`
  }

  return `${Math.floor(seconds / 60)}m ${seconds % 60}s`
}

/**
 * The checksum cell: a dash when no digest was recorded, `present` for a digest
 * nobody has checked, the check date once it verified, and a loud MISMATCH when it
 * did not. Shared by the three views so the column cannot drift between them.
 *
 * Only the date part of the verification instant is shown: the column is a compact
 * one next to Size, and the hour a check ran is not what the operator scans for.
 *
 * @param row The backup row to format.
 */
export function formatBackupChecksum(row: HilosBackupRow): string {
  switch (row.checksumState) {
    case 'mismatch':
      return 'MISMATCH'
    case 'verified':
      return row.verifiedAt === null ? '✓' : `✓ ${row.verifiedAt.slice(0, 10)}`
    case 'present':
      return 'present'
    default:
      return '—'
  }
}

/**
 * A stored archive that did not match its recorded checksum — the only checksum
 * state the views render in red. The single source the three share, so an alarming
 * state cannot end up quiet in one of them.
 */
export function isBackupChecksumMismatch(row: HilosBackupRow): boolean {
  return row.checksumState === 'mismatch'
}

/** The single in-progress backup (renders the live progress row; not actionable). */
export function isBackupInProgress(row: HilosBackupRow): boolean {
  return row.finished === false
}

/** A completed backup, success or failure — the only kind that can be deleted. */
export function isBackupDeletable(row: HilosBackupRow): boolean {
  return row.finished !== false
}

/** A successfully completed backup — the only kind whose keep pin can be toggled. */
export function isBackupKeepable(row: HilosBackupRow): boolean {
  return row.finished === true
}

/**
 * A failed backup that carries a recorded reason — the only kind that shows a
 * failure-detail button. The single source the three views share, so the button's
 * visibility cannot drift between them. A failure without a stored reason (a legacy
 * record) shows nothing, since there is nothing to open.
 */
export function hasBackupFailureDetail(row: HilosBackupRow): boolean {
  return row.finished === null && row.failureReason !== null
}

/**
 * A completed, successful archive — the rows that carry a restore control at all.
 *
 * Separate from {@link isBackupRestorable} on purpose, though a success is what both
 * start from: this decides whether the control is THERE, and that one whether it is
 * usable. A corrupt archive keeps its button and loses its enablement, because a row
 * with no button at all leaves the operator wondering whether restore even exists,
 * while a disabled one names what is wrong with this archive.
 */
export function offersBackupRestore(row: HilosBackupRow): boolean {
  return row.finished === true
}

/**
 * An archive that can be replayed: completed successfully, and not known to differ
 * from its recorded digest. The backend decides again on the action — the client is
 * not the source of truth about an installation's environment, or about what the
 * agent is busy with right now.
 */
export function isBackupRestorable(row: HilosBackupRow): boolean {
  return row.finished === true && !isBackupChecksumMismatch(row)
}

/**
 * Whether the backup subsystem looks occupied from here: a run in the list, or a
 * restore this connection is watching.
 *
 * The agent is single-flight and refuses the second run itself; this is what turns
 * that refusal into a disabled button with a reason instead of a toast after the
 * click. It is deliberately only what the client can see — another admin's restore
 * sends its frames to them, not here — so the server's answer stays the real one.
 *
 * @param rows The rows currently in the window (a removed placeholder reads as null).
 * @param restore The latest restore frame this connection received, or null.
 */
export function isBackupSubsystemBusy(
  rows: readonly (HilosBackupRow | null)[],
  restore: HilosRestoreStatus | null,
): boolean {
  return (
    restore?.running === true ||
    rows.some((row) => row !== null && isBackupInProgress(row))
  )
}

/**
 * An archive whose restore has ended — the only kind that shows a restore outcome.
 * The single source the three views share, so the cell cannot appear in one and not
 * the others.
 */
export function hasRestoreOutcome(row: HilosBackupRow): boolean {
  return row.restoreOutcome !== null
}

/**
 * The CLI command that restores this archive, for the environments where the button
 * is withheld. The id and scope are substituted so nobody retypes an identifier —
 * the very mistake the confirmation guards against where the button does exist.
 *
 * The configured entry path is deliberately not revealed: where the script lives
 * inside the container says nothing about how an operator reaches the machine.
 *
 * @param row The backup row to name in the command.
 */
export function formatRestoreCliCommand(row: HilosBackupRow): string {
  // A record that names no scope (a sidecar written before scopes were stored) gets a
  // placeholder rather than a guess: naming the wrong scope in a command an operator
  // is about to paste is worse than making them fill one word in.
  const scope = row.scope ?? '<scope>'

  return `php cli.php backup:restore ${row.id} --scope=${scope} --yes`
}

/**
 * Payload of the restore page-data section: whether this environment offers the
 * button, and which environment it is.
 */
const restoreGateSchema = z.looseObject({
  uiEnabled: z.boolean(),
  targetEnv: z.string().nullable(),
})

/**
 * Payload of one restore progress frame — the restore runtime row as the agent
 * photographs it, key for key with what the CLI monitor is answered
 * (PHP `BackupRestoreProgressSignalData`).
 */
const restoreProgressSchema = z.looseObject({
  running: z.boolean(),
  backupId: z.string().nullable(),
  scope: z.string().nullable(),
  phase: z.string().nullable(),
  startedAt: z.string().nullable(),
  finishedAt: z.string().nullable(),
  outcome: z.string().nullable(),
  failureReason: z.string().nullable(),
  rehydrateComplete: z.boolean(),
  rehydrateProblems: z.array(z.string()),
  databaseTouched: z.boolean(),
})

/** One restore progress frame: where the run is, and how it ended. */
export type HilosRestoreStatus = z.infer<typeof restoreProgressSchema>

/**
 * The backup signal schemas keyed for a connection's `projectSchemas`, so the parse
 * boundary validates the restore frames {@link createHilosRestoreProgress} ingests.
 * {@link createHilosConnection} merges them in, so a project never restates them.
 */
export const BACKUP_SIGNAL_SCHEMAS = {
  [BACKUP_RESTORE_PROGRESS_SIGNAL]: restoreProgressSchema,
}

/**
 * What this installation offers for restoring, reactively. The section is answered
 * once at subscribe, so it re-resolves on navigation rather than on every delta.
 *
 * A page scope without the section — an older backend, or the moment before the
 * subscription lands — reads as no button and no environment name. Withholding is
 * the safe answer: the environments that hide the button are exactly the ones where
 * pressing it would be worst, and the backend refuses the action there anyway.
 *
 * @param context The project context (the scope stores holding the page scope).
 */
export function createHilosBackupsRestoreGate(
  context: HilosBackupsContext,
): ReadonlySignal<HilosBackupRestoreGate> {
  const section = context.scopes.pageDataSignal(BACKUP_RESTORE_DATA)

  return computedSignal(() => {
    const parsed = restoreGateSchema.safeParse(section.get())

    return parsed.success
      ? { uiEnabled: parsed.data.uiEnabled, targetEnv: parsed.data.targetEnv }
      : { uiEnabled: false, targetEnv: null }
  })
}

/** The live restore status a backup view renders while a restore of its own runs. */
export interface HilosRestoreProgress {
  /** The latest frame this connection was sent, or null until the first one arrives. */
  readonly status: ReadonlySignal<HilosRestoreStatus | null>
  /** Start listening for frames — call on mount. */
  start(): void
  /** Stop listening — call on unmount. */
  dispose(): void
}

/**
 * The live restore status for the connection that asked for a restore.
 *
 * It exists because the table cannot report during a restore: the node is frozen, the
 * page's own agent is stopped, and the only channel left is the addressed frame the
 * backup agent sends to the initiator. Frames arrive on the connection rather than in
 * the page scope for the same reason — page scopes are fed by the machinery that is
 * down for the length of the operation.
 *
 * Another admin's restore is not shown here: the frames are addressed, so a tab that
 * did not start one simply never receives any and keeps a null status.
 *
 * @param context The project context (the connection the frames arrive on).
 */
export function createHilosRestoreProgress(
  context: HilosBackupsContext,
): HilosRestoreProgress {
  const status = createSignal<HilosRestoreStatus | null>(null)
  const teardown: Array<() => void> = []

  return {
    status,
    start() {
      teardown.push(
        context.connection.on('projectSignal', (signal) => {
          if (signal.type === BACKUP_RESTORE_PROGRESS_SIGNAL) {
            // Validated against restoreProgressSchema at the parse boundary; this cast is
            // the declared typed selector for that schema's output.
            status.set(signal.data as HilosRestoreStatus)
          }
        }),
      )
    },
    dispose() {
      for (const off of teardown.splice(0)) {
        off()
      }
    },
  }
}

/** The backup table handle a backup view drives: the controller plus its mount lifecycle. */
export interface HilosBackupsTable {
  /** The server-windowed controller the view renders rows, descriptor, and pending from. */
  readonly controller: TableViewportController<HilosBackupRow>
  /** Bind the table to the connection and request the first window — call on mount. */
  start(): void
  /** Unbind from the connection — call on unmount. */
  dispose(): void
}

/**
 * The server-windowed controller for the Hilos backup table: search, sort, and
 * paging change the viewport descriptor sent over the connection, and the backend
 * replies a window plus live deltas scoped to the table's (page, tableKey)
 * address. Rows resolve through {@link resolveHilosBackupRow}. Newest first by
 * default, so a started backup and fresh backups surface at the top. The returned
 * handle's `start` binds the table to its address and requests the first window;
 * `dispose` unbinds it (the view calls them on mount / unmount).
 *
 * @param context The project context (connection and scope stores).
 */
export function createHilosBackupsTable(
  context: HilosBackupsContext,
): HilosBackupsTable {
  const controller = new TableViewportController<HilosBackupRow>({
    resolve: resolveHilosBackupRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.BACKUP,
        HILOS_BACKUPS_TABLE,
        descriptor,
      ),
    pageSize: HILOS_BACKUPS_PAGE_SIZE,
    initialSort: { field: BACKUP_CREATED_AT_FIELD, direction: 'desc' },
  })
  const teardown: Array<() => void> = []

  return {
    controller,
    start() {
      teardown.push(
        // A backup action_error without a requestId answers no pending request: it is the
        // agent reporting how a run this connection started ended, long after the create was
        // acked. Nothing on this page is waiting for it, so it surfaces as a toast rather than
        // an inline error; the correlated ones stay with the tracked action that dispatched them.
        context.connection.on('actionError', (signal) => {
          if (
            signal.requestId === undefined &&
            BACKUP_ACTIONS.has(signal.action)
          ) {
            hilosToasts.push(signal.reason, { severity: 'error' })
          }
        }),
        bindTableViewport(
          context.connection,
          context.scopes,
          { page: HilosPages.BACKUP, tableKey: HILOS_BACKUPS_TABLE },
          controller,
        ),
        // Re-request the window whenever the socket (re)connects: the initial
        // request below can run before the connection is open, and a reconnect
        // is a fresh exchange that no longer remembers this connection's window.
        context.connection.on('state', (state) => {
          if (state === 'connected') {
            controller.start()
          }
        }),
      )
      controller.start()
    },
    dispose() {
      for (const off of teardown.splice(0)) {
        off()
      }
    },
  }
}

/**
 * The backup mutation surface: create / delete / set-keep submit as tracked
 * actions over the lifecycle. Each returns an ActionHandle whose `done` resolves
 * on the backend's `::success` ack and rejects on `::fail` — a view surfaces the
 * failure (authoritative-backend). Own-change is decided server-side now: the
 * backend tags the echoed delta `own` for the connection that authored it (the
 * agent stamps the initiator on its index write), so this surface only dispatches
 * and no longer marks rows on the controller.
 *
 * @param context The project context (the action lifecycle the actions dispatch over).
 */
export function createHilosBackupsActions(
  context: HilosBackupsContext,
): HilosBackupsActions {
  return {
    sendBackupCreate(scope) {
      return context.actions.dispatch(BACKUP_CREATE_ACTION, { scope })
    },
    sendBackupDelete(id) {
      return context.actions.dispatch(BACKUP_DELETE_ACTION, {
        backupId: id,
      })
    },
    sendBackupSetKeep(id, keep) {
      return context.actions.dispatch(BACKUP_SET_KEEP_ACTION, {
        backupId: id,
        keep,
      })
    },
    sendBackupRestore(id) {
      return context.actions.dispatch(BACKUP_RESTORE_ACTION, {
        backupId: id,
      })
    },
  }
}
