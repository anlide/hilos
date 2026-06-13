// The main chat page actions: client-to-server submits the view fires. Selectors
// (mainPage.ts) read the page payload; this module writes back over the same
// connection through the core sendAction primitive. The message action mirrors
// the backend MainPage ACTIONS entry (ChatSignalConstants::MESSAGE → its
// MessageActionDTO {content}); the publish only happens once the backend
// moderates and emits the event, never optimistically here. A rejected submit
// comes back as a framework `action_error`, handled by the core ActionErrorStore
// (connection.ts); this module just exposes the `message` action's slice of it.
import { type ReadonlySignal } from '@hilos/core'

import { actionErrors, connection } from './connection'

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
 * carrying the text content. Returns false, sending nothing, when the
 * connection is not `connected`.
 *
 * @param content The message text to submit.
 */
export function sendChatMessage(content: string): boolean {
  actionErrors.clear(MESSAGE_ACTION)

  return connection.sendAction(MESSAGE_ACTION, { content })
}
