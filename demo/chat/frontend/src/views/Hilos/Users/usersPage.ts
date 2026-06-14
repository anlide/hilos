// The Hilos users list page selectors: the page-scoped table rows fed into a
// headless TableController (search/sort/paginate on the client), each raw row
// resolved into its HilosUserRow view-model. The user profile rides the `users`
// slot as an entity reference (so a rename fans out through the entity store);
// the live presence rides the inline `connections` slot. The view reads the
// controller and never touches a raw store.
import {
  readNumber,
  TableController,
  type EntityRef,
  type TableRow,
} from '@hilos/core'

import { scopes } from '../../../bootstrap/session'
import { Users, toPresence } from '../../../types'
import { type HilosUserRow } from '../../../types/tables/HilosUserRow'

// Wire keys: the Hilos users table (backend ChatTableContext::hilosUsers) and the
// single-user detail table (ChatBrowserTable::USER_DETAIL), which carry the same
// row slots so both resolve through resolveHilosUserRow.
export const HILOS_USERS_TABLE = 'hilosUsers'
export const USER_DETAIL_TABLE = 'userDetail'
// Row slots: the user entity (backend ChatDbContext::users, typed `user` via
// pageEntityTypes) and the inline runtime connection summary (ChatRtContext::connections).
const USER_SLOT = 'users'
const CONNECTIONS_SLOT = 'connections'
const PRESENCE_FIELD = 'presence'
const ONLINE_SESSION_COUNT_FIELD = 'onlineSessionCount'

/** Read a row slot as an inline record, or undefined when it is not one. */
function recordSlot(slot: unknown): Record<string, unknown> | undefined {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : undefined
}

/**
 * Resolve one raw Hilos-user table row into its view-model: the referenced user
 * entity folded together with the inline connection summary. Shared by the list
 * and the single-user detail page, which deliver the same row shape.
 *
 * @param row The raw table row from the page-scoped table store.
 */
export function resolveHilosUserRow(row: TableRow): HilosUserRow {
  const ref = row.slots[USER_SLOT] as EntityRef | undefined
  const user = ref ? Users.signal(ref).get() : undefined
  const connection = recordSlot(row.slots[CONNECTIONS_SLOT])

  return {
    id: Number(user?.id ?? row.rowKey),
    name: user?.name ?? '',
    lastActivity: user?.lastActivity ?? null,
    presence: toPresence(connection?.[PRESENCE_FIELD]),
    onlineSessionCount: connection
      ? readNumber(connection, ONLINE_SESSION_COUNT_FIELD)
      : 0,
  }
}

/** The headless controller for the Hilos users table (client viewport). */
export const usersTable = new TableController<HilosUserRow>({
  source: scopes.pageTableSignal(HILOS_USERS_TABLE),
  resolve: resolveHilosUserRow,
  searchText: (row) => row.name,
  sortValue: (row, field) => {
    switch (field) {
      case 'name':
        return row.name
      case 'presence':
        return row.presence
      case 'onlineSessionCount':
        return row.onlineSessionCount
      case 'lastActivity':
        return row.lastActivity
      default:
        return row.id
    }
  },
  pageSize: 20,
  initialSort: { field: 'id', direction: 'asc' },
})
