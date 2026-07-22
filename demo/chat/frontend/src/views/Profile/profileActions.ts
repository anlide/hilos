// The profile page actions: the client-to-server submits the view fires. The
// rename mirrors the backend ProfilePage ACTIONS entry (ChatSignalConstants::RENAME
// → RenameActionDTO {newName}); the new name only appears once the backend
// moderates and renames the user, never optimistically here. A rejected rename
// comes back as a framework action_error (the page no longer sends a bespoke
// ack), handled by the core ActionErrorStore; success is state-driven — the
// committed name arrives over the self-connection data (profilePage.ts).
import { type ReadonlySignal } from '@hilos/core'

import { actionErrors, connection } from '../../bootstrap/connection'

/** Backend action name routed by ProfilePage (PHP `ChatSignalConstants::RENAME`). */
const RENAME_ACTION = 'rename'

/** The latest rename error reason, or null when clear (framework action_error). */
export const renameError: ReadonlySignal<string | null> =
  actionErrors.signal(RENAME_ACTION)

/**
 * Submit a display-name change: clear any prior error and send the `rename`
 * action carrying the new name. Returns false, sending nothing, when the
 * connection is not `connected`.
 *
 * @param newName The requested display name.
 */
export function sendRename(newName: string): boolean {
  actionErrors.clear(RENAME_ACTION)

  return connection.sendAction(RENAME_ACTION, { newName })
}

/** Clear the rename error — the view does this when opening the edit modal. */
export function clearRenameError(): void {
  actionErrors.clear(RENAME_ACTION)
}

/** Backend action name routed by ProfilePage (PHP `ChatSignalConstants::UNLINK_IDENTITY`). */
const UNLINK_IDENTITY_ACTION = 'unlink_identity'

/** The latest unlink error reason, or null when clear (framework action_error). */
export const unlinkIdentityError: ReadonlySignal<string | null> =
  actionErrors.signal(UNLINK_IDENTITY_ACTION)

/**
 * Submit an unlink of one login identity: clear any prior error and send the
 * `unlink_identity` action carrying the identity id. The row disappears only
 * once the backend deletes it and the identities projection re-emits, never
 * optimistically here. Returns false, sending nothing, when the connection is
 * not `connected`.
 *
 * @param identityId The id of the identity to unlink.
 */
export function sendUnlinkIdentity(identityId: number): boolean {
  actionErrors.clear(UNLINK_IDENTITY_ACTION)

  return connection.sendAction(UNLINK_IDENTITY_ACTION, { identityId })
}
