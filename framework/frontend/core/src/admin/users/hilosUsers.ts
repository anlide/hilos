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
//
// The card reads a person's standing as ONE verdict (HIL-945): the page's
// `accountStanding` data and the live `hilos_account_standing_state` frame after
// it. The rows of the access section and the badge in the card's header are
// drawn from it, so what the rows say and what the badge shows cannot differ.

import { z } from 'zod'

import {
  createHilosStepUpActions,
  createHilosStepUpStep,
  type HilosStepUpOpenOutcome,
  type HilosStepUpStep,
} from '../../auth/stepUp.js'
import {
  type ActionHandle,
  type ActionLifecycle,
} from '../../connection/actionLifecycle.js'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { formatCalendarDate } from '../../format/date.js'
import {
  formatHilosLegalDate,
  hilosLegalDocumentLabel,
  type HilosLegalDocumentKey,
} from '../../legal/legalAgreements.js'
import {
  accountDeletionDaysLeft,
  formatAccountDeletionDays,
} from '../../profile/accountDeletion.js'
import { type ProjectSignal } from '../../protocol/parseSignal.js'
import { resolveHilosPath } from '../../routing/hilosAdmin.js'
import { HilosPages } from '../../routing/hilosPages.js'
import { type PageRouteMatch } from '../../routing/PageRouter.js'
import { hilosAdminAccess } from '../../session/adminAccess.js'
import {
  hilosStandingTone,
  type HilosStandingTone,
} from '../../session/accountStanding.js'
import {
  readHilosAccountStanding,
  sessionUserId,
  type HilosAccountStanding,
  type HilosAccountStandingKind,
} from '../../session/sessionScope.js'
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
import {
  HIDDEN_VALUE,
  type Hideable,
  isHiddenValue,
} from '../../state/hiddenValue.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
  subscribeSignal,
} from '../../state/signal.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import {
  HILOS_TABLE_ACTIONS_KEY,
  type HilosTableColumnOf,
} from '../../table/hilosTableColumn.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import { hiddenAsWord } from '../viewMode.js'

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
  /**
   * Display name (from the `users` entity slot, so a rename fans out for free), or
   * {@link HIDDEN_VALUE} for a viewer of the admin view mode, who is sent it hidden.
   */
  readonly name: Hideable<string>
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
  /**
   * Sign-in address of the unconfirmed password identity, or null when the password is
   * confirmed, absent, or hidden from a viewer of the admin view mode.
   */
  readonly unverifiedPasswordAddress: string | null
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
  /**
   * Candidate display name.
   *
   * Hidden from a viewer of the admin view mode.
   */
  readonly name: Hideable<string>
  /** Last activity timestamp, or null when never recorded. */
  readonly lastActivity: string | null
  /**
   * Safe sign-in identity metadata.
   *
   * Hidden from a viewer of the admin view mode.
   */
  readonly identities: Hideable<readonly HilosMergeCandidateIdentity[]>
  /** Whether the candidate currently owns a password identity. */
  readonly hasPassword: boolean
  /**
   * Sign-in address of the unconfirmed password identity, or null when the password is
   * confirmed, absent, or hidden from a viewer of the admin view mode.
   */
  readonly unverifiedPasswordAddress: string | null
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
/** Field of the user entity carrying the display name (`userFromFields`). */
const USER_NAME_FIELD = 'name'
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

/** Candidate/detail-row payload key of an unconfirmed password identity's sign-in address. */
export const USER_UNVERIFIED_PASSWORD_ADDRESS_FIELD =
  'unverifiedPasswordAddress'

/** Candidate-table filter key that excludes the account whose card is open. */
export const USER_MERGE_SURVIVOR_FILTER = 'survivor'

/**
 * Users-table filter key narrowing the list to the people past one legal
 * document's deadline, whatever the refusal setting says (PHP
 * `AbstractHilosUsersTable::FILTER_LAPSED`, HIL-945). Its value is the document
 * key, and the same key names the optional tail of the list's address.
 */
export const HILOS_USERS_LAPSED_FILTER = 'lapsed'

/**
 * Page data key of the standing of the person the card shows (PHP
 * `AbstractHilosUserPage::ACCOUNT_STANDING`, HIL-945).
 */
export const HILOS_USER_STANDING_SECTION = 'accountStanding'

