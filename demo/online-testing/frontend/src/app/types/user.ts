// The online-testing user collection. The person and the reading of its fields are the
// framework's (`User` and `userFromFields` of @hilos/core), and the online-testing demo
// adds no field of its own, so this file only binds the people collection to the
// online-testing demo's stores. The `users` slot of the Hilos users/user admin pages and
// the session `currentUser` selector both resolve to this one `user` entity
// (data-model.md: one entity per (type,id) per scope).
import {
  entityCollection,
  USER_ENTITY_TYPE,
  userFromFields,
  type EntityCollection,
  type User,
} from '@hilos/core'

import { scopes } from '../bootstrap/session.js'

/** The user collection: typed reference resolution for the `user` entity type. */
export const Users: EntityCollection<User> = entityCollection(
  scopes,
  USER_ENTITY_TYPE,
  userFromFields,
)
