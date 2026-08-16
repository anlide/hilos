// The crypto sidecar of a passkey identity — one row of the framework-owned
// `hilos_passkey_credential` table, projected read-only. A passkey is two rows:
// the `identity` anchor and this one, which carries the only parts a person can
// read — the device the key was enrolled on and when. The profile page joins the
// two by `identityId` to name a key instead of printing its credential id
// (HIL-418); the public key, user handle and counter are never fields here, so
// they can never reach the client.
import {
  entityCollection,
  readNumber,
  readString,
  readStringOrNull,
  type EntityCollection,
} from '@hilos/core'

import { scopes } from '../bootstrap/session'

/** The canonical entity type — keep in sync with the backend `passkeyCredentials` source. */
export const PASSKEY_CREDENTIAL_TYPE = 'passkeyCredential'

/** A registered passkey: which identity it belongs to, its device name and its age. */
export interface PasskeyCredential {
  /** Credential row id. */
  readonly id: number
  /** Owning user id. */
  readonly userId: number
  /** The `identity` anchor row this credential belongs to. */
  readonly identityId: number
  /** The device the key was enrolled on, or null when the browser was unrecognized. */
  readonly label: string | null
  /** When the key was registered, as an SQL datetime. */
  readonly createdAt: string
}

/**
 * Project a committed credential's raw fields into the typed entity.
 *
 * @param fields The credential entity's committed fields.
 */
export function passkeyCredentialFromFields(
  fields: Readonly<Record<string, unknown>>,
): PasskeyCredential {
  return {
    id: readNumber(fields, 'id'),
    userId: readNumber(fields, 'userId'),
    identityId: readNumber(fields, 'identityId'),
    label: readStringOrNull(fields, 'label'),
    createdAt: readString(fields, 'createdAt'),
  }
}

/** The credential collection: typed reference resolution for the `passkeyCredential` entity type. */
export const PasskeyCredentials: EntityCollection<PasskeyCredential> =
  entityCollection(scopes, PASSKEY_CREDENTIAL_TYPE, passkeyCredentialFromFields)