/**
 * Server→client (WS_USER): the standing of the person a card shows, whenever it
 * changed (PHP `HilosSignalConstants::HILOS_ACCOUNT_STANDING_STATE`, HIL-945).
 */
export const SIGNAL_ACCOUNT_STANDING_STATE = 'hilos_account_standing_state'

/**
 * The live standing frame (PHP `AccountStandingStateSignalData`). Both members
 * are read past the parse boundary rather than at it: a viewer of the admin
 * view mode is sent hidden marks in their place, and such a frame is still one
 * to take — it says the standing cannot be read ({@link readHilosAccountStanding}).
 */
export const accountStandingStateSchema = z.looseObject({
  userId: z.unknown(),
  accountStanding: z.unknown(),
})

/**
 * The live standing frame keyed for a connection's `projectSchemas`.
 * {@link createHilosConnection} merges it in.
 */
export const ACCOUNT_STANDING_SIGNAL_SCHEMAS = {
  [SIGNAL_ACCOUNT_STANDING_STATE]: accountStandingStateSchema,
}

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

  // The typed person keeps a string name for every reader outside the admin
  // section; the row asks the collection whether it arrived hidden.
  const nameHidden = ref ? users.hidden(ref, USER_NAME_FIELD).get() : false

  return {
    id: Number(user?.id ?? row.rowKey),
    name: nameHidden ? HIDDEN_VALUE : (user?.name ?? ''),
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
  const nameHidden =
    ref !== undefined && users.hidden(ref, USER_NAME_FIELD).get()
  const merge = recordSlot(row.slots[MERGE_SLOT])
  const rawIdentities = merge?.[USER_IDENTITIES_FIELD]
  const identities: Hideable<readonly HilosMergeCandidateIdentity[]> =
    isHiddenValue(rawIdentities)
      ? HIDDEN_VALUE
      : Array.isArray(rawIdentities)
        ? rawIdentities
            .map(resolveMergeIdentity)
            .filter(
              (identity): identity is HilosMergeCandidateIdentity =>
                identity !== null,
            )
        : []

  return {
    id: Number(user?.id ?? row.rowKey),
    name: nameHidden ? HIDDEN_VALUE : (user?.name ?? ''),
    lastActivity: user?.lastActivity ?? null,
    identities,
    hasPassword:
      merge === undefined ? false : readBoolean(merge, USER_HAS_PASSWORD_FIELD),
    unverifiedPasswordAddress:
      merge === undefined
        ? null
        : readStringOrNull(merge, USER_UNVERIFIED_PASSWORD_ADDRESS_FIELD),
  }
}

/**
 * Where the users list reads and writes its address (HIL-945). The lapsed filter
 * lives in the address as an optional tail — `/hilos/users/terms` — so the link
 * from the legal section's root opens the list narrowed, and a reload or a
 * copied link opens what is on the screen. {@link HilosRouter} is a structural
 * fit; a test passes a fake. Rewriting rather than navigating keeps the page
 * subscribed — the filter narrows the same table.
 */
export interface HilosUsersAddress {
  /** The matched route, whose params carry the lapsed filter when the address names one. */
  readonly currentRoute: ReadonlySignal<PageRouteMatch>
  /**
   * Rewrite the current address without re-subscribing the page.
   *
   * @param pathname The address the current lapsed filter resolves to.
   */
  replacePath(pathname: string): void
}

/**
 * Read the lapsed filter an address names: a legal document key, or `''` for
 * the whole list. An unknown tail reads as no tail rather than as a refusal:
 * the address came from outside, and the filter has no other value to land on.
 *
 * @param params The route params of the users-list route.
 */
export function readHilosUsersAddress(
  params: Record<string, string>,
): HilosLegalDocumentKey | '' {
  const value = params[HILOS_USERS_LAPSED_FILTER]

  return value === 'terms' || value === 'privacy' ? value : ''
}

/**
 * The address of the users list narrowed to the people past a document's
 * deadline, or of the whole list for `''`.
 *
 * @param lapsed The document key, `''` when none.
 */
export function hilosUsersPath(lapsed: string): string {
  return resolveHilosPath(
    HilosPages.USERS,
    lapsed === '' ? {} : { [HILOS_USERS_LAPSED_FILTER]: lapsed },
  )
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
  filters: [
    {
      kind: 'select',
      key: HILOS_USERS_LAPSED_FILTER,
      // One label for both refusal settings: the list page carries no setting,
      // and "past deadline" is true of everybody the filter keeps under either.
      label: 'Past deadline on',
      anyLabel: 'All',
      options: () =>
        (['terms', 'privacy'] as const).map((document) => ({
          value: document,
          label: hilosLegalDocumentLabel(document),
        })),
    },
  ],
}

