import {
  computedSignal,
  hilosNotificationPreferences,
  hilosPushSubscription,
  profilePushChannel,
  resolveHilosProfileDevices,
  type EntityRef,
} from '@hilos/core/base'

import { scopes } from '../../bootstrap/session.js'
import { PushSubscriptions } from '../../types/PushSubscription.js'

/** Page list key declared by the PMD backend. */
export const PROFILE_DEVICES_LIST = 'profileDevices'

/** Page slot declared by the PMD backend source collection. */
const PUSH_SUBSCRIPTIONS_SLOT = 'pushSubscriptions'

function references(slot: unknown): readonly EntityRef[] {
  return Array.isArray(slot) ? (slot as EntityRef[]) : []
}

const deviceItems = scopes.pageListSignal(PROFILE_DEVICES_LIST)

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

/** Enabled push channel and its public key, or null when push is unavailable. */
export const profileDevicePushChannel = computedSignal(() =>
  profilePushChannel(hilosNotificationPreferences.channels.get()),
)
