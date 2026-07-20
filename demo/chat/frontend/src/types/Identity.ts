// A linked login identity of the current user — one row of the framework-owned
// `hilos_identity` table, projected read-only. The profile page's
// `profileIdentities` list delivers these by reference (the DB-source slot
// resolves through this collection); the `secret` hash is never a field here, so
// it can never reach the client. link/unlink management arrives with HIL-377,
// which reuses this same entity.
import {
  entityCollection,
  readBoolean,
  readNumber,
  readString,
  readStringOrNull,
  type EntityCollection,
} from '@hilos/core'

import { scopes } from '../bootstrap/session'

/** The canonical entity type — keep in sync with the backend `identities` source. */
export const IDENTITY_TYPE = 'identity'

/** A linked login identity: how the user authenticates, without any secret. */
export interface Identity {
  /** Identity row id. */
  readonly id: number
  /** Owning user id. */
  readonly userId: number
  /** Identity kind (password, magic_link, sms, oauth, passkey). */
  readonly type: string
  /** The public identifier (email, phone, oauth subject), never masked to its owner. */
  readonly identifier: string
  /** OAuth provider label, or null for non-oauth methods. */
  readonly provider: string | null
  /** Whether this identity has been verified. */
  readonly verified: boolean
}

/**
 * Project a committed identity's raw fields into the typed entity.
 *
 * @param fields The identity entity's committed fields.
 */
export function identityFromFields(
  fields: Readonly<Record<string, unknown>>,
): Identity {
  return {
    id: readNumber(fields, 'id'),
    userId: readNumber(fields, 'userId'),
    type: readString(fields, 'type'),
    identifier: readString(fields, 'identifier'),
    provider: readStringOrNull(fields, 'provider'),
    verified: readBoolean(fields, 'verified'),
  }
}

/** The identity collection: typed reference resolution for the `identity` entity type. */
export const Identities: EntityCollection<Identity> = entityCollection(
  scopes,
  IDENTITY_TYPE,
  identityFromFields,
)
