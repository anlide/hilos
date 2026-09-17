// The framework Hilos users/user admin headless: the view-model, the row
// resolver, and the controller/selector/actions factories the per-framework
// views render from. It is framework-agnostic (imports no UI framework) and
// reads only @hilos/core primitives, so the Vue/React/Angular HilosUsersPage /
// HilosUserPage views stay thin (multiframework-core.md).
//
// The page is a hybrid: its identity, view-model, and behavior are the
// framework's, but the data arrives through slots a project fills on its backend
// (the `users` entity slot and the inline `connections` slot). A project supplies
// a HilosUsersContext — its scope manager, its connection, its action lifecycle,
// and its typed user collection — and the framework owns the rest.

import {
  type ActionHandle,
  type ActionLifecycle,
} from '../../connection/actionLifecycle.js'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { HilosPages } from '../../routing/hilosPages.js'
import { sessionUserId } from '../../session/sessionScope.js'
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
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import {
  HILOS_TABLE_ACTIONS_KEY,
  type HilosTableColumnOf,
} from '../../table/hilosTableColumn.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'

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

/**
 * Row payload key of the live presence, inside the inline `connections` slot.
 * Exported because it is also the table column key the three views declare, so the
 * wire name has one owner instead of a copy per view.
 */
export const USER_PRESENCE_FIELD = 'presence'

/** Row payload key of the open-session count, inside the inline `connections` slot. */
export const USER_ONLINE_SESSION_COUNT_FIELD = 'onlineSessionCount'

// Wire keys: the framework users table and the single-user detail table, which
// carry the same row slots so both resolve through resolveHilosUserRow. A project
// binds its backend tables to these keys.
const HILOS_USERS_TABLE = 'hilosUsers'
const USER_DETAIL_TABLE = 'userDetail'
// Row slots: the user entity (typed `user` via pageEntityTypes) and the inline
// runtime connection summary a project fills on its backend.
const USER_SLOT = 'users'

/**
 * Row slot key of the inline connection summary.
 *
 * Exported for the same reason as {@link USER_PRESENCE_FIELD} next door: the three
 * views name this slot when they declare which source their presence columns are
 * built from, and a string literal repeated in each of them is three places where a
 * typo silently takes the freshness mark off.
 */
export const USER_CONNECTIONS_SLOT = 'connections'

// Action / ack signal names (the rename round-trip). The fail ack carries no
// registered schema, so the view observes its type only.
const HILOS_USER_UPDATE_ACTION = 'hilos_user_update'
const HILOS_USER_UPDATE_FAIL = 'hilos_user_update_fail'
// The takeover the users list offers on every row but your own. Owned by the
// framework Hilos users page since HIL-824 (it was the sessions library's before,
// and the ADMIN level that closes it is a thing only a page carries), so the SDK
// draws the control and no project restates the name.
const HILOS_IMPERSONATE_START_ACTION = 'hilos_impersonate_start'

/**
 * The project-supplied context the users admin reads from: the scope-partitioned
 * stores, the live connection, the action lifecycle its tracked actions dispatch
 * over, and the typed user collection. Everything else (the table keys, slot
 * names, view-model, and behavior) is the framework's.
 */
export interface HilosUsersContext<
  TUser extends HilosUserProfile = HilosUserProfile,
> {
  /** The scope manager that owns the page-scoped table rows. */
  readonly scopes: ScopeManager
  /** The live connection the rename action and its fail ack ride. */
  readonly connection: HilosConnection
  /** The action lifecycle the impersonation tracked action dispatches over. */
  readonly actions: ActionLifecycle
  /** The typed user collection that resolves the `users` entity slot. */
  readonly users: EntityCollection<TUser>
}

/** The impersonation control a users-list view binds to. */
export interface HilosImpersonate {
  /**
   * The signed-in user's own id, or null before the handshake answers. The row
   * that carries it offers no takeover button: the backend refuses taking
   * yourself over, and a control whose only outcome is a refusal is not one.
   */
  readonly currentUserId: ReadonlySignal<number | null>
  /**
   * Dispatch one takeover as a tracked action.
   *
   * @param targetUserId The user id to act as.
   */
  start(targetUserId: number): ActionHandle
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
  const connection = recordSlot(row.slots[USER_CONNECTIONS_SLOT])

  return {
    id: Number(user?.id ?? row.rowKey),
    name: user?.name ?? '',
    lastActivity: user?.lastActivity ?? null,
    presence: toHilosPresence(connection?.[USER_PRESENCE_FIELD]),
    onlineSessionCount: connection
      ? readNumber(connection, USER_ONLINE_SESSION_COUNT_FIELD)
      : 0,
  }
}

