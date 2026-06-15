// The framework Hilos users/user admin headless: the view-model, the row
// resolver, and the controller/selector/actions factories the per-framework
// views render from. It is framework-agnostic (imports no UI framework) and
// reads only @hilos/core primitives, so the Vue/React/Angular HilosUsersPage /
// HilosUserPage views stay thin (multiframework-core.md).
//
// The page is a hybrid: its identity, view-model, and behavior are the
// framework's, but the data arrives through slots a project fills on its backend
// (the `users` entity slot and the inline `connections` slot). A project supplies
// a HilosUsersContext — its scope manager, its connection, and its typed user
// collection — and the framework owns the rest.

import { type HilosConnection } from '../../connection/HilosConnection.js'
import { type Entity } from '../../state/entity.js'
import { type EntityCollection } from '../../state/EntityCollection.js'
import { type EntityRef } from '../../state/EntityStore.js'
import { readNumber } from '../../state/fieldReaders.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
} from '../../state/signal.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { TableController } from '../../table/TableController.js'

/** A user's connection presence; a string union so a third state extends it. */
export type HilosPresence = 'online' | 'offline'

const PRESENCE_VALUES: readonly HilosPresence[] = ['online', 'offline']

/**
 * Narrow a raw slot value to a HilosPresence, defaulting to `offline`.
 *
 * @param value The raw presence value from a payload slot.
 */
export function toHilosPresence(value: unknown): HilosPresence {
  return PRESENCE_VALUES.includes(value as HilosPresence)
    ? (value as HilosPresence)
    : 'offline'
}

/**
 * The user-entity contract the users admin needs: a project's user entity must
 * resolve to at least these fields for the row resolver to build its view-model.
 * The project's own entity (e.g. the chat `User`) extends this with more.
 */
export interface HilosUserProfile extends Entity {
  /** Display name. */
  readonly name: string
  /** Last activity timestamp, or null when never recorded. */
  readonly lastActivity: string | null
}

/** One row of the Hilos users table — the framework users view-model. */
export interface HilosUserRow {
  /** User id; also the table row key. */
  readonly id: number
  /** Display name (from the `users` entity slot, so a rename fans out for free). */
  readonly name: string
  /** Last activity timestamp, or null when never recorded. */
  readonly lastActivity: string | null
  /** Live connection presence (from the inline `connections` slot). */
  readonly presence: HilosPresence
  /** Count of the user's currently open sessions. */
  readonly onlineSessionCount: number
}

// Wire keys: the framework users table and the single-user detail table, which
// carry the same row slots so both resolve through resolveHilosUserRow. A project
// binds its backend tables to these keys.
const HILOS_USERS_TABLE = 'hilosUsers'
const USER_DETAIL_TABLE = 'userDetail'
// Row slots: the user entity (typed `user` via pageEntityTypes) and the inline
// runtime connection summary a project fills on its backend.
const USER_SLOT = 'users'
const CONNECTIONS_SLOT = 'connections'
const PRESENCE_FIELD = 'presence'
const ONLINE_SESSION_COUNT_FIELD = 'onlineSessionCount'
// Action / ack signal names (the rename round-trip). The fail ack carries no
// registered schema, so the view observes its type only.
const HILOS_USER_UPDATE_ACTION = 'hilos_user_update'
const HILOS_USER_UPDATE_FAIL = 'hilos_user_update_fail'

/**
 * The project-supplied context the users admin reads from: the scope-partitioned
 * stores, the live connection, and the typed user collection. Everything else
 * (the table keys, slot names, view-model, and behavior) is the framework's.
 */
export interface HilosUsersContext<
  TUser extends HilosUserProfile = HilosUserProfile,
> {
  /** The scope manager that owns the page-scoped table rows. */
  readonly scopes: ScopeManager
  /** The live connection the rename action and its fail ack ride. */
  readonly connection: HilosConnection
  /** The typed user collection that resolves the `users` entity slot. */
  readonly users: EntityCollection<TUser>
}

/** The rename action surface a user-detail view binds to. */
export interface HilosUserRename {
  /** The latest rename failure reason, or null when the form is clear. */
  readonly renameError: ReadonlySignal<string | null>
  /** Clear the rename error (on opening or cancelling the edit form). */
  clearRenameError(): void
  /**
   * Submit a user rename: clear any prior error and send the update action.
   * Returns false, sending nothing, when the connection is not `connected`.
   *
   * @param id The user id to rename.
   * @param name The new display name.
   */
  submitRename(id: number, name: string): boolean
}