/**
 * The users-table controller, whose reset returns the WHOLE list. An address
 * carrying a lapsed tail opens the table with that filter as its preset — so
 * the unfiltered first window is never shown — but the filter is one of the
 * bar's own, and resetting back to the preset would leave its badge and its
 * reset control saying what they said before the press.
 */
class HilosUsersController extends TableViewportController<HilosUserRow> {
  override resetFilters(): void {
    this.setFilters(
      Object.fromEntries(
        Object.keys(this.filter.get()).map((key) => [key, null]),
      ),
    )
  }
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
 * The lapsed filter lives in the address too (HIL-945): an address carrying a
 * document tail opens the table with that filter, and whenever the filter moves
 * — by its control or by a reset — the address is rewritten in place to match.
 * Without a navigator there is neither a preset nor a rewrite.
 *
 * @param context The project context (connection, scopes, and user collection).
 * @param address The navigator the lapsed filter is read from and written to, or none.
 */
export function createHilosUsersTable<TUser extends User>(
  context: HilosUsersContext<TUser>,
  address?: HilosUsersAddress,
): HilosUsersTable {
  const entered =
    address === undefined
      ? ''
      : readHilosUsersAddress(address.currentRoute.get().params)
  const controller = new HilosUsersController({
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
    initialFilter:
      entered === '' ? undefined : { [HILOS_USERS_LAPSED_FILTER]: entered },
  })
  const lapsed = computedSignal(() => {
    const value = controller.filter.get()[HILOS_USERS_LAPSED_FILTER]

    return typeof value === 'string' ? value : ''
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
      if (address !== undefined) {
        teardown.push(
          subscribeSignal(lapsed, (value) => {
            if (
              value !== readHilosUsersAddress(address.currentRoute.get().params)
            ) {
              address.replacePath(hilosUsersPath(value))
            }
          }),
        )
      }
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
      unverifiedPasswordAddress:
        identities === undefined
          ? null
          : readStringOrNull(
              identities,
              USER_UNVERIFIED_PASSWORD_ADDRESS_FIELD,
            ),
    }
  })
}

/** The standing of the person a card shows, and its mount lifecycle (HIL-945). */
export interface HilosUserStanding {
  /**
   * The standing, or `null` before the page answered and wherever it cannot be
   * read — a viewer of the admin view mode is sent it hidden.
   */
  readonly standing: ReadonlySignal<HilosAccountStanding | null>
  /** Start taking the standing from the page data and the live frames — call on mount. */
  start(): void
  /** Stop taking it and forget it — call on unmount. */
  dispose(): void
}

/**
 * The one verdict a user card reads its person's standing from (HIL-945): the
 * page's `accountStanding` data, then every `hilos_account_standing_state`
 * frame after it — whichever arrived last. A frame naming another person than
 * the card's row is a late one from the card left behind and is not taken.
 *
 * @param context The project context (the scope stores and the connection the frames ride).
 */
export function createHilosUserStanding(
  context: Pick<HilosUsersContext, 'scopes' | 'connection'>,
): HilosUserStanding {
  const standing = createSignal<HilosAccountStanding | null>(null)
  const detailRows = context.scopes.pageTableSignal(USER_DETAIL_TABLE)
  let stop: (() => void) | null = null

  /**
   * Whether a frame speaks about the person the card shows: the card's row names
   * them once it has arrived, and until then the frame is this card's by address.
   *
   * @param userId The person the frame names.
   */
  function aboutShownPerson(userId: unknown): boolean {
    const row = detailRows.get()[0]

    return row === undefined || Number(row.rowKey) === userId
  }

  return {
    standing,
    start() {
      stop?.()
      const section = context.scopes.pageDataSignal(HILOS_USER_STANDING_SECTION)
      standing.set(readHilosAccountStanding(section.get()))
      const offSection = subscribeSignal(section, (raw) =>
        standing.set(readHilosAccountStanding(raw)),
      )
      const offFrames = context.connection.on(
        'projectSignal',
        (signal: ProjectSignal) => {
          if (signal.type !== SIGNAL_ACCOUNT_STANDING_STATE) {
            return
          }
          const frame = signal.data as z.infer<
            typeof accountStandingStateSchema
          >
          if (aboutShownPerson(frame.userId)) {
            standing.set(readHilosAccountStanding(frame.accountStanding))
          }
        },
      )
      stop = () => {
        offSection()
        offFrames()
      }
    },
    dispose() {
      stop?.()
      stop = null
      standing.set(null)
    },
  }
}

