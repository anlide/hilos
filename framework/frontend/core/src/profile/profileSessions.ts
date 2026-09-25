import { type ActionHandle } from '../connection/actionLifecycle.js'
import { type ActionLifecycle } from '../connection/actionLifecycle.js'
import {
  readNumber,
  readNumberOrNull,
  readString,
  readStringOrNull,
} from '../state/fieldReaders.js'

/** Client→server action ending one other browser session. */
export const PROFILE_SESSION_END_ACTION = 'hilos_session_end'

/** Client→server action ending every other non-impersonated browser session. */
export const PROFILE_SESSIONS_END_OTHERS_ACTION = 'hilos_sessions_end_others'

/** Row payload key of the durable session id. */
export const PROFILE_SESSION_ID_FIELD = 'id'
/** Row payload key of the user who owns the durable session. */
export const PROFILE_SESSION_USER_ID_FIELD = 'userId'
/** Row payload key of the stored device label. */
export const PROFILE_SESSION_DEVICE_NAME_FIELD = 'deviceName'
/** Row payload key of the session creation instant. */
export const PROFILE_SESSION_CREATED_AT_FIELD = 'createdAt'
/** Row payload key of the session's last activity instant. */
export const PROFILE_SESSION_LAST_SEEN_AT_FIELD = 'lastSeenAt'
/** Row payload key of the session expiry instant. */
export const PROFILE_SESSION_EXPIRES_AT_FIELD = 'expiresAt'
/** Row payload key of the administrator working through the session. */
export const PROFILE_SESSION_IMPERSONATOR_USER_ID_FIELD = 'impersonatorUserId'
/** Row payload key of a live tab's connection key. */
export const PROFILE_SESSION_TAB_ACCEPT_KEY_FIELD = 'acceptKey'
/** Row payload key of the durable session a live tab belongs to. */
export const PROFILE_SESSION_TAB_SESSION_ID_FIELD = 'sessionId'
/** Row payload key of the instant a live tab connected. */
export const PROFILE_SESSION_TAB_CONNECTED_AT_FIELD = 'connectedAt'

/** The subscriber's own connection and durable session. */
export interface HilosProfileSelfConnection {
  readonly acceptKey: string
  readonly sessionId: number | null
}

/** One live tab nested under a durable session. */
export interface HilosProfileSessionTab {
  readonly acceptKey: string
  readonly connectedAt: number
  readonly current: boolean
}

/** One durable browser sign-in and the tabs currently using it. */
export interface HilosProfileSession {
  readonly id: number
  readonly deviceName: string | null
  readonly createdAt: string
  readonly lastSeenAt: string
  readonly expiresAt: string | null
  readonly current: boolean
  readonly impersonated: boolean
  readonly tabs: readonly HilosProfileSessionTab[]
}

/** Raw durable session plus the live connection rows joined to its owner. */
export interface HilosProfileSessionSource {
  readonly session: Readonly<Record<string, unknown>>
  readonly connections: readonly Readonly<Record<string, unknown>>[]
}

/** The action lifecycle the profile session controls dispatch through. */
export interface HilosProfileSessionActionContext {
  readonly actions: ActionLifecycle
}

/** Commands exposed to the three profile-session views. */
export interface HilosProfileSessionActions {
  /** End one session by its durable id. */
  endSession(sessionId: number): ActionHandle
  /** End all other non-impersonated sessions. */
  endOtherSessions(): ActionHandle
}

/**
 * Resolve durable session rows into the shared profile view-model.
 *
 * @param sources Durable sessions and the owner's live connection rows.
 * @param selfConnection The subscriber's own connection, when the mounting surface has one.
 */
export function resolveHilosProfileSessions(
  sources: readonly HilosProfileSessionSource[],
  selfConnection?: HilosProfileSelfConnection,
): readonly HilosProfileSession[] {
  return sources
    .map(({ session, connections }) => {
      const id = readNumber(session, PROFILE_SESSION_ID_FIELD)
      const tabs = connections
        .filter(
          (connection) =>
            readNumberOrNull(
              connection,
              PROFILE_SESSION_TAB_SESSION_ID_FIELD,
            ) === id,
        )
        .map((connection) => {
          const acceptKey = readString(
            connection,
            PROFILE_SESSION_TAB_ACCEPT_KEY_FIELD,
          )

          return {
            acceptKey,
            connectedAt: readNumber(
              connection,
              PROFILE_SESSION_TAB_CONNECTED_AT_FIELD,
            ),
            current: selfConnection?.acceptKey === acceptKey,
          }
        })
        .sort((left, right) => right.connectedAt - left.connectedAt)

      return {
        id,
        deviceName: readStringOrNull(
          session,
          PROFILE_SESSION_DEVICE_NAME_FIELD,
        ),
        createdAt: readString(session, PROFILE_SESSION_CREATED_AT_FIELD),
        lastSeenAt: readString(session, PROFILE_SESSION_LAST_SEEN_AT_FIELD),
        expiresAt: readStringOrNull(session, PROFILE_SESSION_EXPIRES_AT_FIELD),
        current: selfConnection?.sessionId === id,
        impersonated:
          readNumberOrNull(
            session,
            PROFILE_SESSION_IMPERSONATOR_USER_ID_FIELD,
          ) !== null,
        tabs,
      }
    })
    .sort((left, right) => {
      if (left.current !== right.current) {
        return left.current ? -1 : 1
      }

      return right.lastSeenAt.localeCompare(left.lastSeenAt)
    })
}

/**
 * Count the other sessions the bulk action may end.
 *
 * @param sessions Current session view-models.
 */
export function endableProfileSessionCount(
  sessions: readonly HilosProfileSession[],
): number {
  return sessions.filter((session) => !session.current && !session.impersonated)
    .length
}

/**
 * Build the tracked profile-session commands over one connection lifecycle.
 *
 * @param context The action lifecycle to dispatch through.
 */
export function createHilosProfileSessionActions(
  context: HilosProfileSessionActionContext,
): HilosProfileSessionActions {
  return {
    endSession(sessionId) {
      return context.actions.dispatch(PROFILE_SESSION_END_ACTION, { sessionId })
    },
    endOtherSessions() {
      return context.actions.dispatch(PROFILE_SESSIONS_END_OTHERS_ACTION, {})
    },
  }
}

/**
 * Format a backend datetime in the reader's locale, preserving an unreadable value.
 *
 * @param value SQL/ISO datetime, local epoch-ms moment, or null when no instant exists.
 */
export function formatProfileDateTime(value: string | number | null): string {
  if (value === null) {
    return 'never'
  }
  const parsed = new Date(value)

  return Number.isNaN(parsed.getTime())
    ? String(value)
    : parsed.toLocaleString()
}
