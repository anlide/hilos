// The sessions of the person a card shows: the `userSessions` and
// `userActingSessions` lists the card's pages receive with their one page
// answer, read out of the page scope's normalized store, and the two commands
// an administrator ends them with. The lists, their slots and the two action
// names belong to this module; the session and tab field keys, the row
// view-model and its resolver are the profile's (profile/profileSessions.ts),
// so the card and the profile draw one row and a project writes no second
// resolver.
//
// The card differs from the profile in its order, names and access. Sessions
// are ordered by activity, newest first, and no session comes first for being
// this browser's: an administrator's page carries no connection of its own to
// compare with. A session in which an administrator acts as the person names
// that administrator, from the person row the list delivers beside it — never
// from the browser's own session. An outgoing session names the person acted
// as, and the ordinary list wins when both lists carry the same session id.
// A viewer of the admin view mode is shown the list hidden as a whole, by the
// one access verdict every admin surface reads, so a list a wider scope left
// behind is not drawn to them either.
//
// Nothing here removes a session from the list: ending one is answered by the
// server, and the list follows the server's own update.

import { type ActionHandle } from '../../connection/actionLifecycle.js'
import {
  PROFILE_SESSION_BROWSER_TIME_ZONE_FIELD,
  PROFILE_SESSION_CREATED_AT_FIELD,
  PROFILE_SESSION_DEVICE_NAME_FIELD,
  PROFILE_SESSION_EXPIRES_AT_FIELD,
  PROFILE_SESSION_ID_FIELD,
  PROFILE_SESSION_IMPERSONATOR_USER_ID_FIELD,
  PROFILE_SESSION_LAST_SEEN_AT_FIELD,
  PROFILE_SESSION_TAB_ACCEPT_KEY_FIELD,
  PROFILE_SESSION_TAB_CONNECTED_AT_FIELD,
  PROFILE_SESSION_TAB_SESSION_ID_FIELD,
  PROFILE_SESSION_USER_ID_FIELD,
  resolveHilosProfileSessions,
  type HilosProfileSession,
  type HilosProfileSessionSource,
  type HilosProfileSessionTab,
} from '../../profile/profileSessions.js'
import { hilosAdminAccess } from '../../session/adminAccess.js'
import { type User } from '../../state/entity.js'
import { type EntityRef } from '../../state/EntityStore.js'
import { readNumber, readNumberOrNull } from '../../state/fieldReaders.js'
import {
  HIDDEN_VALUE,
  type Hideable,
  isHiddenValue,
} from '../../state/hiddenValue.js'
import { type ListItem } from '../../state/ListStore.js'
import { computedSignal, type ReadonlySignal } from '../../state/signal.js'
import { USER_NAME_FIELD, type HilosUsersContext } from './hilosUsers.js'

/** Page list key of the person's sessions, as the framework list declares it. */
const USER_SESSIONS_LIST = 'userSessions'
/** Page list key of the sessions in which the person acts as somebody else. */
const USER_ACTING_SESSIONS_LIST = 'userActingSessions'
/** List slot of the session row each item is anchored on. */
const USER_SESSIONS_SESSION_SLOT = 'sessions'
/** List slot of the live tabs open in the item's session. */
const USER_SESSIONS_CONNECTIONS_SLOT = 'connections'
/** List slot of the acting administrator or the person acted as, by list. */
const USER_SESSIONS_USER_SLOT = 'users'
/** Page data key of whether the card has its real Sessions tab and reads the list. */
const USER_SESSIONS_ENABLED_KEY = 'userSessionsEnabled'

/** Client→server action ending one session of the person. */
const HILOS_USER_SESSION_END_ACTION = 'hilos_user_session_end'
/** Client→server action ending every current session of the person. */
const HILOS_USER_SESSIONS_END_ALL_ACTION = 'hilos_user_sessions_end_all'

/** The session fields the row reads, each of which a viewer may be sent hidden. */
const SESSION_FIELDS = [
  PROFILE_SESSION_ID_FIELD,
  PROFILE_SESSION_USER_ID_FIELD,
  PROFILE_SESSION_DEVICE_NAME_FIELD,
  PROFILE_SESSION_BROWSER_TIME_ZONE_FIELD,
  PROFILE_SESSION_CREATED_AT_FIELD,
  PROFILE_SESSION_LAST_SEEN_AT_FIELD,
  PROFILE_SESSION_EXPIRES_AT_FIELD,
  PROFILE_SESSION_IMPERSONATOR_USER_ID_FIELD,
] as const