/** The badge a card's header shows for the standing shown (HIL-945). */
export interface HilosStandingBadge {
  /** The badge's word. */
  readonly label: 'Blocked' | 'Frozen' | 'Deletion scheduled'
  /** The Bootstrap color of the badge, the one the shell uses for the same standing. */
  readonly tone: HilosStandingTone
  /** The Bootstrap Icon beside the word. */
  readonly icon: 'bi-slash-circle' | 'bi-snow' | 'bi-trash'
}

/**
 * The badge beside the presence in a card's header: one, for the standing
 * shown, in the color and with the icon the shell uses for it — or `null` for
 * an account with nothing standing against it.
 *
 * @param kind The standing shown.
 */
export function hilosStandingBadge(
  kind: HilosAccountStandingKind,
): HilosStandingBadge | null {
  switch (kind) {
    case 'blocked':
      return {
        label: 'Blocked',
        tone: hilosStandingTone(kind),
        icon: 'bi-slash-circle',
      }
    case 'frozen':
      return { label: 'Frozen', tone: hilosStandingTone(kind), icon: 'bi-snow' }
    case 'deletion_scheduled':
      return {
        label: 'Deletion scheduled',
        tone: hilosStandingTone(kind),
        icon: 'bi-trash',
      }
    default:
      return null
  }
}

/**
 * The rename action surface for the user-detail view. The backend acks a rename
 * through dedicated project signals rather than the framework action_error, so
 * success is observed state-driven in the view (the committed name reaches the
 * name it sent) while a failure is surfaced here from the fail ack. The fail signal has
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

/** English copy for the account-merge password fate choices. */
export const HILOS_ACCOUNT_MERGE_PASSWORD_COPY = {
  legend: 'Both accounts have a password. Which one stays?',
  survivor: 'The survivor password',
  loser: 'The other account password',
  none: 'Neither password; set a new one in Profile',
  otherRemoved: '{address} is not confirmed and will be removed, not moved.',
  survivorRemoved: '{address} is not confirmed and will be removed.',
} as const

/** One password fate choice presented in the account merge window. */
export interface HilosPasswordFateChoice {
  /** The value sent to the merge action. */
  readonly value: HilosPasswordFate
  /** Option label in the radio group. */
  readonly label: string
  /** Explanatory removal notices for unverified addresses that will be removed under this choice. */
  readonly removes: readonly string[]
}

/**
 * Resolves the password fate choices and their removal warnings for the account merge dialog.
 *
 * When both accounts own a password, the merge keeps at most one password. Any password identity
 * that is not kept is removed rather than demoted to a link if its sign-in address is unverified
 * ({@see Identities::demotePasswordToMagicLink()}, HIL-692/HIL-713). The dialog warns about each
 * address that will be removed under each choice.
 *
 * @param survivor The survivor account detail, or null/undefined if not available.
 * @param loser The candidate account to be merged, or null/undefined if not available.
 * @returns The fate choices in order: survivor, loser, none.
 */
