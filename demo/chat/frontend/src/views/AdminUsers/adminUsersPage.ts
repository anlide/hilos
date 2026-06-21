// The admin-users page selectors: the page-scoped admin users table fed into a
// server-windowed TableViewportController, each row resolved into the framework
// HilosUserRow view-model. The bot/moderator admin tables are chat-owned, but the
// user row shape is the framework's, so this reuses resolveHilosUserRow over the
// chat Users collection (the `users` slot is an entity reference, so an admin
// rename fans out to the row for free). The view reads the controller, never a
// raw store.
import {
  TableViewportController,
  bindTableViewport,
  resolveHilosUserRow,
  type HilosUserRow,
} from '@hilos/core'

import { connection } from '../../bootstrap/connection'
import { scopes } from '../../bootstrap/session'
import { PAGE_ADMIN_USERS } from '../../pages/keys'
import { Users } from '../../types'

// Wire keys: the admin users table (ChatTableContext::adminUsers) and its row's
// entity-ref slot (ChatDbContext::users, resolved through the Users collection).
const USERS_TABLE = 'adminUsers'
const USER_SLOT = 'users'
const USERS_PAGE_SIZE = 10

/**
 * The server-windowed controller for the admin users table: search, sort, and
 * paging change the viewport descriptor sent over the connection, and the backend
 * replies a window plus live deltas scoped to the table's (page, tableKey)
 * address. Rows resolve through the framework {@link resolveHilosUserRow} against
 * the chat Users collection.
 */
export const adminUsersTable = new TableViewportController<HilosUserRow>({
  resolve: (row) => resolveHilosUserRow(row, Users),
  sendViewport: (descriptor) =>
    connection.sendTableViewport(PAGE_ADMIN_USERS, USERS_TABLE, descriptor),
  pageSize: USERS_PAGE_SIZE,
  initialSort: { field: 'id', direction: 'asc' },
})

const teardown: Array<() => void> = []

/** Bind the table to the connection and request the first window — call on mount. */
export function startAdminUsersTable(): void {
  teardown.push(
    bindTableViewport(
      connection,
      scopes,
      { page: PAGE_ADMIN_USERS, tableKey: USERS_TABLE },
      adminUsersTable,
      // The user slot is an entity; normalize it under the collection's type so
      // the row resolves through Users.
      { entityTypes: { [USER_SLOT]: Users.type } },
    ),
    // Re-request the window whenever the socket (re)connects: the initial request
    // below can run before the connection is open, and a reconnect is a fresh
    // exchange that no longer remembers this connection's window.
    connection.on('state', (state) => {
      if (state === 'connected') {
        adminUsersTable.start()
      }
    }),
  )
  adminUsersTable.start()
}

/** Unbind from the connection — call on unmount. */
export function disposeAdminUsersTable(): void {
  for (const off of teardown.splice(0)) {
    off()
  }
}
