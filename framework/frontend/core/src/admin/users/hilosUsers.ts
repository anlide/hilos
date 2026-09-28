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
import { formatCalendarDate } from '../../format/date.js'
import {
  accountDeletionDaysLeft,
  formatAccountDeletionDays,
} from '../../profile/accountDeletion.js'
import { HilosPages } from '../../routing/hilosPages.js'
import { sessionUserId } from '../../session/sessionScope.js'
import { toLocal } from '../../session/serverClock.js'
import { type User } from '../../state/entity.js'
import { type EntityCollection } from '../../state/EntityCollection.js'
import { type EntityRef } from '../../state/EntityStore.js'
import {
  readBoolean,
  readNumber,
  readNumberOrNull,
  readString,
  readStringOrNull,
} from '../../state/fieldReaders.js'
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

/** One user-detail row, including whether its account owns a password identity. */
export interface HilosUserDetailRow extends HilosUserRow {
  /** Whether the account currently owns a password identity. */
  readonly hasPassword: boolean
  /** Whether the account can open the admin panel. */
  readonly admin: boolean
  /** Whether the account is blocked from signing in. */
  readonly block: boolean
  /** Scheduled erasure in local epoch milliseconds, or null when none stands. */
  readonly deletionEffectiveAt: number | null
}

/** One safe sign-in identity shown while choosing an account to merge. */
export interface HilosMergeCandidateIdentity {
  /** Identity kind, for example email or passkey. */
  readonly type: string
  /** Public identifier; hidden by the view for passkeys. */
  readonly identifier: string
  /** Provider name when the identity came from an external provider. */
  readonly provider: string | null
  /** Whether the identity has been verified. */
  readonly verified: boolean
}

/** One account that may be merged into the user whose card is open. */
export interface HilosMergeCandidateRow {
  /** Candidate user id; also the table row key. */
  readonly id: number
  /** Candidate display name. */
  readonly name: string
  /** Last activity timestamp, or null when never recorded. */
  readonly lastActivity: string | null
  /** Safe sign-in identity metadata. */
  readonly identities: readonly HilosMergeCandidateIdentity[]
  /** Whether the candidate currently owns a password identity. */
  readonly hasPassword: boolean
}

/** Password identity outcome when both accounts currently own a password. */
export type HilosPasswordFate = 'survivor' | 'loser' | 'none'

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
const MERGE_CANDIDATES_TABLE = 'mergeCandidates'
// Row slots: the user entity (typed `user` via pageEntityTypes) and the inline
// runtime connection summary a project fills on its backend.
const USER_SLOT = 'users'
const USER_IDENTITIES_SLOT = 'identities'
const USER_DELETION_SLOT = 'accountDeletions'
/** Row payload key of the standing deletion request's erasure time. */
const USER_DELETION_EFFECTIVE_AT_FIELD = 'deletionEffectiveAt'
const USER_DELETION_GRACE_DAYS_KEY = 'accountDeletionGraceDays'
const MERGE_SLOT = 'merge'
const MERGE_IDENTITY_TYPE_FIELD = 'type'
const MERGE_IDENTITY_IDENTIFIER_FIELD = 'identifier'
const MERGE_IDENTITY_PROVIDER_FIELD = 'provider'
const MERGE_IDENTITY_VERIFIED_FIELD = 'verified'

/** Candidate-row payload key of safe identity metadata. */
export const USER_IDENTITIES_FIELD = 'identities'

/** Candidate/detail-row payload key of password presence. */
export const USER_HAS_PASSWORD_FIELD = 'hasPassword'

/** Candidate-table filter key that excludes the account whose card is open. */
export const USER_MERGE_SURVIVOR_FILTER = 'survivor'

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
const HILOS_USER_MERGE_ACTION = 'hilos_user_merge'
const HILOS_USER_ADMIN_SET_ACTION = 'hilos_user_admin_set'
const HILOS_USER_BLOCK_SET_ACTION = 'hilos_user_block_set'
const HILOS_USER_DELETION_SET_ACTION = 'hilos_user_deletion_set'

/**
 * The project-supplied context the users admin reads from: the scope-partitioned
 * stores, the live connection, the action lifecycle its tracked actions dispatch
 * over, and the typed user collection. Everything else (the table keys, slot
 * names, view-model, and behavior) is the framework's.
 */