/** Read a row slot as an inline record, or undefined when it is not one. */
function recordSlot(slot: unknown): Record<string, unknown> | undefined {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : undefined
}

/**
 * Resolve one raw users-table row into its view-model: the referenced user
 * entity folded together with the inline connection summary. Shared by the list
 * and the single-user detail page, which deliver the same row shape. Reading the
 * entity through the collection keeps the resolved row reactive to a rename.
 *
 * @param row The raw table row from the page-scoped table store.
 * @param users The project's user collection resolving the `users` entity slot.
 */
export function resolveHilosUserRow<TUser extends HilosUserProfile>(
  row: TableRow,
  users: EntityCollection<TUser>,
): HilosUserRow {
  const ref = row.slots[USER_SLOT] as EntityRef | undefined
  const user = ref ? users.signal(ref).get() : undefined
  const connection = recordSlot(row.slots[CONNECTIONS_SLOT])

  return {
    id: Number(user?.id ?? row.rowKey),
    name: user?.name ?? '',
    lastActivity: user?.lastActivity ?? null,
    presence: toHilosPresence(connection?.[PRESENCE_FIELD]),
    onlineSessionCount: connection
      ? readNumber(connection, ONLINE_SESSION_COUNT_FIELD)
      : 0,
  }
}

/**
 * The headless controller for the Hilos users table (client viewport): search by
 * name, sort by any column, paginate at 20 rows. Rows resolve through
 * {@link resolveHilosUserRow} against the context's user collection.
 *
 * @param context The project context (scopes + user collection).
 */
export function createHilosUsersTable<TUser extends HilosUserProfile>(
  context: HilosUsersContext<TUser>,
): TableController<HilosUserRow> {
  return new TableController<HilosUserRow>({
    source: context.scopes.pageTableSignal(HILOS_USERS_TABLE),
    resolve: (row) => resolveHilosUserRow(row, context.users),
    searchText: (row) => row.name,
    sortValue: (row, field) => {
      switch (field) {
        case 'name':
          return row.name
        case 'presence':
          return row.presence
        case 'onlineSessionCount':
          return row.onlineSessionCount
        case 'lastActivity':
          return row.lastActivity
        default:
          return row.id
      }
    },
    pageSize: 20,
    initialSort: { field: 'id', direction: 'asc' },
  })
}

/**
 * The requested user's detail row, or undefined until the single-row detail
 * table lands. The backend filters the table to the route's userId, so the first
 * (only) row is the requested user.
 *
 * @param context The project context (scopes + user collection).
 */
export function createHilosUserDetail<TUser extends HilosUserProfile>(
  context: HilosUsersContext<TUser>,
): ReadonlySignal<HilosUserRow | undefined> {
  const detailRows = context.scopes.pageTableSignal(USER_DETAIL_TABLE)

  return computedSignal(() => {
    const rows = detailRows.get()

    return rows.length === 0
      ? undefined
      : resolveHilosUserRow(rows[0], context.users)
  })
}

/**
 * The rename action surface for the user-detail view. The backend acks a rename
 * through dedicated project signals rather than the framework action_error, so
 * success is observed state-driven in the view (the committed name reaches the
 * draft) while a failure is surfaced here from the fail ack. The fail signal has
 * no registered schema, so it arrives as an `unknownSignal`; we react to its type
 * only and never read its raw payload (the parse boundary stays the one place
 * that interprets a frame — wire-protocol.md).
 *
 * @param context The project context (the live connection the rename rides).
 */
export function createHilosUserRename(
  context: HilosUsersContext,
): HilosUserRename {
  const error = createSignal<string | null>(null)

  // A rejected rename arrives as the backend's fail ack; surface a generic
  // message (the reason text stays backend-side, behind the unregistered schema).
  context.connection.on('unknownSignal', (signal) => {
    if (signal.type === HILOS_USER_UPDATE_FAIL) {
      error.set('Could not rename the user. Please try again.')
    }
  })

  return {
    renameError: error,
    clearRenameError: () => error.set(null),
    submitRename(id, name) {
      error.set(null)

      return context.connection.sendAction(HILOS_USER_UPDATE_ACTION, {
        id,
        name,
      })
    },
  }
}