export function hilosPasswordFateChoices(
  survivor:
    | Pick<HilosUserDetailRow, 'unverifiedPasswordAddress'>
    | null
    | undefined,
  loser:
    | Pick<HilosMergeCandidateRow, 'unverifiedPasswordAddress'>
    | null
    | undefined,
): readonly HilosPasswordFateChoice[] {
  const loserAddress = loser?.unverifiedPasswordAddress
  const survivorAddress = survivor?.unverifiedPasswordAddress
  const loserNotice =
    typeof loserAddress === 'string' && loserAddress.length > 0
      ? HILOS_ACCOUNT_MERGE_PASSWORD_COPY.otherRemoved.replace(
          '{address}',
          loserAddress,
        )
      : null
  const survivorNotice =
    typeof survivorAddress === 'string' && survivorAddress.length > 0
      ? HILOS_ACCOUNT_MERGE_PASSWORD_COPY.survivorRemoved.replace(
          '{address}',
          survivorAddress,
        )
      : null

  return [
    {
      value: 'survivor',
      label: HILOS_ACCOUNT_MERGE_PASSWORD_COPY.survivor,
      removes: loserNotice ? [loserNotice] : [],
    },
    {
      value: 'loser',
      label: HILOS_ACCOUNT_MERGE_PASSWORD_COPY.loser,
      removes: survivorNotice ? [survivorNotice] : [],
    },
    {
      value: 'none',
      label: HILOS_ACCOUNT_MERGE_PASSWORD_COPY.none,
      removes: [
        ...(loserNotice ? [loserNotice] : []),
        ...(survivorNotice ? [survivorNotice] : []),
      ],
    },
  ]
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
  /** Deletion grace period from the page's first answer, or hidden for a view-mode viewer. */
  readonly graceDays: ReadonlySignal<Hideable<number> | null>
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

      if (isHiddenValue(value)) {
        return value
      }

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
  frozen: 'Frozen',
  frozenNone: 'Not frozen',
  pastDeadline: 'Past deadline',
  remindersOnly: 'reminders only',
  lapsedLine: '{document} — deadline passed {date}',
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

/**
 * The freeze row of the access section (HIL-945): a fact without a control —
 * only the person's own acceptance lifts a freeze, and an administrator has
 * nothing to press. It is drawn between the block row and the deletion row.
 */
export interface HilosUserFrozenRow {
  readonly key: 'frozen'
  readonly title: string
  /** Whether the account is frozen. */
  readonly state: boolean
  /**
   * What the row says under its title: nothing more while frozen (the lapsed
   * lines say it), "Past deadline — reminders only" while a deadline has passed
   * under the reminding setting, "Not frozen" otherwise.
   */
  readonly hint: string | null
  /** One line per document whose deadline has passed: the document and the day. */
  readonly lapsed: readonly string[]
}

/** One account-card section, rendered alike by all three SDKs. */
export interface HilosUserLifecycleSection {
  readonly key: 'rights' | 'access'
  readonly title: string
  readonly rows: readonly HilosUserLifecycleRow[]
}

/**
 * Present the three independent states and the controls the current card offers.
 *
 * The block and the deletion are read from the person's standing when the card
 * has one (HIL-945) — the verdict the header badge shows too — and from the
 * committed row only while it has none, as before the verdict existed.
 *
 * @param detail The committed user row, absent until the table arrives.
 * @param currentUserId The person behind this session.
 * @param graceDays The subscription's grace-period snapshot, or hidden for a viewer.
 * @param now The current local epoch milliseconds, for the remaining days.
 * @param standing The person's standing, or `null` while the card has none.
 */
export function hilosUserLifecycleSections(
  detail: HilosUserDetailRow | undefined,
  currentUserId: number | null,
  graceDays: Hideable<number> | null,
  now: number,
  standing: HilosAccountStanding | null = null,
): readonly HilosUserLifecycleSection[] {
  if (!detail) return []
  const copy = HILOS_USER_LIFECYCLE_COPY
  const own = detail.id === currentUserId
  const blocked = standing?.blocked ?? detail.block
  const deletionEffectiveAt =
    standing === null
      ? detail.deletionEffectiveAt
      : standing.deletionEffectiveAt
  const scheduled = deletionEffectiveAt !== null
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
          state: blocked,
          choice: blocked ? 'unblock' : 'block',
          disabled: own && !blocked,
          reason: own && !blocked ? copy.ownBlockReason : null,
          reasonSpace: copy.ownBlockReason,
        },
        {
          key: 'deletion',
          title: copy.deletionScheduled,
          hint:
            deletionEffectiveAt === null
              ? copy.deletionNone
              : copy.deletionSummary
                  .replace('{date}', formatCalendarDate(deletionEffectiveAt))
                  .replace(
                    '{days}',
                    formatAccountDeletionDays(
                      accountDeletionDaysLeft(deletionEffectiveAt, now),
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
 * The freeze row of a card (HIL-945), or `null` while the card has no standing
 * to read it from — a freeze is computed on the backend and nowhere else, so
 * there is no row flag to fall back to.
 *
 * Frozen: the lapsed documents with the day each deadline passed. Past a
 * deadline under the reminding setting: "Past deadline — reminders only" and the
 * same lines. Otherwise "Not frozen".
 *
 * @param standing The person's standing, or `null` while the card has none.
 */
export function hilosUserFrozenRow(
  standing: HilosAccountStanding | null,
): HilosUserFrozenRow | null {
  if (standing === null) {
    return null
  }
  const copy = HILOS_USER_LIFECYCLE_COPY
  const lapsed = standing.lapsed.map((lapse) =>
    copy.lapsedLine
      .replace('{document}', hilosLegalDocumentLabel(lapse.document))
      .replace('{date}', formatHilosLegalDate(lapse.deadline)),
  )

  return {
    key: 'frozen',
    title: copy.frozen,
    state: standing.frozen,
    hint: standing.frozen
      ? null
      : lapsed.length > 0
        ? `${copy.pastDeadline} — ${copy.remindersOnly}`
        : copy.frozenNone,
    lapsed,
  }
}

/**
 * Freeze the person and the confirmation text at the moment a control is pressed.
 * @param detail The account whose control was pressed.
 * @param choice The requested operation.
 * @param graceDays The grace period named by this page's subscription, or hidden for a viewer.
 */
export function hilosUserLifecyclePrompt(
  detail: HilosUserDetailRow,
  choice: HilosUserLifecycleChoice,
  graceDays: Hideable<number> | null,
): HilosUserLifecyclePrompt {
  const copy = HILOS_USER_LIFECYCLE_COPY.confirmations[choice]

  return {
    userId: detail.id,
    choice,
    title: `${copy.title} · ${hiddenAsWord(detail.name)}`,
    paragraphs: copy.paragraphs.map((line) =>
      line.replace(
        '{graceDays}',
        graceDays === null
          ? ''
          : isHiddenValue(graceDays)
            ? String(hiddenAsWord(graceDays))
            : formatAccountDeletionDays(graceDays),
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

/**
 * The step-up operation behind each account-card window that takes something
 * away from the person (HIL-1275). Lifting a block and calling a deletion off
 * give back rather than take, so their windows have no operation.
 */
export const HILOS_USER_CARD_STEP_UP_OPERATIONS = {
  merge: 'merge_accounts',
  grant: 'grant_admin',
  revoke: 'revoke_admin',
  block: 'block_account',
  delete: 'delete_other_account',
} as const

/** A window of the account card: one of the six confirmations, or the merge. */
export type HilosUserCardWindow = HilosUserLifecycleChoice | 'merge'

/** The confirmation step of one account-card window (HIL-1275). */
export interface HilosUserCardStepUp {
  /** The step the window draws while it asks; the shared step-up body binds to it. */
  readonly step: HilosStepUpStep
  /**
   * Ask the server whether the window's operation needs a fresh confirmation of
   * the administrator. A viewer of the admin view mode skips the step without
   * asking the server, because the viewer cannot act and opening the step would
   * send a code to their address. A window without an operation answers `skip`
   * and sends nothing; every other window asks, whatever the installation's
   * list says — which windows ask is the list's answer, not the view's.
   *
   * @param window The window being opened.
   */
  open(window: HilosUserCardWindow): Promise<HilosStepUpOpenOutcome>
}

/** The operation a window confirms, or undefined for a window that gives back. */
function cardOperation(window: HilosUserCardWindow): string | undefined {
  switch (window) {
    case 'unblock':
    case 'cancelDeletion':
      return undefined
    default:
      return HILOS_USER_CARD_STEP_UP_OPERATIONS[window]
  }
}

/**
 * The confirmation step an account-card window opens with (HIL-1275): the
 * administrator confirms it is them before an action over another person's
 * account, by the method their own account can prove. A viewer of the admin view
 * mode skips the step without asking the server (HIL-1263): the viewer acts on
 * nothing, and opening the step on a signed-in non-admin would send a code to
 * their unconfirmed address.
 *
 * @param context The project context whose action lifecycle carries the step.
 */
export function createHilosUserCardStepUp(
  context: HilosUsersContext,
): HilosUserCardStepUp {
  const step = createHilosStepUpStep(createHilosStepUpActions(context.actions))

  return {
    step,
    open(window) {
      if (hilosAdminAccess.get() === 'view') {
        return Promise.resolve('skip')
      }
      const operation = cardOperation(window)

      return operation === undefined
        ? Promise.resolve('skip')
        : step.open(operation)
    },
  }
}
