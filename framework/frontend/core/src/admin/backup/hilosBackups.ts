// The framework Hilos backup admin headless: the backup-list view-model, the row
// resolver, and the table factory the per-framework HilosBackupPage view renders
// from. It is framework-agnostic (imports no UI framework) and reads only
// @hilos/core primitives, so the Vue/React/Angular backup views stay thin
// (multiframework-core.md).
//
// The page is read-only and live: rows arrive over the socket from the stored backup
// index, delivered through the page-scoped `hilosBackups` viewport table. A run in
// flight is no row of that set — it is the table's own progress bar, and the caption
// beside it is assembled here (HIL-820). A project supplies a HilosBackupsContext —
// its scope stores and its live connection — and the framework owns the rest. Row
// actions (create / delete / keep) are a separate page (HIL-333) and are not part of
// this view.

import { type HilosRestoreStatus } from '../../protocol/backupSignals.js'
import { PERCENT_SCALE } from '../../connection/restoreProgress.js'
import { z } from 'zod'
import {
  type ActionHandle,
  type ActionLifecycle,
} from '../../connection/actionLifecycle.js'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { formatBytes } from '../../format/bytes.js'
import { formatDurationShort } from '../../format/duration.js'
import { formatMoment } from '../../format/moment.js'
import { resolveHilosPath } from '../../routing/hilosAdmin.js'
import { HilosPages } from '../../routing/hilosPages.js'
import {
  readBoolean,
  readHideableStringOrNull,
  readNumber,
  readNumberOrNull,
  readString,
  readStringOrNull,
} from '../../state/fieldReaders.js'
import {
  HIDDEN_VALUE,
  type Hideable,
  isHiddenValue,
} from '../../state/hiddenValue.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import {
  computedSignal,
  type ReadonlySignal,
  subscribeSignal,
} from '../../state/signal.js'
import { hilosToasts } from '../../state/toasts.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import {
  HILOS_TABLE_ACTIONS_KEY,
  type HilosTableColumnOf,
} from '../../table/hilosTableColumn.js'
import {
  hilosTableBulkAcceptedSchema,
  hilosTableBulkPayload,
} from '../../table/tableBulkRequest.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { type HilosTableSortOrder } from '../../table/tableSortOrder.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import { type HilosTableProgress } from '../../table/tableProgress.js'
import { hilosTimeZone } from '../../timeZone/timeZoneState.js'

export {
  BACKUP_RESTORE_PROGRESS_SIGNAL,
  type HilosRestoreStatus,
  BACKUP_SIGNAL_SCHEMAS,
} from '../../protocol/backupSignals.js'
export {
  backupProgressPercent,
  createBackupProgressClock,
  createHilosRestoreProgress,
  elapsedSecondsSince,
  formatBackupEta,
  formatBackupProgressLabel,
  formatRestoreOutcomeLine,
  formatRestorePhaseLine,
  type HilosProgressAnchors,
  type HilosRestoreProgress,
  type HilosBackupProgressClock,
} from '../../connection/restoreProgress.js'

