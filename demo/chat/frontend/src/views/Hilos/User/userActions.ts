// The Hilos user detail actions: the rename submit and its failure feedback. The
// backend UserPage acks a rename through dedicated project signals
// (HILOS_USER_UPDATE_SUCCESS / _FAIL) rather than the framework action_error, so
// success is observed state-driven in the view (the committed name reaches the
// draft) while a failure is surfaced here from the fail signal. The signal has no
// registered schema, so it arrives as an `unknownSignal`; we react to its type
// only and never read its raw payload (the parse boundary stays the one place
// that interprets a frame — wire-protocol.md).
import { createSignal, type ReadonlySignal } from '@hilos/core'

import { connection } from '../../../bootstrap/connection'

// Backend action and ack signal names (PHP ChatSignalConstants).
const HILOS_USER_UPDATE_ACTION = 'hilos_user_update'
const HILOS_USER_UPDATE_FAIL = 'hilos_user_update_fail'

const error = createSignal<string | null>(null)

/** The latest rename failure reason, or null when the form is clear. */
export const renameError: ReadonlySignal<string | null> = error

// A rejected rename arrives as the backend's fail ack; surface a generic message
// (the reason text stays backend-side, behind the unregistered signal schema).
connection.on('unknownSignal', (signal) => {
  if (signal.type === HILOS_USER_UPDATE_FAIL) {
    error.set('Could not rename the user. Please try again.')
  }
})

/** Clear the rename error (on opening or cancelling the edit form). */
export function clearRenameError(): void {
  error.set(null)
}

/**
 * Submit a user rename: clear any prior error and send the update action. The
 * committed name returns over the live table, so the view detects success by
 * watching the detail name reach the submitted value. Returns false, sending
 * nothing, when the connection is not `connected`.
 *
 * @param id The user id to rename.
 * @param name The new display name.
 */
export function sendUserRename(id: number, name: string): boolean {
  error.set(null)

  return connection.sendAction(HILOS_USER_UPDATE_ACTION, { id, name })
}
