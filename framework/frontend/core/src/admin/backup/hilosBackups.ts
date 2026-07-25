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
  /** Application environment the backup was taken in. */
  readonly env: string
  /** Backup scope value (`full` | `schema-seed` | `schema-only`). */
  readonly scope: string
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
}

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
 * Resolve one raw backup table row into its view-model. The merged runtime fields
 * ride a single inline `backup` slot (no entity reference — a backup row is
 * page-scoped, keyed by its id), so this reads the slot as a plain record.
 *
 * @param row The raw table row from the page-scoped table store.
 */
export function resolveHilosBackupRow(row: TableRow): HilosBackupRow {
  const slot = recordSlot(row.slots[BACKUP_SLOT]) ?? {}

  return {
    id: readString(slot, 'id') || String(row.rowKey),
    createdAt: readString(slot, 'createdAt'),
    env: readString(slot, 'env'),
    scope: readString(slot, 'scope'),
    sizeBytes: readNumber(slot, 'sizeBytes'),
    durationSeconds: readNumber(slot, 'durationSeconds'),
    keep: readBoolean(slot, 'keep'),
    status: readString(slot, 'status'),
    finished: toFinished(slot['finished']),
  }
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
    initialSort: { field: 'createdAt', direction: 'desc' },
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
 * failure (authoritative-backend). Delete and set-keep mark their row as an
 * own-change on the table (table.expectOwnChange) so the echo applies at once in
 * this tab while other tabs keep the pending gate; create makes a new row whose
 * server-minted id is unknown up front, so it has no own-change to mark and simply
 * surfaces over the live table.
 *
 * @param context The project context (the action lifecycle the actions dispatch over).
 * @param table The backup table controller the own-change marks land on.
 */
export function createHilosBackupsActions(
  context: HilosBackupsContext,
  table: TableViewportController<HilosBackupRow>,
): HilosBackupsActions {
  return {
    sendBackupCreate(scope) {
      return context.actions.dispatch(BACKUP_CREATE_ACTION, { scope })
    },
    sendBackupDelete(id) {
      const handle = context.actions.dispatch(BACKUP_DELETE_ACTION, {
        backupId: id,
      })
      table.expectOwnChange(id, handle.done)

      return handle
    },
    sendBackupSetKeep(id, keep) {
      const handle = context.actions.dispatch(BACKUP_SET_KEEP_ACTION, {
        backupId: id,
        keep,
      })
      table.expectOwnChange(id, handle.done)

      return handle
    },
  }
}