/** One row of the Hilos backup table — the framework backup view-model. */
export interface HilosBackupRow {
  /** The backup id; also the table row key. */
  readonly id: string
  /** ISO-8601 creation timestamp. */
  readonly createdAt: string
  /** Application environment the backup was taken in, or null when the record names none. */
  readonly env: string | null
  /** Backup scope value (`full` | `schema-seed` | `schema-only`), or null when the record names none. */
  readonly scope: string | null
  /** Archive size in bytes. */
  readonly sizeBytes: number
  /** Capture duration in seconds. */
  readonly durationSeconds: number
  /** Retention pin: true when the backup is excluded from rotation. */
  readonly keep: boolean
  /** Status value (`success` | `error`). */
  readonly status: string
  /**
   * Completion state: true completed, null a recorded failure. Two values and not
   * three — a run in flight is no row of this set at all, it is the table's own bar
   * (HIL-820).
   */
  readonly finished: boolean | null
  /**
   * Why the run failed — the persisted diagnostic shown in the failure-detail
   * modal. Present on error rows only; null for success and for legacy records saved
   * before the reason was recorded. Hidden from a viewer of the admin view mode.
   */
  readonly failureReason: Hideable<string | null>
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
   * Whether a copy of this backup exists off the machine it was taken on, and how
   * the last attempt at one went. `none` when this installation ships nowhere at
   * all, which is the state a row must read in rather than a copy pending forever.
   */
  readonly shipState: HilosBackupShipState
  /**
   * ISO-8601 instant of the last successful copy, or null when the archive has
   * never left the machine.
   */
  readonly shippedAt: string | null
  /**
   * Why the last copy attempt failed, or null when none has; hidden from a viewer of
   * the admin view mode.
   */
  readonly shipError: Hideable<string | null>
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
  /**
   * Why that restore failed, or null when it succeeded or never ran; hidden from a
   * viewer of the admin view mode.
   */
  readonly restoreFailureReason: Hideable<string | null>
  /**
   * Whether a failed restore of this archive had already begun replacing the
   * database. It rides the row rather than only the live frame because it is the
   * first thing an operator needs after reloading onto a failed restore.
   */
  readonly restoreDatabaseTouched: boolean
  /**
   * What the migration gate allows for this archive (`allow` | `refuse`), or null
   * on a row with no archive to judge. Decided on the server: the rules for
   * comparing an archive's schema level with the code's live there, and a client
   * that recomputed them would answer from a page that may predate a restart.
   */
  readonly restoreMigrationDecision: string | null
  /**
   * Migrations the furthest lagging connection will apply after the import, or
   * null when nothing lags and when nothing can be compared.
   */
  readonly restoreMigrationBehind: number | null
  /**
   * Operator-facing text about this archive's levels, or null when there is nothing
   * to say: one line per connection joined by newlines where the archive is allowed,
   * and the gate's single refusal sentence where it is not — that one names the
   * connections that are ahead inside itself. The same sentences the CLI preflight
   * prints, so the two never word one verdict differently. Hidden from a viewer of
   * the admin view mode.
   */
  readonly restoreMigrationNotice: Hideable<string | null>
  /**
   * Cluster node whose disk holds this archive, named only when the backup agent runs
   * elsewhere and cannot reach it; null otherwise. One key and not a flag beside it:
   * out of reach IS a named holder, so a row cannot claim one and carry the other.
   */
  readonly holderNode: string | null
}

/**
 * The checksum state of a stored backup, mirroring the backend BackupChecksumState:
 * no digest recorded, a digest that nobody has checked, a checked-and-matching
 * archive, and one that did not match what was recorded.
 */
export type HilosBackupChecksumState =
  'none' | 'present' | 'verified' | 'mismatch'

/**
 * The off-machine copy state of a stored backup, mirroring the backend
 * BackupShipState: no destination configured at all, a copy still owed, a copy that
 * landed, and one whose last attempt failed.
 */
export type HilosBackupShipState = 'none' | 'pending' | 'shipped' | 'failed'

// Wire keys: the framework backup table and its single inline `backup` slot (the
// merged runtime fields) and the create / delete / set-keep action names. A
// project binds its backend to these keys.
const HILOS_BACKUPS_TABLE = 'hilosBackups'
const BACKUP_SLOT = 'backup'
const BACKUP_CREATE_ACTION = 'backup_create'
const BACKUP_DELETE_ACTION = 'backup_delete'
const BACKUP_BULK_DELETE_ACTION = 'backup_bulk_delete'
const BACKUP_SET_KEEP_ACTION = 'backup_set_keep'
const BACKUP_RESTORE_ACTION = 'backup_restore'
const BACKUP_REOPEN_ACTION = 'backup_reopen'
/** The page's own actions, so an addressed failure notice for one is recognized as ours. */
const BACKUP_ACTIONS = new Set<string>([
  BACKUP_CREATE_ACTION,
  BACKUP_DELETE_ACTION,
  BACKUP_BULK_DELETE_ACTION,
  BACKUP_SET_KEEP_ACTION,
  BACKUP_RESTORE_ACTION,
  BACKUP_REOPEN_ACTION,
])

/** Page-data section saying what this installation offers for restoring (backend `RESTORE_SECTION`). */
const BACKUP_RESTORE_DATA = 'backupRestore'

/** Page-data section saying whether THIS browser may reopen the system (backend `REOPEN_SECTION`). */
const BACKUP_REOPEN_DATA = 'backupReopen'

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

/**
 * Row payload key of the off-machine copy state. Exported because it is also the
 * table column key the three views declare, so the wire name has one owner.
 */
export const BACKUP_SHIP_STATE_FIELD = 'shipState'

/** Row payload key of the last successful copy instant. */
export const BACKUP_SHIPPED_AT_FIELD = 'shippedAt'

