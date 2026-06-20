// The poll's HilosUsersContext: binds the framework Hilos users/user admin pages
// (@hilos/angular HilosUsersPage / HilosUserPage) to this project's scope stores,
// live connection, and typed user collection. The framework owns the table, the
// row view-model, search/sort/paging, and the rename round-trip; the project
// supplies only where the data lives. Shared by the list and detail wrappers
// (HilosPages.USERS / USER), which carry the same row slots.
import { type HilosUsersContext } from '@hilos/core'

import { connection } from '../../bootstrap/connection'
import { scopes } from '../../bootstrap/session'
import { type User, Users } from '../../types/user'

/** This project's context for the framework users/user admin pages. */
export const hilosUsersContext: HilosUsersContext<User> = {
  scopes,
  connection,
  users: Users,
}
