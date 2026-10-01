// This demo's HilosUsersContext: binds the framework Hilos users/user admin pages
// (@hilos/vue HilosUsersPage / HilosUserPage) to this project's scope stores,
// live connection, and typed user collection. The framework owns the table, the
// row view-model, search/sort/paging, the rename round-trip, and the takeover; the
// project supplies only where the data lives. Shared by the list and detail wrappers
// (HilosPages.USERS / USER), which carry the same row slots. No account merge on
// the card: this demo wires none of its seams, and the merge stays on the account
// side, in chat.
import { type HilosUsersContext } from '@hilos/core'

import { actions, connection } from '../../../bootstrap/connection'
import { scopes } from '../../../bootstrap/session'
import { Users } from '../../../types'

/** This project's context for the framework users/user admin pages. */
export const hilosUsersContext: HilosUsersContext = {
  scopes,
  connection,
  actions,
  users: Users,
}