/** Row payload key of the reason the last copy attempt failed. */
export const BACKUP_SHIP_ERROR_FIELD = 'shipError'

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

/** Row payload key of what the migration gate allows for this archive. */
export const BACKUP_RESTORE_MIGRATION_DECISION_FIELD =
  'restoreMigrationDecision'

/** Row payload key of how many migrations the restore will apply after the import. */
export const BACKUP_RESTORE_MIGRATION_BEHIND_FIELD = 'restoreMigrationBehind'

/** Row payload key of the per-connection lines explaining this archive's levels. */
export const BACKUP_RESTORE_MIGRATION_NOTICE_FIELD = 'restoreMigrationNotice'

/** Row payload key of the node holding an archive the backup agent cannot reach. */
export const BACKUP_HOLDER_NODE_FIELD = 'holderNode'

/** Migration-gate decision that refuses the archive; the only value the views test for. */
const BACKUP_MIGRATION_REFUSED = 'refuse'

/** Detail key beside the run's bar: the phase value the run is in, as the code names it. */
export const BACKUP_RUN_PROGRESS_DETAIL_PHASE = 'phase'

/** Detail key beside the run's bar: seconds left, negative once the estimate is spent. */
export const BACKUP_RUN_PROGRESS_DETAIL_REMAINING_SECONDS = 'remainingSeconds'

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
  /**
   * End the verification window the node stands in after a restore, as a tracked
   * action. The ack answers acceptance only: the outcome is that the system reopens,
   * which reloads every connected page including this one.
   */
  sendBackupReopen(): ActionHandle
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
 * Narrow a raw `finished` slot value to the completion state: true completed, null a
 * recorded failure. Missing / non-boolean input is treated as an unknown outcome
 * (null), which is what a failure reads as.
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
 * Narrow a raw `shipState` slot value to the view-model field. Anything the frontend
 * does not recognize — a legacy payload without the key, a value from a newer
 * backend — reads as `none`, so an unreadable row says "this installation ships
 * nowhere" rather than showing a copy that will never arrive.
 *
 * @param value The raw `shipState` value from a payload slot.
 */
function toShipState(value: unknown): HilosBackupShipState {
  return value === 'pending' || value === 'shipped' || value === 'failed'
    ? value
    : 'none'
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
 * Narrow a raw free-text slot value a viewer of the admin view mode may be sent
 * hidden: the one hidden value for the mark, the field's own narrowing otherwise.
 *
 * @param value The raw value from a payload slot.
 * @param narrow The field's narrowing of a value that is not hidden.
 */
function hideableOr(
  value: unknown,
  narrow: (value: unknown) => string | null,
): Hideable<string | null> {
  return isHiddenValue(value) ? HIDDEN_VALUE : narrow(value)
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
    failureReason: hideableOr(
      slot[BACKUP_FAILURE_REASON_FIELD],
      toFailureReason,
    ),
    checksumState: toChecksumState(slot[BACKUP_CHECKSUM_STATE_FIELD]),
    verifiedAt: toVerifiedAt(slot[BACKUP_VERIFIED_AT_FIELD]),
    shipState: toShipState(slot[BACKUP_SHIP_STATE_FIELD]),
    shippedAt: toTextOrNull(slot[BACKUP_SHIPPED_AT_FIELD]),
    shipError: hideableOr(slot[BACKUP_SHIP_ERROR_FIELD], toTextOrNull),
    restorePhase: toTextOrNull(slot[BACKUP_RESTORE_PHASE_FIELD]),
    restoreOutcome: toTextOrNull(slot[BACKUP_RESTORE_OUTCOME_FIELD]),
    restoreFinishedAt: toTextOrNull(slot[BACKUP_RESTORE_FINISHED_AT_FIELD]),
    restoreFailureReason: hideableOr(
      slot[BACKUP_RESTORE_FAILURE_REASON_FIELD],
      toTextOrNull,
    ),
    restoreDatabaseTouched: readBoolean(
      slot,
      BACKUP_RESTORE_DATABASE_TOUCHED_FIELD,
    ),
    restoreMigrationDecision: readStringOrNull(
      slot,
      BACKUP_RESTORE_MIGRATION_DECISION_FIELD,
    ),
    restoreMigrationBehind: readNumberOrNull(
      slot,
      BACKUP_RESTORE_MIGRATION_BEHIND_FIELD,
    ),
    restoreMigrationNotice: readHideableStringOrNull(
      slot,
      BACKUP_RESTORE_MIGRATION_NOTICE_FIELD,
    ),
    holderNode: readStringOrNull(slot, BACKUP_HOLDER_NODE_FIELD),
  }
}

