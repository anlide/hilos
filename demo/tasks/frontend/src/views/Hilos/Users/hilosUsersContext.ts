// The tasks demo's HilosUsersContext: binds the framework Hilos users/user admin pages
// (@hilos/react HilosUsersPage / HilosUserPage) to this project's scope stores,
// live connection, and typed user collection. The framework owns the table, the
// row view-model, search/sort/paging, the rename round-trip, and the takeover; the
// project supplies only where the data lives. Shared by the list and detail wrappers
// (HilosPages.USERS / USER), which carry the same row slots.
import { type HilosUsersContext } from '@hilos/core'

import { actions, connection } from '../../../bootstrap/connection'
import { scopes } from '../../../bootstrap/session'
import { type User, Users } from '../../../types'

/** This project's context for the framework users/user admin pages. */
export const hilosUsersContext: HilosUsersContext<User> = {
  scopes,
  connection,
  actions,
  users: Users,
}