/** The tab fields the row reads, each of which a viewer may be sent hidden. */
const TAB_FIELDS = [
  PROFILE_SESSION_TAB_ACCEPT_KEY_FIELD,
  PROFILE_SESSION_TAB_SESSION_ID_FIELD,
  PROFILE_SESSION_TAB_CONNECTED_AT_FIELD,
] as const

/** One session of the person on their card: the profile's row and who acts in it. */
export interface HilosUserSession extends HilosProfileSession {
  /**
   * The administrator acting as the person in this session: their name, `#<id>`
   * when their person row did not come with the list (the account is gone),
   * {@link HIDDEN_VALUE} when the name arrived hidden, or null for a session
   * nobody acts in.
   */
  readonly impersonatorName: Hideable<string> | null
  /**
   * The person acted as in an outgoing session: their name, `#<id>` when their
   * person row did not come with the list, {@link HIDDEN_VALUE} when the name
   * arrived hidden, or null for a session from the ordinary list.
   */
  readonly actingAsName: Hideable<string> | null
}

/** The person's sessions as the card reads them, and the two commands that end them. */
export interface HilosUserSessions {
  /**
   * Both lists merged by session id, with the ordinary row winning a duplicate,
   * by last activity newest first and, at equal activity, the newer session
   * first; the tabs of each by connection newest first. Hidden as a whole for
   * a viewer of the admin view mode, and wherever a field the row is built from
   * arrived hidden; empty between page answers.
   */
  readonly sessions: ReadonlySignal<Hideable<readonly HilosUserSession[]>>
  /**
   * How many sessions the person has, including outgoing sessions — one per
   * sign-in, whatever its tabs.
   * `undefined` when the card does not read the list (the page did not say it
   * does), 0 for a read list with no session, {@link HIDDEN_VALUE} where the
   * sessions are hidden.
   */
  readonly count: ReadonlySignal<Hideable<number> | undefined>
  /**
   * End one session of the person as a tracked action.
   *
   * @param userId The person the session must still belong to.
   * @param sessionId The session to end.
   */
  endSession(userId: number, sessionId: number): ActionHandle
  /**
   * End every current session of the person as a tracked action — the ones
   * signed in when the server carries it out, this browser's own included.
   *
   * @param userId The person whose sessions end.
   */
  endAllSessions(userId: number): ActionHandle
}

/**
 * The reference a list slot holds, or undefined for a slot that holds none.
 *
 * @param slot The normalized slot value.
 */
function referenceSlot(slot: unknown): EntityRef | undefined {
  if (typeof slot !== 'object' || slot === null || Array.isArray(slot)) {
    return undefined
  }
  const { type, id } = slot as Partial<EntityRef>

  return typeof type === 'string' &&
    (typeof id === 'string' || typeof id === 'number')
    ? { type, id }
    : undefined
}

/**
 * The tab records a list slot holds; anything else in it is not a tab.
 *
 * @param slot The normalized slot value.
 */
function tabRecords(
  slot: unknown,
): readonly Readonly<Record<string, unknown>>[] {
  return Array.isArray(slot)
    ? slot.filter(
        (entry): entry is Readonly<Record<string, unknown>> =>
          typeof entry === 'object' && entry !== null && !Array.isArray(entry),
      )
    : []
}

/**
 * Whether any of the named fields of a record arrived hidden.
 *
 * @param record The raw record.
 * @param fields The field names the row reads from it.
 */
function anyHidden(
  record: Readonly<Record<string, unknown>>,
  fields: readonly string[],
): boolean {
  return fields.some((field) => isHiddenValue(record[field]))
}

/**
 * Order sessions by last activity, newest first, then by id, the newer first.
 *
 * @param left One session.
 * @param right The other session.
 */
function bySessionActivity(
  left: HilosProfileSession,
  right: HilosProfileSession,
): number {
  return right.lastSeenAt - left.lastSeenAt || right.id - left.id
}

/**
 * Order tabs by connection, newest first, then by connection key.
 *
 * @param left One tab.
 * @param right The other tab.
 */