/**
 * Human-readable archive size, or a dash when there is no archive.
 *
 * A failed run never wrote one, and that reads as a dash. Shared by the three views so
 * the column cannot drift between them.
 *
 * @param row The backup row to format.
 */
export function formatBackupSize(row: HilosBackupRow): string {
  if (row.sizeBytes <= 0) {
    return '—'
  }
  return formatBytes(row.sizeBytes)
}

/**
 * Human-readable capture duration.
 *
 * Every row of this set is a run that ended, so every one of them has a duration: a
 * backup that took under a second took `0s`, and reporting that as "no duration" would
 * read as missing data. The form is the compact one of a measured run — `7s`,
 * `3m 05s`, `1h 02m 05s` — and a negative figure reads as `0s`.
 *
 * @param row The backup row to format.
 */
export function formatBackupDuration(row: HilosBackupRow): string {
  return formatDurationShort(Math.max(0, row.durationSeconds))
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
    case 'verified': {
      const date =
        row.verifiedAt === null
          ? null
          : formatMoment(row.verifiedAt, { kind: 'date' })

      return date === null ? '✓' : `✓ ${date}`
    }
    case 'present':
      return 'present'
    default:
      return '—'
  }
}

/**
 * The copy cell: a dash when the installation ships nowhere, the date a copy landed,
 * a waiting marker while one is owed, and a loud FAILED when the last attempt did
 * not make it. Shared by the three views so the column cannot drift between them.
 *
 * Only the date part of the instant is shown, for the reason the checksum cell shows
 * only a date: the column is a compact one, and the hour a copy left is not what the
 * operator scans for. What went wrong is on the row as `shipError`, not here — a
 * column wide enough for an rsync error is a column that pushes the list sideways.
 *
 * @param row The backup row to format.
 */
export function formatBackupShipping(row: HilosBackupRow): string {
  switch (row.shipState) {
    case 'failed':
      return 'FAILED'
    case 'shipped': {
      const date =
        row.shippedAt === null
          ? null
          : formatMoment(row.shippedAt, { kind: 'date' })

      return date === null ? '✓' : `✓ ${date}`
    }
    case 'pending':
      return 'pending'
    default:
      return '—'
  }
}

/**
 * The caption beside the run's bar above the table, read off the bar itself.
 *
 * The same sentence {@link formatBackupProgressLabel} prints for a restore —
 * `archiving · 62% · ~40s left`, each part dropped when the run cannot say it — but
 * assembled from the bar rather than from anchors and a clock. Nothing is counted here:
 * the share and the seconds left arrive already counted on the server, which is what
 * makes one wording out of two sources honest (HIL-820).
 *
 * @param progress The table's progress bar as the controller holds it.
 */
export function formatBackupRunCaption(progress: HilosTableProgress): string {
  const phase = progress.detail[BACKUP_RUN_PROGRESS_DETAIL_PHASE]
  const phaseLabel = typeof phase === 'string' && phase !== '' ? phase : null
  if (progress.fraction === null) {
    return phaseLabel ?? 'In progress'
  }

  const parts = [
    phaseLabel ?? 'In progress',
    `${Math.round(progress.fraction * PERCENT_SCALE)}%`,
  ]
  const remaining =
    progress.detail[BACKUP_RUN_PROGRESS_DETAIL_REMAINING_SECONDS]
  if (typeof remaining === 'number') {
    parts.push(
      remaining > 0 ? `~${remaining}s left` : 'taking longer than usual',
    )
  }

  return parts.join(' · ')
}

/**
 * A stored archive that did not match its recorded checksum — the only checksum
 * state the views render in red. The single source the three share, so an alarming
 * state cannot end up quiet in one of them.
 */
export function isBackupChecksumMismatch(row: HilosBackupRow): boolean {
  return row.checksumState === 'mismatch'
}

/**
 * A stored archive whose last copy off the machine did not make it — the only copy
 * state the views render in red. The single source the three share, for the reason
 * the checksum one is shared: an alarming state must not end up quiet in one view.
 */
export function isBackupShipFailed(row: HilosBackupRow): boolean {
  return row.shipState === 'failed'
}

/**
 * An archive stored on another cluster node's disk — the backup agent runs elsewhere
 * and can neither restore, delete, pin nor rotate it. The row stays in the list so
 * that "these archives are somewhere else" never reads as "no backups were taken".
 */
