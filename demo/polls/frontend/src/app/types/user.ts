// The poll user entity and its collection. The framework owns the user's
// authorization contract (admin/block), so the domain user extends the SDK
// `User` and adds only the poll's own profile fields. The `users` slot of the
// Hilos users/user admin pages and the session `currentUser` selector both
// resolve to this one `user` entity (data-model.md: one entity per (type,id)
// per scope).
import {
  entityCollection,
  readBoolean,
  readNumber,
  readString,
  readStringOrNull,
  type EntityCollection,
  type User as FrameworkUser,
} from '@hilos/core'

import { scopes } from '../bootstrap/session'

/** The canonical entity type — keep in sync with the backend `users` source. */
export const USER_TYPE = 'user'

/** The poll user: the framework authorization fields plus poll profile fields. */
export interface User extends FrameworkUser {
  /** Display name. */
  readonly name: string
  /** Last activity timestamp, or null when never recorded. */
  readonly lastActivity: string | null
}

/**
 * Project a committed user's raw fields into the typed entity.
 *
 * @param fields The user entity's committed fields.
 */
export function userFromFields(
  fields: Readonly<Record<string, unknown>>,
): User {
  return {
    id: readNumber(fields, 'id'),
    admin: readBoolean(fields, 'admin'),
    block: readBoolean(fields, 'block'),
    name: readString(fields, 'name'),
    lastActivity: readStringOrNull(fields, 'lastActivity'),
  }
}

/** The user collection: typed reference resolution for the `user` entity type. */
export const Users: EntityCollection<User> = entityCollection(
  scopes,
  USER_TYPE,
  userFromFields,
)
