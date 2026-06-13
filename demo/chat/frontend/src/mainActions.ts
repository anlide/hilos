// The main chat page actions: client-to-server submits the view fires. Selectors
// (mainPage.ts) read the page payload; this module writes back over the same
// connection through the core sendAction primitive. The message action mirrors
// the backend MainPage ACTIONS entry (ChatSignalConstants::MESSAGE → its
// MessageActionDTO {content}); the publish only happens once the backend
// moderates and emits the event, never optimistically here.
import { connection } from './connection'

/** Backend action name routed by MainPage (PHP `ChatSignalConstants::MESSAGE`). */
const MESSAGE_ACTION = 'message'

/**
 * Re-send lockout in seconds shown after a submit, mirroring the backend
 * `ChatUserState::MESSAGE_RATE_LIMIT_SECONDS`. The backend tolerates a re-send
 * one second early, so the client countdown is the safe upper bound.
 */
export const MESSAGE_RATE_LIMIT_SECONDS = 10

/**
 * Submit a chat message: send the `message` action carrying the text content.
 * Returns false, sending nothing, when the connection is not `connected`.
 *
 * @param content The message text to submit.
 */
export function sendChatMessage(content: string): boolean {
  return connection.sendAction(MESSAGE_ACTION, { content })
}