export function isBackupOutOfReach(row: HilosBackupRow): boolean {
  return row.holderNode !== null
}

/**
 * Why an archive cannot be reached, or null when it can. The single wording the three
 * views put on the node badge, so the explanation cannot drift between them.
 */
export function formatBackupOutOfReach(row: HilosBackupRow): string | null {
  return row.holderNode === null
    ? null
    : `Stored on node ${row.holderNode} - the backup agent runs elsewhere, so this archive cannot be reached from here`
}

/** A completed backup this node holds, success or failure — the only kind that can be deleted. */
export function isBackupDeletable(row: HilosBackupRow): boolean {
  return row.finished !== false && !isBackupOutOfReach(row)
}

/** A successfully completed backup this node holds — the only kind whose keep pin can be toggled. */
export function isBackupKeepable(row: HilosBackupRow): boolean {
  return row.finished === true && !isBackupOutOfReach(row)
}

/**
 * A failed backup that carries a recorded reason — the only kind that shows a
 * failure-detail button. The single source the three views share, so the button's
 * visibility cannot drift between them. A failure without a stored reason (a legacy
 * record) shows nothing, since there is nothing to open. A hidden reason is not "no
 * reason": the server hides the field whatever it holds, so the button stands and
 * the modal shows a viewer of the admin view mode the hidden mark.
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
 * An archive that can be replayed: completed successfully, within the backup agent's
 * reach, and not known to differ from its recorded digest. The backend decides again
 * on the action — the client is not the source of truth about an installation's
 * environment, about what the agent can reach, or about what it is busy with right now.
 */
export function isBackupRestorable(row: HilosBackupRow): boolean {
  return (
    row.finished === true &&
    !isBackupOutOfReach(row) &&
    !isBackupChecksumMismatch(row) &&
    !isBackupMigrationRefused(row)
  )
}

/**
 * An archive taken on code newer than this installation's — refused forever, since
 * there is no downgrade path. The badge that says so and the disabled button read
 * the same predicate, so a row cannot claim one thing and offer the other.
 */
export function isBackupMigrationRefused(row: HilosBackupRow): boolean {
  return row.restoreMigrationDecision === BACKUP_MIGRATION_REFUSED
}

/**
 * How many migrations this archive's restore will apply afterwards, or null when
 * there is no number to show — an archive level with the code, one taken ahead of
 * it, and one whose level nobody can compare all show no count rather than a zero.
 */
export function backupMigrationBehind(row: HilosBackupRow): number | null {
  return row.restoreMigrationBehind
}

/**
 * The archive's migration text, split into the lines a view renders one under another.
 *
 * The wire carries it as one newline-joined string because every row payload in the
 * framework is flat; splitting is this module's job so the three views cannot disagree
 * about the separator. Blank lines are dropped — an empty line is the absence of a
 * note, not a note that says nothing. A refusal arrives as one sentence rather than
 * one line per connection: the gate words the connections that are ahead into it, and
 * it uses `;` inside that sentence, so there is nothing here safe to split it on.
 * A hidden notice stays hidden — one mark, not one line per hidden note.
 */
export function backupMigrationNotes(
  row: HilosBackupRow,
): Hideable<readonly string[]> {
  const notice = row.restoreMigrationNotice
  if (isHiddenValue(notice)) {
    return HIDDEN_VALUE
  }

  return (notice ?? '').split('\n').filter((line) => line !== '')
}

/**
 * Whether the backup subsystem looks occupied from here: the table's bar is up, or a
 * restore this connection is watching is running.
 *
 * The agent is single-flight and refuses the second run itself; this is what turns
 * that refusal into a disabled button with a reason instead of a toast after the
 * click. Read off the bar rather than off the rows, because the bar lives outside the
 * window: a run used to disappear from this answer the moment the operator paged away
 * from the row reporting it. The restore half stays what the client can see — another
 * admin's restore sends its frames to them, not here — so the server's answer remains
 * the real one.
 *
 * @param tableProgress The table's own progress bar, or null when nothing runs on it.
 * @param restore The latest restore frame this connection received, or null.
 */
