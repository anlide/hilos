import { type ActionHandle } from '../connection/actionLifecycle.js'
import { type ActionLifecycle } from '../connection/actionLifecycle.js'
import { type HilosNotificationChannelState } from '../notifications/notificationPreferences.js'
import {
  readNumber,
  readNumberOrNull,
  readString,
  readStringOrNull,
} from '../state/fieldReaders.js'

/** Client→server action removing one owned push subscription. */
export const PROFILE_DEVICE_REMOVE_ACTION = 'push_remove'

/** Row payload key of the durable push-subscription id. */
export const PROFILE_DEVICE_ID_FIELD = 'id'
/** Row payload key of the stored device label. */
export const PROFILE_DEVICE_NAME_FIELD = 'deviceName'
/** Row payload key of the endpoint fingerprint. */
export const PROFILE_DEVICE_ENDPOINT_HASH_FIELD = 'endpointHash'
/** Row payload key of the subscription creation instant. */
export const PROFILE_DEVICE_CREATED_AT_FIELD = 'createdAt'
/** Row payload key of the instant the endpoint was found gone. */
export const PROFILE_DEVICE_GONE_AT_FIELD = 'goneAt'

/** One push destination shown on the profile devices page. */
export interface HilosProfileDevice {
  readonly id: number
  readonly deviceName: string | null
  readonly createdAt: number
  readonly expired: boolean
  readonly current: boolean
}

/** Approximate device kind for visual presentation based on the platform label. */
export type HilosProfileDeviceKind = 'phone' | 'tablet' | 'laptop' | 'display'

/**
 * Approximate device kind for visual presentation based on the stored platform label.
 *
 * This is a presentational hint derived from the server's platform label, not an
 * authoritative hardware classification. It contains no visual or CSS styling.
 *
 * @param deviceName Stored browser/platform label, or null.
 */
export function hilosProfileDeviceKind(
  deviceName: string | null,
): HilosProfileDeviceKind {
  if (deviceName === null) {
    return 'display'
  }

  const normalized = deviceName.trim().toLowerCase()
  if (normalized.endsWith(' on iphone') || normalized.endsWith(' on android')) {
    return 'phone'
  }
  if (normalized.endsWith(' on ipad')) {
    return 'tablet'
  }
  if (normalized.endsWith(' on macos') || normalized.endsWith(' on chromeos')) {
    return 'laptop'
  }
  if (normalized.endsWith(' on windows') || normalized.endsWith(' on linux')) {
    return 'display'
  }

  return 'display'
}

/** The globally enabled push channel a local device toggle needs. */
export interface HilosProfilePushChannel {
  readonly channel: string
  readonly label: string
  readonly vapidPublicKey: string
}

/** The action lifecycle the profile device controls dispatch through. */
export interface HilosProfileDeviceActionContext {
  readonly actions: ActionLifecycle
}

/** Commands exposed to the three profile-device views. */
export interface HilosProfileDeviceActions {
  /** Remove one owned push destination by its durable id. */
  removeDevice(subscriptionId: number): ActionHandle
}

/**
 * Resolve push-subscription rows into the shared device view-model.
 *
 * @param rows Durable push-subscription fields.
 * @param ownEndpointHash SHA-256 fingerprint of this browser's endpoint, or null.
 */
export function resolveHilosProfileDevices(
  rows: readonly Readonly<Record<string, unknown>>[],
  ownEndpointHash: string | null,
): readonly HilosProfileDevice[] {
  return rows.map((row) => ({
    id: readNumber(row, PROFILE_DEVICE_ID_FIELD),
    deviceName: readStringOrNull(row, PROFILE_DEVICE_NAME_FIELD),
    createdAt: readNumber(row, PROFILE_DEVICE_CREATED_AT_FIELD),
    expired: readNumberOrNull(row, PROFILE_DEVICE_GONE_AT_FIELD) !== null,
    current:
      ownEndpointHash !== null &&
      readString(row, PROFILE_DEVICE_ENDPOINT_HASH_FIELD) === ownEndpointHash,
  }))
}

/**
 * Find the enabled push channel row and narrow its public browser config.
 *
 * @param channels Notification-preference channel rows from the shared store.
 */
export function profilePushChannel(
  channels: readonly HilosNotificationChannelState[],
): HilosProfilePushChannel | null {
  for (const row of channels) {
    const vapidPublicKey = row.config?.['vapid_public']
    if (vapidPublicKey !== undefined) {
      return { channel: row.channel, label: row.label, vapidPublicKey }
    }
  }

  return null
}

/**
 * Build the tracked profile-device commands over one connection lifecycle.
 *
 * @param context The action lifecycle to dispatch through.
 */
export function createHilosProfileDeviceActions(
  context: HilosProfileDeviceActionContext,
): HilosProfileDeviceActions {
  return {
    removeDevice(subscriptionId) {
      return context.actions.dispatch(PROFILE_DEVICE_REMOVE_ACTION, {
        subscriptionId,
      })
    },
  }
}