function byTabConnection(
  left: HilosProfileSessionTab,
  right: HilosProfileSessionTab,
): number {
  if (left.connectedAt !== right.connectedAt) {
    return right.connectedAt - left.connectedAt
  }

  return left.acceptKey < right.acceptKey
    ? -1
    : left.acceptKey > right.acceptKey
      ? 1
      : 0
}

/**
 * The person's sessions on their card and the two commands that end them
 * (HIL-1662). The list is read from the page scope, so a page answer, every
 * update after it and the loss of the page all reach the sessions without a
 * request of the card's own; the session rows are resolved by the type their
 * reference carries, whatever entity type the project maps the slot to.
 *
 * @param context The project context (the scope stores, the action lifecycle, the user collection).
 */
export function createHilosUserSessions<TUser extends User>(
  context: HilosUsersContext<TUser>,
): HilosUserSessions {
  const items = context.scopes.pageListSignal(USER_SESSIONS_LIST)
  const actingItems = context.scopes.pageListSignal(USER_ACTING_SESSIONS_LIST)
  const enabled = context.scopes.pageDataSignal(USER_SESSIONS_ENABLED_KEY)

  /**
   * The name read from the person row the list item carries.
   *
   * @param item The list item of the session.
   * @param userId The person the session names, or null.
   */
  function userName(
    item: ListItem,
    userId: number | null,
  ): Hideable<string> | null {
    if (userId === null) {
      return null
    }
    const ref = referenceSlot(item.slots[USER_SESSIONS_USER_SLOT])
    const user = ref ? context.users.signal(ref).get() : undefined
    if (ref === undefined || user === undefined) {
      return `#${userId}`
    }

    return context.users.hidden(ref, USER_NAME_FIELD).get()
      ? HIDDEN_VALUE
      : user.name
  }

  const sessions = computedSignal((): Hideable<readonly HilosUserSession[]> => {
    if (hilosAdminAccess.get() === 'view') {
      return HIDDEN_VALUE
    }
    const sources: HilosProfileSessionSource[] = []
    const names = new Map<
      number,
      Pick<HilosUserSession, 'impersonatorName' | 'actingAsName'>
    >()
    for (const [list, actingAs] of [
      [items.get(), false],
      [actingItems.get(), true],
    ] as const) {
      for (const item of list) {
        const ref = referenceSlot(item.slots[USER_SESSIONS_SESSION_SLOT])
        const session = ref
          ? context.scopes.entitySignal(ref).get()?.fields
          : undefined
        if (session === undefined) {
          continue
        }
        if (anyHidden(session, SESSION_FIELDS)) {
          return HIDDEN_VALUE
        }
        const id = readNumber(session, PROFILE_SESSION_ID_FIELD)
        if (names.has(id)) {
          continue
        }
        const tabs = item.slots[USER_SESSIONS_CONNECTIONS_SLOT]
        const connections = tabRecords(tabs)
        if (
          isHiddenValue(tabs) ||
          connections.some((tab) => anyHidden(tab, TAB_FIELDS))
        ) {
          return HIDDEN_VALUE
        }
        sources.push({ session, connections })
        names.set(id, {
          impersonatorName: actingAs
            ? null
            : userName(
                item,
                readNumberOrNull(
                  session,
                  PROFILE_SESSION_IMPERSONATOR_USER_ID_FIELD,
                ),
              ),
          actingAsName: actingAs
            ? userName(item, readNumber(session, PROFILE_SESSION_USER_ID_FIELD))
            : null,
        })
      }
    }

    return resolveHilosProfileSessions(sources)
      .map((session) => ({
        ...session,
        tabs: [...session.tabs].sort(byTabConnection),
        impersonatorName: names.get(session.id)?.impersonatorName ?? null,
        actingAsName: names.get(session.id)?.actingAsName ?? null,
      }))
      .sort(bySessionActivity)
  })

  return {
    sessions,
    count: computedSignal(() => {
      if (enabled.get() !== true) {
        return undefined
      }
      const list = sessions.get()

      return isHiddenValue(list) ? HIDDEN_VALUE : list.length
    }),
    endSession(userId, sessionId) {
      return context.actions.dispatch(HILOS_USER_SESSION_END_ACTION, {
        userId,
        sessionId,
      })
    },
    endAllSessions(userId) {
      return context.actions.dispatch(HILOS_USER_SESSIONS_END_ALL_ACTION, {
        userId,
      })
    },
  }
}
