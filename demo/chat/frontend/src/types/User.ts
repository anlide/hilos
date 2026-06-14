// The chat user entity and its collection. The framework owns the user's
// authorization contract (superadmin/blocked), so the domain user extends the
// SDK `User` and adds only the chat's own profile fields. The `users` slot of
// the main page lists and the session `currentUser` slot both resolve to this
// one `user` entity (data-model.md: one entity per (type,id) per scope).
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

/** The chat user: the framework authorization fields plus chat profile fields. */
export interface User extends FrameworkUser {
  /** Display name. */
  readonly name: string
  /** Last activity timestamp, or null when never recorded. */
  readonly lastActivity: string | null
}

/**
 * Project a committed user's raw fields into the typed entity. Defaults the
 * framework authorization fields until a backend emits them (the chat schema
 * does not carry them yet).
 *
 * @param fields The user entity's committed fields.
 */
export function userFromFields(
  fields: Readonly<Record<string, unknown>>,
): User {
  return {
    id: readNumber(fields, 'id'),
    superadmin: readBoolean(fields, 'superadmin'),
    blocked: readBoolean(fields, 'blocked'),
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
