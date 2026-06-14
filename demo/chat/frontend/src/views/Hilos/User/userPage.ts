// The Hilos user detail page selector: the single-user detail table (one row,
// filtered by the route's userId on the backend) resolved into the same
// HilosUserRow view-model the list uses. The profile rides the `users` entity
// slot, so a rename re-renders here without a refresh; presence rides the inline
// `connections` slot. The view reads this signal and never touches a raw store.
import { computedSignal, type ReadonlySignal } from '@hilos/core'

import { scopes } from '../../../bootstrap/session'
import { type HilosUserRow } from '../../../types/tables/HilosUserRow'
import { resolveHilosUserRow, USER_DETAIL_TABLE } from '../Users/usersPage'

const detailRows = scopes.pageTableSignal(USER_DETAIL_TABLE)

/**
 * The requested user's detail row, or undefined until the single-row detail
 * table lands. The backend filters the table to the route's userId, so the first
 * (only) row is the requested user.
 */
export const userDetail: ReadonlySignal<HilosUserRow | undefined> =
  computedSignal(() => {
    const rows = detailRows.get()

    return rows.length === 0 ? undefined : resolveHilosUserRow(rows[0])
  })
