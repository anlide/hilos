// The admin-users action: a rename submitted as a tracked action over the
// connection's reply lifecycle (action-lifecycle, step 7.4). It returns an
// ActionHandle whose `done` resolves on the backend's `::success` ack and rejects
// (with the reason) on `::fail` — the view closes the modal on success and
// surfaces the failure (authoritative-backend). The committed row returns
// separately over the live table, so the success ack arrives even when the edit
// changed nothing.
import { type ActionHandle } from '@hilos/core'

import { actions } from '../../bootstrap/connection'

// Backend action name (ChatSignalConstants::USER_UPDATE).
const ADMIN_USER_UPDATE = 'user_update'

// Backend page-action name (ChatSignalConstants::IMPERSONATE_START).
const IMPERSONATE_START = 'impersonate_start'

/**
 * Rename a user as a tracked action. Own-change is decided server-side: the
 * backend tags this tab's own echo `own` (page action origin) so it applies at
 * once here while other tabs keep the pending gate.
 *
 * @param id The user id to rename.
 * @param name The new display name.
 */
export function sendAdminUserUpdate(id: number, name: string): ActionHandle {
  return actions.dispatch(ADMIN_USER_UPDATE, { id, name })
}

/**
 * Start impersonating the target user as a tracked action. The visible effect —
 * the shell banner and this admin session becoming the target — is server-driven
 * through the handshake broadcast, so the caller only awaits the action ack.
 *
 * @param targetUserId The user id to impersonate.
 */
export function sendImpersonateStart(targetUserId: number): ActionHandle {
  return actions.dispatch(IMPERSONATE_START, { targetUserId })
}
