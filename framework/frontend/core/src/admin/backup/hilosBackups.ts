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
/** The page's own actions, so an addressed failure notice for one is recognized as ours. */
const BACKUP_ACTIONS = new Set<string>([
  BACKUP_CREATE_ACTION,
  BACKUP_DELETE_ACTION,
  BACKUP_SET_KEEP_ACTION,
])

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
  }
}
