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

import { type HilosConnection } from '../../connection/HilosConnection.js'
import { HilosPages } from '../../routing/hilosPages.js'
import {
  readBoolean,
  readNumber,
  readString,
} from '../../state/fieldReaders.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
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
// merged runtime fields). A project binds its backend to these keys.
const HILOS_BACKUPS_TABLE = 'hilosBackups'
const BACKUP_SLOT = 'backup'
const HILOS_BACKUPS_PAGE_SIZE = 10

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
