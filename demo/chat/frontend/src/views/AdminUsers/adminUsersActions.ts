// The admin-users action: a rename submitted as a tracked action over the
// connection's reply lifecycle (action-lifecycle, step 7.4). It returns an
// ActionHandle whose `done` resolves on the backend's `::success` ack and rejects
// (with the reason) on `::fail` — the view closes the modal on success and
// surfaces the failure (authoritative-backend). The committed row returns
// separately over the live table, so the success ack arrives even when the edit
// changed nothing.
import { type ActionHandle } from '@hilos/core'

import { actions } from '../../bootstrap/connection'
import { adminUsersTable } from './adminUsersPage'

// Backend action name (ChatSignalConstants::USER_UPDATE).
const ADMIN_USER_UPDATE = 'user_update'

/**
 * Rename a user as a tracked action. The row is marked as this tab's own change
 * so its echo applies at once here while other tabs keep the pending gate.
 *
 * @param id The user id to rename.
 * @param name The new display name.
 */
export function sendAdminUserUpdate(id: number, name: string): ActionHandle {
  const handle = actions.dispatch(ADMIN_USER_UPDATE, { id, name })
  adminUsersTable.expectOwnChange(String(id), handle.done)

  return handle
}
