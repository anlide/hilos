import {
  computedSignal,
  createHilosProfileDeviceActions,
  createHilosProfileSessionActions,
  endableProfileSessionCount,
  hilosNotificationPreferences,
  hilosPushSubscription,
  profilePushChannel,
  PROFILE_SESSION_TAB_ACCEPT_KEY_FIELD,
  PROFILE_SESSION_TAB_SESSION_ID_FIELD,
  readNumberOrNull,
  readString,
  resolveHilosProfileDevices,
  resolveHilosProfileSessions,
  type EntityRef,
  type HilosProfileSelfConnection,
} from '@hilos/core'

import { actions } from '../bootstrap/connection.js'
import { scopes } from '../bootstrap/session.js'
import { PushSubscriptions, Sessions } from '../types/index.js'

/** Page list keys declared by the chat backend. */
export const PROFILE_SESSIONS_LIST = 'profileSessions'
export const PROFILE_DEVICES_LIST = 'profileDevices'

/** Page slots declared by the chat backend source collections. */
const SESSIONS_SLOT = 'sessions'
const CONNECTIONS_SLOT = 'connections'
const PUSH_SUBSCRIPTIONS_SLOT = 'pushSubscriptions'
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
const deviceItems = scopes.pageListSignal(PROFILE_DEVICES_LIST)
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

/** Current page's push devices, matched to this browser by endpoint fingerprint. */
export const profileDevices = computedSignal(() =>
  resolveHilosProfileDevices(
    deviceItems.get().flatMap((item) =>
      references(item.slots[PUSH_SUBSCRIPTIONS_SLOT]).flatMap((reference) => {
        const subscription = PushSubscriptions.signal(reference).get()

        return subscription === undefined ? [] : [{ ...subscription }]
      }),
    ),
    hilosPushSubscription.endpointHash.get(),
  ),
)

/** Number shown beside the profile's Devices section link. */
export const profileDeviceCount = computedSignal(
  () => profileDevices.get().filter((device) => !device.expired).length,
)

/** Enabled push channel and its public key, or null when push is unavailable. */
export const profileDevicePushChannel = computedSignal(() =>
  profilePushChannel(hilosNotificationPreferences.channels.get()),
)

/** Tracked remove action shared by the profile devices page and SDK block. */
export const profileDeviceActions = createHilosProfileDeviceActions({ actions })
