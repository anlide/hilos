// The main chat page actions: client-to-server submits the view fires. Selectors
// (mainPage.ts) read the page payload; this module writes back over the same
// connection through the core sendAction primitive. The message action mirrors
// the backend MainPage ACTIONS entry (ChatSignalConstants::MESSAGE → its
// MessageActionDTO {content, attachments}); the files themselves travel through
// the framework uploads client (@hilos/core uploadFile, HIL-144), and a message
// names the complete ones by their client ids. The publish only happens once the backend
// moderates and emits the event, never optimistically here. A rejected submit
// comes back as a framework `action_error`, handled by the core ActionErrorStore
// (bootstrap/connection.ts); this module just exposes the `message` action's slice.
import { type ReadonlySignal } from '@hilos/core'

import { actionErrors, connection } from '../../bootstrap/connection'

/** Backend action name routed by MainPage (PHP `ChatSignalConstants::MESSAGE`). */
const MESSAGE_ACTION = 'message'

/**
 * Re-send lockout in seconds shown after a submit, mirroring the backend
 * `ChatUserState::MESSAGE_RATE_LIMIT_SECONDS`. The backend tolerates a re-send
 * one second early, so the client countdown is the safe upper bound; the
 * backend-reported remaining (`messageRateLimitSecondsRemaining`) reconciles it.
 */
export const MESSAGE_RATE_LIMIT_SECONDS = 10

/** The latest message-send error reason, or null when the composer is clear. */
export const messageError: ReadonlySignal<string | null> =
  actionErrors.signal(MESSAGE_ACTION)

/**
 * Submit a chat message: clear any prior error and send the `message` action
 * carrying the text and the files it rides with. Returns false, sending
 * nothing, when the connection is not `connected`.
 *
 * @param content The message text to submit.
 * @param attachments Client ids of the complete uploads the message carries, in attach order.
 */
export function sendChatMessage(
  content: string,
  attachments: readonly string[],
): boolean {
  actionErrors.clear(MESSAGE_ACTION)

  return connection.sendAction(MESSAGE_ACTION, { content, attachments })
}
