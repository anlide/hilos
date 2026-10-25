import {
  entityCollection,
  PROFILE_DEVICE_CREATED_AT_FIELD,
  PROFILE_DEVICE_ENDPOINT_HASH_FIELD,
  PROFILE_DEVICE_GONE_AT_FIELD,
  PROFILE_DEVICE_ID_FIELD,
  PROFILE_DEVICE_NAME_FIELD,
  readNumber,
  readNumberOrNull,
  readString,
  readStringOrNull,
  type EntityCollection,
} from '@hilos/core/base'

import { scopes } from '../bootstrap/session.js'

/** The canonical entity type for a durable push subscription. */
export const PUSH_SUBSCRIPTION_TYPE = 'pushSubscription'

/** Browser-safe durable push-subscription fields. */
export interface PushSubscription {
  readonly id: number
  readonly deviceName: string | null
  readonly endpointHash: string
  readonly createdAt: number
  readonly goneAt: number | null
}

/**
 * Project committed push-subscription fields without its endpoint or keys.
 *
 * @param fields Browser-safe push-subscription fields.
 */
export function pushSubscriptionFromFields(
  fields: Readonly<Record<string, unknown>>,
): PushSubscription {
  return {
    id: readNumber(fields, PROFILE_DEVICE_ID_FIELD),
    deviceName: readStringOrNull(fields, PROFILE_DEVICE_NAME_FIELD),
    endpointHash: readString(fields, PROFILE_DEVICE_ENDPOINT_HASH_FIELD),
    createdAt: readNumber(fields, PROFILE_DEVICE_CREATED_AT_FIELD),
    goneAt: readNumberOrNull(fields, PROFILE_DEVICE_GONE_AT_FIELD),
  }
}

/** Durable push-subscription entity collection for the profile devices list. */
export const PushSubscriptions: EntityCollection<PushSubscription> =
  entityCollection(scopes, PUSH_SUBSCRIPTION_TYPE, pushSubscriptionFromFields)