export function isBackupSubsystemBusy(
  tableProgress: HilosTableProgress | null,
  restore: HilosRestoreStatus | null,
): boolean {
  return restore?.running === true || tableProgress !== null
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
 * Payload of the reopen page-data section: whether this one browser is offered the
 * block that ends the verification window. One boolean, because the decision is the
 * backend's and there is nothing here to recompute it from.
 */
const reopenGateSchema = z.looseObject({
  offered: z.boolean(),
})

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

/**
 * Whether this browser is offered the block that reopens the system, reactively.
 *
 * The section is personal: the backend answers it against the session behind the
 * subscribing connection, so two tabs of two different admins on the same shuttered
 * node get two different answers to the same page. It changes under a mounted page
 * exactly once: the operator's tabs were answered while the system was still frozen,
 * and when the verification window opens the backend answers each of them again with a
 * whole page response carrying the fresh section, which lands in the same page scope
 * this reads — so the block draws itself with no reload and no frame of its own. The
 * other moves need nothing: reopening the system reloads every page, and re-freezing
 * replaces this one with the maintenance surface.
 *
 * A page scope without the section — an older backend, or the moment before the
 * subscription lands — reads as no block, for the same reason the restore gate
 * withholds: the state where the block is absent is by far the common one, and drawing
 * it on a guess offers an action the backend would refuse.
 *
 * @param context The project context (the scope stores holding the page scope).
 */
export function createHilosBackupsReopenGate(
  context: HilosBackupsContext,
): ReadonlySignal<boolean> {
  const section = context.scopes.pageDataSignal(BACKUP_REOPEN_DATA)

  return computedSignal(() => {
    const parsed = reopenGateSchema.safeParse(section.get())

    return parsed.success ? parsed.data.offered : false
  })
}

/**
 * The words of the reopen block and of its confirmation, owned here rather than by the
 * three view shells.
 *
 * Same rule the panel formatters above stand on: a sentence copied into a Vue, a React
 * and an Angular template is three sentences that drift, and the one that drifts is
 * always the one nobody opened this month. Here it is one string with one owner, and a
 * shell that renders it cannot disagree with the other two.
 *
 * The modal says what the click costs before it is paid — everyone comes back in, every
 * page reloads including this one — and says the one thing that is not undoable from
 * this screen: closing the system again is a CLI command, not a button.
 */
export const HILOS_BACKUP_REOPEN_COPY = {
  /** Heading of the block offered to the browser that started the restore. */
  title: 'Verification window open',
  /** The block's body: what state the installation is in, and what the button ends. */
  body:
    'The restore finished and this installation is closed to everyone but this browser. ' +
    'Check what you need to check, then reopen it - every open page reloads itself when you do.',
  /** Label of the button in the block. */
  button: 'Reopen the system',
  /** Heading of the confirmation modal. */
  modalTitle: 'Reopen the system',
  /** Heading of the refusal details when reopening is refused: what failed. */
  refusalTitle: "Couldn't reopen the system",
  /** The modal's body: the cost of the click, and the way back. */
  modalBody:
    'This ends the verification window and lets everyone back in. Every connected page ' +
    'reloads itself, this one included. Closing the system again is a CLI command.',
} as const

/**
 * The verifier circle belongs to the freeze and lives in Maintenance (HIL-1119,
 * HIL-1120, HIL-1121). The backup page keeps this pointer because an operator
 * prepares the circle immediately before restoring and must learn about it here.
 */
export const HILOS_BACKUP_CIRCLE_POINTER_COPY = {
  /** The pointer heading. */
  title: 'Who can check the system after a restore',
  /** Where to prepare the circle and when its members enter the verification window. */
  body:
    'The verifier circle is kept in the Maintenance section. Set it up before you press Restore: ' +
    'only members with a tab open at that moment are let into the verification window.',
  /** The link label. */
  open: 'Open Maintenance',
} as const

/** The Maintenance route shared by all three backup views. */
export const HILOS_BACKUP_CIRCLE_POINTER_PATH = resolveHilosPath(
  HilosPages.MAINTENANCE,
)

/** What the view lends the backup table: the press of its declared main action. */
export interface HilosBackupsTableView {
  /** Open the page's create dialog — what the table's main action does. */
  readonly openCreate: () => void
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

/** Filter-map key narrowing the list to one scope (backend `HilosBackupHistoryTable::FILTER_SCOPE`). */
const BACKUP_FILTER_SCOPE = 'scope'

/** Filter-map key of the first day of the period (backend `HilosBackupHistoryTable::FILTER_FROM`). */
const BACKUP_FILTER_FROM = 'from'

/** Filter-map key of the last day of the period (backend `HilosBackupHistoryTable::FILTER_TO`). */
const BACKUP_FILTER_TO = 'to'

/** Hidden filter-map key of the period's reader zone (backend `HilosBackupHistoryTable::FILTER_TIME_ZONE`). */
export const BACKUP_FILTER_TIME_ZONE = 'timeZone'

/** The columns of the backup table, in display order. */
const BACKUPS_COLUMNS: HilosTableColumnOf<HilosBackupRow>[] = [
  {
    key: BACKUP_CREATED_AT_FIELD,
    label: 'Date',
    sortable: true,
    cellClass: 'text-nowrap',
    // The node holding an archive out of reach is named here; every other cell greys
    // out by the same field, and one column naming it is what the comparison needs.
    reads: [BACKUP_HOLDER_NODE_FIELD],
  },
  { key: BACKUP_ENV_FIELD, label: 'Environment', sortable: true },
  { key: BACKUP_SCOPE_FIELD, label: 'Scope', sortable: true },
  {
    key: BACKUP_SIZE_BYTES_FIELD,
    label: 'Size',
    sortable: true,
    headerClass: 'text-end',
    cellClass: 'text-end',
  },
  {
    key: BACKUP_CHECKSUM_STATE_FIELD,
    label: 'Checksum',
    cellClass: 'text-nowrap',
    reads: [BACKUP_VERIFIED_AT_FIELD],
  },
  {
    key: BACKUP_SHIP_STATE_FIELD,
    label: 'Copy',
    cellClass: 'text-nowrap',
    // The instant of the last copy, and the reason the last attempt failed behind a button.
    reads: [BACKUP_SHIPPED_AT_FIELD, BACKUP_SHIP_ERROR_FIELD],
  },
  {
    key: BACKUP_DURATION_SECONDS_FIELD,
    label: 'Duration',
    sortable: true,
    headerClass: 'text-end',
    cellClass: 'text-end',
  },
  {
    key: BACKUP_STATUS_FIELD,
    label: 'Status',
    sortable: true,
    reads: [BACKUP_FINISHED_FIELD],
  },
  {
    key: BACKUP_RESTORE_OUTCOME_FIELD,
    label: 'Restore',
    cellClass: 'text-nowrap',
    // The phase of a restore still running, the migration gate where no restore has
    // anything to report, and the outcome dialog the badge opens.
    reads: [
      BACKUP_RESTORE_PHASE_FIELD,
      BACKUP_RESTORE_MIGRATION_DECISION_FIELD,
      BACKUP_RESTORE_MIGRATION_BEHIND_FIELD,
      BACKUP_RESTORE_FINISHED_AT_FIELD,
      BACKUP_RESTORE_FAILURE_REASON_FIELD,
      BACKUP_RESTORE_DATABASE_TOUCHED_FIELD,
    ],
  },
  {
    key: BACKUP_KEEP_FIELD,
    label: 'Keep',
    headerClass: 'text-center',
    cellClass: 'text-center',
    // A pin is offered on a completed archive within reach.
    reads: [BACKUP_FINISHED_FIELD, BACKUP_HOLDER_NODE_FIELD],
  },
  {
    key: HILOS_TABLE_ACTIONS_KEY,
    label: '',
    headerClass: 'text-end',
    cellClass: 'text-end',
    // Failure detail, restore and delete are each offered by what they ask of the row
    // (hasBackupFailureDetail, offersBackupRestore, isBackupRestorable, isBackupDeletable
    // and the page's restore-blocked sentence), and the dialogs they open show the failure
    // reason, the migration notes, the environment and the scope of the CLI command.
    reads: [
      BACKUP_FINISHED_FIELD,
      BACKUP_FAILURE_REASON_FIELD,
      BACKUP_HOLDER_NODE_FIELD,
      BACKUP_CHECKSUM_STATE_FIELD,
      BACKUP_RESTORE_MIGRATION_DECISION_FIELD,
      BACKUP_RESTORE_MIGRATION_BEHIND_FIELD,
      BACKUP_RESTORE_MIGRATION_NOTICE_FIELD,
      BACKUP_ENV_FIELD,
      BACKUP_SCOPE_FIELD,
    ],
  },
]

/** The part of the backup table's frame that needs nothing from the project. */
const BACKUPS_FRAME_BASE: HilosTableFrame = {
  search: { placeholder: 'Search backups…' },
  filters: [
    {
      kind: 'select',
      key: BACKUP_FILTER_SCOPE,
      label: 'Scope',
      options: () => HILOS_BACKUP_SCOPES,
    },
    {
      kind: 'date_range',
      fromKey: BACKUP_FILTER_FROM,
      toKey: BACKUP_FILTER_TO,
      label: 'Period',
    },
  ],
  columns: BACKUPS_COLUMNS,
  empty: { title: 'No backups yet.' },
}

/**
 * What the backup table declares about its frame: a search, the scope and the
 * period as the filters of its bar, one main action, one bulk operation, and no
 * title — the page heading above already names it. The main action is the create
 * button: its press opens the page's create dialog, where the scope is picked and
 * the command is sent. The dialog belongs to the view, and the frame only lends it
 * the press — the bar's button knows neither "in flight" nor "disabled", because
 * the command leaves from the dialog, not from the button (HilosTableMainAction).
 *
 * The bulk delete needs the connection and the main action needs the view, so the
 * declaration is built per table rather than held as a constant. The framework's
 * selection panel confirms the delete before the run, and the storage agent judges
 * every copy.
 *
 * @param context The project context the delete dispatches over.
 * @param view What the view lends: the press that opens its create dialog.
 * @returns The frame declaration.
 */
function backupsFrame(
  context: HilosBackupsContext,
  view: HilosBackupsTableView,
): HilosTableFrame {
  return {
    ...BACKUPS_FRAME_BASE,
    mainAction: { label: 'Create backup', press: view.openCreate },
    bulkActions: [
      {
        key: 'delete',
        label: 'Delete',
        refusalTitle: "Couldn't delete the marked backups",
        danger: true,
        run: (target) =>
          context.actions.dispatch(
            BACKUP_BULK_DELETE_ACTION,
            hilosTableBulkPayload(HILOS_BACKUPS_TABLE, target),
            { replySchema: hilosTableBulkAcceptedSchema },
          ),
      },
    ],
  }
}

/**
 * The orders of more than one column the backup table offers in its menu — the
 * very keys and components `HilosBackupHistoryTable::sortOrders()` declares. Both
 * end on the newest copy first, the order the table opens in.
 */
const BACKUPS_ORDERS: readonly HilosTableSortOrder[] = [
  {
    key: 'scope_created',
    components: [
      { field: BACKUP_SCOPE_FIELD, direction: 'asc' },
      { field: BACKUP_CREATED_AT_FIELD, direction: 'desc' },
    ],
  },
  {
    key: 'status_created',
    components: [
      { field: BACKUP_STATUS_FIELD, direction: 'asc' },
      { field: BACKUP_CREATED_AT_FIELD, direction: 'desc' },
    ],
  },
]

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
 * @param view What the view lends the table: the press of its main action.
 */
export function createHilosBackupsTable(
  context: HilosBackupsContext,
  view: HilosBackupsTableView,
): HilosBackupsTable {
  // One handle's reset preset, kept current rather than caching the reader zone.
  // No other process reads it, so Hilos::$rt has no part in this browser lifecycle
  // (framework-first.md; runtime/rt-context.md; architecture/truth-source.md).
  const initialFilter = { [BACKUP_FILTER_TIME_ZONE]: hilosTimeZone.get() }
  const controller = new TableViewportController<HilosBackupRow>({
    initialFilter,
    resolve: resolveHilosBackupRow,
    refused: (row) => row.finished === null,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.BACKUP,
        HILOS_BACKUPS_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        HilosPages.BACKUP,
        HILOS_BACKUPS_TABLE,
        rendered,
      ),
    sendFacets: (facets) =>
      context.connection.sendTableFacets(
        HilosPages.BACKUP,
        HILOS_BACKUPS_TABLE,
        facets,
      ),
    declaredOrders: BACKUPS_ORDERS,
    frame: backupsFrame(context, view),
  })
  const teardown: Array<() => void> = []

  const syncTimeZone = (timeZone: string): void => {
    initialFilter[BACKUP_FILTER_TIME_ZONE] = timeZone
    if (controller.filter.get()[BACKUP_FILTER_TIME_ZONE] !== timeZone) {
      controller.setFilters({ [BACKUP_FILTER_TIME_ZONE]: timeZone })
    }
  }

  return {
    controller,
    start() {
      if (teardown.length > 0) {
        return
      }
      // The effective zone may have changed since creation or the last mount.
      syncTimeZone(hilosTimeZone.get())
      teardown.push(
        subscribeSignal(hilosTimeZone, syncTimeZone),
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
      )
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
    sendBackupReopen() {
      return context.actions.dispatch(BACKUP_REOPEN_ACTION, {})
    },
  }
}
