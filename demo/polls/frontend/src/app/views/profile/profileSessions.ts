import {
  computedSignal,
  createHilosProfileSessionActions,
  endableProfileSessionCount,
  PROFILE_SESSION_TAB_ACCEPT_KEY_FIELD,
  PROFILE_SESSION_TAB_SESSION_ID_FIELD,
  readNumberOrNull,
  readString,
  resolveHilosProfileSessions,
  type EntityRef,
  type HilosProfileSelfConnection,
} from '@hilos/core'

import { actions } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'
import { Sessions } from '../../types/session.js'

/** Page list keys declared by the polls backend. */
export const PROFILE_SESSIONS_LIST = 'profileSessions'

/** Page slots declared by the polls backend source collections. */
const SESSIONS_SLOT = 'sessions'
const CONNECTIONS_SLOT = 'connections'
const SELF_CONNECTION_DATA = 'selfConnection'

function records(slot: unknown): readonly Readonly<Record<string, unknown>>[] {
  return Array.isArray(slot)
    ? slot.filter(
        (entry): entry is Readonly<Record<string, unknown>> =>
          typeof entry === 'object' && entry !== null && !Array.isArray(entry),
      )
    : []
}

function references(slot: unknown): readonly EntityRef[] {
  return Array.isArray(slot) ? (slot as EntityRef[]) : []
}

function selfConnection(raw: unknown): HilosProfileSelfConnection | undefined {
  if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) {
    return undefined
  }
  const fields = raw as Record<string, unknown>
  const acceptKey = readString(fields, PROFILE_SESSION_TAB_ACCEPT_KEY_FIELD)

  return acceptKey === ''
    ? undefined
    : {
        acceptKey,
        sessionId: readNumberOrNull(
          fields,
          PROFILE_SESSION_TAB_SESSION_ID_FIELD,
        ),
      }
}

const sessionItems = scopes.pageListSignal(PROFILE_SESSIONS_LIST)
const selfConnectionData = scopes.pageDataSignal(SELF_CONNECTION_DATA)

/** Current page's session list, resolved through the normalized session entities. */
export const profileSessions = computedSignal(() =>
  resolveHilosProfileSessions(
    sessionItems.get().flatMap((item) => {
      const connections = records(item.slots[CONNECTIONS_SLOT])

      return references(item.slots[SESSIONS_SLOT]).flatMap((reference) => {
        const session = Sessions.signal(reference).get()

        return session === undefined
          ? []
          : [{ session: { ...session }, connections }]
      })
    }),
    selfConnection(selfConnectionData.get()),
  ),
)

/** Number shown beside the profile's Sessions section link. */
export const profileSessionCount = computedSignal(
  () => profileSessions.get().length,
)

/** Number of sessions the bulk action can currently end. */
export const endableProfileSessions = computedSignal(() =>
  endableProfileSessionCount(profileSessions.get()),
)

/** Tracked actions shared by the profile session page and SDK block. */
export const profileSessionActions = createHilosProfileSessionActions({
  actions,
})
