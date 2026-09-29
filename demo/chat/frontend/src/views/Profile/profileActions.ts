// The chat's own rename, handed to the framework profile page (HIL-1169). The
// wire is the chat's (ChatSignalConstants::RENAME → RenameActionDTO {newName});
// the new name only appears once the backend moderates and renames the user,
// never optimistically here. A rejected rename comes back as a framework
// action_error, held by the core ActionErrorStore; success is state-driven —
// the committed name arrives over the self-connection data (profilePage.ts).
import { type HilosProfileRename } from '@hilos/core'

import { actionErrors, connection } from '../../bootstrap/connection.js'

/** Backend action name routed to the users library (PHP `ChatSignalConstants::RENAME`). */
const RENAME_ACTION = 'rename'

/** The protected operation a rename confirms first (PHP `ChatStepUpOperationKey::CHANGE_NAME`). */
const CHANGE_NAME_OPERATION = 'change_name'

/** The shortest display name the chat accepts. */
const NAME_MIN = 2

/** The longest display name the chat accepts. */
const NAME_MAX = 64

/** The chat's moderated rename, as the framework profile page asks for it. */
export const chatProfileRename: HilosProfileRename = {
  send(newName) {
    return connection.sendAction(RENAME_ACTION, { newName })
  },
  refusal: actionErrors.signal(RENAME_ACTION),
  clearRefusal() {
    actionErrors.clear(RENAME_ACTION)
  },
  stepUpOperation: CHANGE_NAME_OPERATION,
  minLength: NAME_MIN,
  maxLength: NAME_MAX,
}