export interface HilosUsersContext<TUser extends User = User> {
  /** The scope manager that owns the page-scoped table rows. */
  readonly scopes: ScopeManager
  /** The live connection the rename action and its fail ack ride. */
  readonly connection: HilosConnection
  /** The action lifecycle the impersonation tracked action dispatches over. */
  readonly actions: ActionLifecycle
  /** The typed user collection that resolves the `users` entity slot. */
  readonly users: EntityCollection<TUser>
  /** Whether this project exposes browser account merge on the user card. */
  readonly accountMerge?: boolean
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

/** The account-merge action surface a user-detail view binds to. */
export interface HilosAccountMerge {
  /**
   * Dispatch one merge as a tracked action.
   *
   * @param survivorUserId The account that remains.
   * @param loserUserId The account merged into the survivor.
   * @param passwordFate Which password remains, only when the choice is required.
   */
  merge(
    survivorUserId: number,
    loserUserId: number,
    passwordFate?: HilosPasswordFate,
  ): ActionHandle
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
export function resolveHilosUserRow<TUser extends User>(
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

/** Narrow a raw candidate identity to the safe view-model shape. */
function resolveMergeIdentity(
  value: unknown,
): HilosMergeCandidateIdentity | null {
  const identity = recordSlot(value)
  if (identity === undefined) {
    return null
  }

  return {
    type: readString(identity, MERGE_IDENTITY_TYPE_FIELD),
    identifier: readString(identity, MERGE_IDENTITY_IDENTIFIER_FIELD),
    provider: readStringOrNull(identity, MERGE_IDENTITY_PROVIDER_FIELD),
    verified: readBoolean(identity, MERGE_IDENTITY_VERIFIED_FIELD),
  }
}

/**
 * Resolve one raw merge-candidate row into its framework view-model.
 *
 * @param row The raw table row from the page-scoped table store.
 * @param users The project's user collection resolving the `users` entity slot.
 */
export function resolveHilosMergeCandidateRow<TUser extends User>(
  row: TableRow,
  users: EntityCollection<TUser>,
): HilosMergeCandidateRow {
  const ref = row.slots[USER_SLOT] as EntityRef | undefined
  const user = ref ? users.signal(ref).get() : undefined
  const merge = recordSlot(row.slots[MERGE_SLOT])
  const identities = Array.isArray(merge?.[USER_IDENTITIES_FIELD])
    ? merge[USER_IDENTITIES_FIELD].map(resolveMergeIdentity).filter(
        (identity): identity is HilosMergeCandidateIdentity =>
          identity !== null,
      )
    : []

  return {
    id: Number(user?.id ?? row.rowKey),
    name: user?.name ?? '',
    lastActivity: user?.lastActivity ?? null,
    identities,
    hasPassword:
      merge === undefined ? false : readBoolean(merge, USER_HAS_PASSWORD_FIELD),
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

/** The merge-candidates table handle a user-detail view drives while its modal is open. */
export interface HilosMergeCandidates {
  /** The server-windowed candidate controller. */
  readonly controller: TableViewportController<HilosMergeCandidateRow>
  /**
   * Bind the table to the connection for one survivor.
   *
   * @param survivorId The open card's account, excluded by the reset-safe preset.
   */
  start(survivorId: number): void
  /** Unbind from the connection — call when the modal closes. */
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

/** Candidate columns shared by all three account-merge views. */
const MERGE_CANDIDATE_COLUMNS: HilosTableColumnOf<HilosMergeCandidateRow>[] = [
  {
    key: HILOS_TABLE_ACTIONS_KEY,
    label: '',
    source: MERGE_SLOT,
    reads: [USER_HAS_PASSWORD_FIELD],
  },
  { key: 'name', label: 'Account', card: 'title' },
  {
    key: USER_IDENTITIES_FIELD,
    label: 'Sign-in methods',
    source: MERGE_SLOT,
  },
  { key: 'lastActivity', label: 'Last activity' },
]

/** The merge picker frame; search is server-side through the candidate table. */
const MERGE_CANDIDATES_FRAME: HilosTableFrame = {
  search: { placeholder: 'Search by name, address or ID' },
  columns: MERGE_CANDIDATE_COLUMNS,
  empty: { title: 'No accounts match.' },
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
export function createHilosUsersTable<TUser extends User>(
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
 * Build the server-windowed account picker for one survivor account.
 *
 * @param context The project context (connection, scopes, and user collection).
 */
export function createHilosMergeCandidates<TUser extends User>(
  context: HilosUsersContext<TUser>,
): HilosMergeCandidates {
  const initialFilter = { [USER_MERGE_SURVIVOR_FILTER]: 0 }
  const controller = new TableViewportController<HilosMergeCandidateRow>({
    resolve: (row) => resolveHilosMergeCandidateRow(row, context.users),
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.USER,
        MERGE_CANDIDATES_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        HilosPages.USER,
        MERGE_CANDIDATES_TABLE,
        rendered,
      ),
    initialFilter,
    frame: MERGE_CANDIDATES_FRAME,
  })
  const teardown: Array<() => void> = []

  return {
    controller,
    start(survivorId) {
      initialFilter[USER_MERGE_SURVIVOR_FILTER] = survivorId
      teardown.push(
        bindTableViewport(
          context.connection,
          context.scopes,
          { page: HilosPages.USER, tableKey: MERGE_CANDIDATES_TABLE },
          controller,
          { entityTypes: { [USER_SLOT]: context.users.type } },
        ),
      )
      controller.resetFilters()
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
export function createHilosUserDetail<TUser extends User>(
  context: HilosUsersContext<TUser>,
): ReadonlySignal<HilosUserDetailRow | undefined> {
  const detailRows = context.scopes.pageTableSignal(USER_DETAIL_TABLE)

  return computedSignal(() => {
    const rows = detailRows.get()

    if (rows.length === 0) {
      return undefined
    }
    const row = rows[0]
    const identities = recordSlot(row.slots[USER_IDENTITIES_SLOT])
    const ref = row.slots[USER_SLOT] as EntityRef | undefined
    const user = ref ? context.users.signal(ref).get() : undefined
    const deletion = recordSlot(row.slots[USER_DELETION_SLOT])
    const deletionEffectiveAt = deletion
      ? readNumberOrNull(deletion, USER_DELETION_EFFECTIVE_AT_FIELD)
      : null

    return {
      ...resolveHilosUserRow(row, context.users),
      admin: user?.admin ?? false,
      block: user?.block ?? false,
      deletionEffectiveAt:
        deletionEffectiveAt === null ? null : toLocal(deletionEffectiveAt),
      hasPassword:
        identities === undefined
          ? false
          : readBoolean(identities, USER_HAS_PASSWORD_FIELD),
    }
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

/**
 * The tracked account-merge action for a user-detail view.
 *
 * @param context The project context whose action lifecycle dispatches the merge.
 */
export function createHilosAccountMerge(
  context: HilosUsersContext,
): HilosAccountMerge {
  return {
    merge(survivorUserId, loserUserId, passwordFate) {
      return context.actions.dispatch(HILOS_USER_MERGE_ACTION, {
        survivorUserId,
        loserUserId,
        ...(passwordFate === undefined ? {} : { passwordFate }),
      })
    },
  }
}

/** The tracked account-card operations and the identity behind their controls. */
export interface HilosUserLifecycle {
  /** Person behind the current session. */
  readonly currentUserId: ReadonlySignal<number | null>
  /** Deletion grace period from the page's first answer. */
  readonly graceDays: ReadonlySignal<number | null>
  /**
   * Grant or remove administrator rights.
   * @param userId The target account.
   * @param admin The requested rights flag.
   */
  setAdmin(userId: number, admin: boolean): ActionHandle
  /**
   * Block the account or lift its block.
   * @param userId The target account.
   * @param block The requested block flag.
   */
  setBlock(userId: number, block: boolean): ActionHandle
  /**
   * Schedule deletion or cancel the standing request.
   * @param userId The target account.
   * @param scheduled Whether a deletion request should stand.
   */
  setDeletion(userId: number, scheduled: boolean): ActionHandle
}

/**
 * Account-card controls read committed state and dispatch tracked page actions.
 * @param context The page stores and the action lifecycle.
 */
export function createHilosUserLifecycle(
  context: HilosUsersContext,
): HilosUserLifecycle {
  const graceDays = context.scopes.pageDataSignal(USER_DELETION_GRACE_DAYS_KEY)

  return {
    currentUserId: sessionUserId(context.scopes),
    graceDays: computedSignal(() => {
      const value = graceDays.get()

      return typeof value === 'number' && Number.isInteger(value) && value >= 1
        ? value
        : null
    }),
    setAdmin: (userId, admin) =>
      context.actions.dispatch(HILOS_USER_ADMIN_SET_ACTION, { userId, admin }),
    setBlock: (userId, block) =>
      context.actions.dispatch(HILOS_USER_BLOCK_SET_ACTION, { userId, block }),
    setDeletion: (userId, scheduled) =>
      context.actions.dispatch(HILOS_USER_DELETION_SET_ACTION, {
        userId,
        scheduled,
      }),
  }
}

/** English copy shared by the three account-card views. */
export const HILOS_USER_LIFECYCLE_COPY = {
  rights: 'Rights',
  access: 'Access to the product',
  administrator: 'Administrator',
  administratorHint: 'Opens the whole admin panel of this project',
  blocked: 'Blocked',
  blockedHint: 'Sessions end, sign-in is closed',
  deletionScheduled: 'Deletion scheduled',
  deletionSummary: 'Erased on {date} — {days} left',
  deletionNone: 'No deletion is scheduled',
  yes: 'Yes',
  no: 'No',
  grant: 'Grant rights',
  revoke: 'Remove rights',
  block: 'Block',
  unblock: 'Lift the block',
  delete: 'Delete account…',
  cancelDeletion: 'Cancel deletion',
  cancel: 'Cancel',
  ownRevokeReason: 'You cannot remove your own rights',
  ownBlockReason: 'You cannot block yourself',
  ownDeleteReason: 'Delete your own account from your profile',
  adminDeleteReason: 'Remove the admin rights first',
  confirmations: {
    grant: {
      title: 'Grant admin rights',
      paragraphs: [
        'This person will have access to the whole admin panel: settings, logs, backups and users, including this screen.',
        'Rights apply immediately, without signing in again. Open tabs are updated with the access they now have.',
        'Any administrator may remove these rights, except when this is the last active administrator.',
      ],
      confirm: 'Grant rights',
      danger: false,
    },
    revoke: {
      title: 'Remove admin rights',
      paragraphs: [
        'This person will lose access to the admin panel immediately. Open admin screens will show an access refusal.',
        'Their sessions stay open and they remain signed in to the product. Only admin rights are removed.',
      ],
      confirm: 'Remove rights',
      danger: true,
    },
    block: {
      title: 'Block account',
      paragraphs: [
        'All sessions of this person end immediately. They cannot sign in again while the account is blocked.',
        'No reason is stored: the person sees that access is closed, not why.',
        'Blocking is reversible and deletes nothing. Lifting the block restores sign-in immediately.',
      ],
      confirm: 'Block',
      danger: true,
    },
    unblock: {
      title: 'Lift the block',
      paragraphs: [
        'This person can sign in again immediately, without waiting or receiving a message.',
        'Their previous sessions do not return. They must sign in again.',
      ],
      confirm: 'Lift the block',
      danger: false,
    },
    delete: {
      title: 'Delete account',
      paragraphs: [
        'After {graceDays} the account and its data are erased for good.',
        'Until then, an administrator can cancel deletion here, and the person can cancel it from their profile.',
      ],
      confirm: 'Schedule deletion',
      danger: true,
    },
    cancelDeletion: {
      title: 'Cancel deletion',
      paragraphs: [
        'The scheduled deletion is canceled. The account and its data remain.',
      ],
      confirm: 'Cancel deletion',
      danger: false,
    },
  },
} as const

/** The six parameter-free confirmations offered by the account card. */
export type HilosUserLifecycleChoice =
  keyof typeof HILOS_USER_LIFECYCLE_COPY.confirmations

/** A confirmation snapshots its addressee so a later row update cannot retarget it. */
export interface HilosUserLifecyclePrompt {
  readonly userId: number
  readonly choice: HilosUserLifecycleChoice
  readonly title: string
  readonly paragraphs: readonly string[]
  readonly confirm: string
  readonly danger: boolean
}

/** A status row and its visible reason when the control is unavailable. */
export interface HilosUserLifecycleRow {
  readonly key: 'admin' | 'block' | 'deletion'
  readonly title: string
  readonly hint: string
  readonly state: boolean
  readonly choice: HilosUserLifecycleChoice
  readonly disabled: boolean
  readonly reason: string | null
  readonly reasonSpace: string
}

/** One account-card section, rendered alike by all three SDKs. */
export interface HilosUserLifecycleSection {
  readonly key: 'rights' | 'access'
  readonly title: string
  readonly rows: readonly HilosUserLifecycleRow[]
}

/**
 * Present the three independent states and the controls the current card offers.
 * @param detail The committed user row, absent until the table arrives.
 * @param currentUserId The person behind this session.
 * @param graceDays The subscription's grace-period snapshot.
 * @param now The current local epoch milliseconds, for the remaining days.
 */
export function hilosUserLifecycleSections(
  detail: HilosUserDetailRow | undefined,
  currentUserId: number | null,
  graceDays: number | null,
  now: number,
): readonly HilosUserLifecycleSection[] {
  if (!detail) return []
  const copy = HILOS_USER_LIFECYCLE_COPY
  const own = detail.id === currentUserId
  const scheduled = detail.deletionEffectiveAt !== null
  const deletionReason = scheduled
    ? null
    : own
      ? copy.ownDeleteReason
      : detail.admin
        ? copy.adminDeleteReason
        : null

  return [
    {
      key: 'rights',
      title: copy.rights,
      rows: [
        {
          key: 'admin',
          title: copy.administrator,
          hint: copy.administratorHint,
          state: detail.admin,
          choice: detail.admin ? 'revoke' : 'grant',
          disabled: own && detail.admin,
          reason: own && detail.admin ? copy.ownRevokeReason : null,
          reasonSpace: copy.ownRevokeReason,
        },
      ],
    },
    {
      key: 'access',
      title: copy.access,
      rows: [
        {
          key: 'block',
          title: copy.blocked,
          hint: copy.blockedHint,
          state: detail.block,
          choice: detail.block ? 'unblock' : 'block',
          disabled: own && !detail.block,
          reason: own && !detail.block ? copy.ownBlockReason : null,
          reasonSpace: copy.ownBlockReason,
        },
        {
          key: 'deletion',
          title: copy.deletionScheduled,
          hint:
            detail.deletionEffectiveAt === null
              ? copy.deletionNone
              : copy.deletionSummary
                  .replace(
                    '{date}',
                    formatCalendarDate(detail.deletionEffectiveAt),
                  )
                  .replace(
                    '{days}',
                    formatAccountDeletionDays(
                      accountDeletionDaysLeft(detail.deletionEffectiveAt, now),
                    ),
                  ),
          state: scheduled,
          choice: scheduled ? 'cancelDeletion' : 'delete',
          disabled:
            !scheduled && (deletionReason !== null || graceDays === null),
          reason: deletionReason,
          reasonSpace: copy.ownDeleteReason,
        },
      ],
    },
  ]
}

/**
 * Freeze the person and the confirmation text at the moment a control is pressed.
 * @param detail The account whose control was pressed.
 * @param choice The requested operation.
 * @param graceDays The grace period named by this page's subscription.
 */
export function hilosUserLifecyclePrompt(
  detail: HilosUserDetailRow,
  choice: HilosUserLifecycleChoice,
  graceDays: number | null,
): HilosUserLifecyclePrompt {
  const copy = HILOS_USER_LIFECYCLE_COPY.confirmations[choice]

  return {
    userId: detail.id,
    choice,
    title: `${copy.title} · ${detail.name}`,
    paragraphs: copy.paragraphs.map((line) =>
      line.replace(
        '{graceDays}',
        graceDays === null ? '' : formatAccountDeletionDays(graceDays),
      ),
    ),
    confirm: copy.confirm,
    danger: copy.danger,
  }
}

/**
 * Dispatch the operation that was confirmed, keeping its original account and choice.
 * @param lifecycle The tracked account-card actions.
 * @param prompt The confirmation that was shown.
 */
export function submitHilosUserLifecycle(
  lifecycle: HilosUserLifecycle,
  prompt: HilosUserLifecyclePrompt,
): ActionHandle {
  switch (prompt.choice) {
    case 'grant':
      return lifecycle.setAdmin(prompt.userId, true)
    case 'revoke':
      return lifecycle.setAdmin(prompt.userId, false)
    case 'block':
      return lifecycle.setBlock(prompt.userId, true)
    case 'unblock':
      return lifecycle.setBlock(prompt.userId, false)
    case 'delete':
      return lifecycle.setDeletion(prompt.userId, true)
    case 'cancelDeletion':
      return lifecycle.setDeletion(prompt.userId, false)
  }
}
