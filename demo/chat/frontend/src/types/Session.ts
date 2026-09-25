import {
  entityCollection,
  PROFILE_SESSION_CREATED_AT_FIELD,
  PROFILE_SESSION_DEVICE_NAME_FIELD,
  PROFILE_SESSION_EXPIRES_AT_FIELD,
  PROFILE_SESSION_ID_FIELD,
  PROFILE_SESSION_IMPERSONATOR_USER_ID_FIELD,
  PROFILE_SESSION_LAST_SEEN_AT_FIELD,
  PROFILE_SESSION_USER_ID_FIELD,
  readNumber,
  readNumberOrNull,
  readString,
  readStringOrNull,
  type EntityCollection,
} from '@hilos/core'

import { scopes } from '../bootstrap/session.js'

/** The canonical entity type for a durable browser session. */
export const SESSION_TYPE = 'session'

/** Browser-safe durable session fields projected to profile lists. */
export interface Session {
  readonly id: number
  readonly userId: number
  readonly deviceName: string | null
  readonly createdAt: string
  readonly lastSeenAt: string
  readonly expiresAt: string | null
  readonly impersonatorUserId: number | null
}

/**
 * Project committed session fields without the secret token.
 *
 * @param fields Browser-safe session fields.
 */
export function sessionFromFields(
  fields: Readonly<Record<string, unknown>>,
): Session {
  return {
    id: readNumber(fields, PROFILE_SESSION_ID_FIELD),
    userId: readNumber(fields, PROFILE_SESSION_USER_ID_FIELD),
    deviceName: readStringOrNull(fields, PROFILE_SESSION_DEVICE_NAME_FIELD),
    createdAt: readString(fields, PROFILE_SESSION_CREATED_AT_FIELD),
    lastSeenAt: readString(fields, PROFILE_SESSION_LAST_SEEN_AT_FIELD),
    expiresAt: readStringOrNull(fields, PROFILE_SESSION_EXPIRES_AT_FIELD),
    impersonatorUserId: readNumberOrNull(
      fields,
      PROFILE_SESSION_IMPERSONATOR_USER_ID_FIELD,
    ),
  }
}

/** Durable session entity collection for the profile lists. */
export const Sessions: EntityCollection<Session> = entityCollection(
  scopes,
  SESSION_TYPE,
  sessionFromFields,
)