/** The users table handle a users view drives: the controller plus its mount lifecycle. */
export interface HilosUsersTable {
  /** The server-windowed controller the view renders rows, descriptor, and pending from. */
  readonly controller: TableViewportController<HilosUserRow>
  /** Bind the table to the connection and request the first window — call on mount. */
  start(): void
  /** Unbind from the connection — call on unmount. */
  dispose(): void
}

/**
 * The columns of the users table, in display order. The two presence columns name
 * the connections slot they are built from: that runtime summary is the one thing
 * on the row that can stop being current, while the rest comes from the user
 * record in the database, and a database does not go quiet.
 */
const USERS_COLUMNS: HilosTableColumnOf<HilosUserRow>[] = [
  { key: 'id', label: 'ID', sortable: true, cellClass: 'text-body-secondary' },
  { key: 'name', label: 'Name', sortable: true, cellClass: 'fw-medium' },
  {
    key: USER_PRESENCE_FIELD,
    label: 'Presence',
    sortable: true,
    source: USER_CONNECTIONS_SLOT,
  },
  {
    key: USER_ONLINE_SESSION_COUNT_FIELD,
    label: 'Sessions',
    sortable: true,
    headerClass: 'text-end',
    cellClass: 'text-end',
    source: USER_CONNECTIONS_SLOT,
  },
  { key: 'lastActivity', label: 'Last activity', sortable: true },
  {
    key: HILOS_TABLE_ACTIONS_KEY,
    label: '',
    headerClass: 'text-end',
    cellClass: 'text-end',
    // The takeover button stands on every row but the reader's own.
    reads: ['id'],
  },
]

/**
 * What the users table declares about its frame. No title: the page heading above
 * already names it, and the table takes its accessible name from there.
 */
const USERS_FRAME: HilosTableFrame = {
  search: { placeholder: 'Search users…' },
  columns: USERS_COLUMNS,
  empty: { title: 'No users yet.' },
}

/**
 * The server-windowed controller for the Hilos users table: search, sort, and
 * paging change the viewport descriptor sent over the connection, and the backend
 * replies a window plus live deltas scoped to the table's (page, tableKey)
 * address. Rows resolve through {@link resolveHilosUserRow} against the context's
 * user collection; the `users` entity slot is normalized under that collection's
 * type so a window row dedupes against the same user wherever it appears (and a
 * rename fans out for free). The returned handle's `start` binds the table and
 * requests the first window; `dispose` unbinds it (the view calls them on mount /
 * unmount).
 *
 * @param context The project context (connection, scopes, and user collection).
 */
export function createHilosUsersTable<TUser extends HilosUserProfile>(
  context: HilosUsersContext<TUser>,
): HilosUsersTable {
  const controller = new TableViewportController<HilosUserRow>({
    resolve: (row) => resolveHilosUserRow(row, context.users),
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.USERS,
        HILOS_USERS_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        HilosPages.USERS,
        HILOS_USERS_TABLE,
        rendered,
      ),
    frame: USERS_FRAME,
  })
  const teardown: Array<() => void> = []

  return {
    controller,
    start() {
      teardown.push(
        bindTableViewport(
          context.connection,
          context.scopes,
          { page: HilosPages.USERS, tableKey: HILOS_USERS_TABLE },
          controller,
          // The `users` slot is the project's user entity; normalize it under the
          // collection's type so the row resolves through context.users.
          { entityTypes: { [USER_SLOT]: context.users.type } },
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

/**
 * The impersonation surface for the users-list view: the takeover submits as a
 * tracked action over the lifecycle, returning an ActionHandle whose `done`
 * resolves on the page's `::success` ack and rejects with the reason on `::fail`
 * — the confirm modal closes on the first and stays open with the sentence on the
 * second (authoritative-backend). Success needs nothing else: the takeover
 * arrives as the rebound session on the handshake broadcast, which redraws the
 * shell and drops this admin-only page on its own.
 *
 * @param context The project context (the scope stores and the action lifecycle).
 */
export function createHilosImpersonate(
  context: HilosUsersContext,
): HilosImpersonate {
  return {
    currentUserId: sessionUserId(context.scopes),
    start(targetUserId) {
      return context.actions.dispatch(HILOS_IMPERSONATE_START_ACTION, {
        targetUserId,
      })
    },
  }
}
