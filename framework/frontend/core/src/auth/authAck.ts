// The one translation between what the server says was achieved and where the
// auth machine has to stand to draw it (HIL-422). The mark travels as a value,
// the machine speaks in step+intent, and the screen falls out of the pair the
// machine already knows (authFlow.ts DONE_SCREENS) — so this module maps the
// value onto that pair. A view applies the result through `applyExternal`, the
// same door a converge comes in by, which is why the flow machine itself needs
// no branch for acks at all. A mark that CLEARS is this module's decision
// (`shouldLowerAckPanel`) and the view's execution: the function says whether
// the standing panel is still owed, and only a view knows whether the panel it
// is showing is the one the mark raised (HIL-865, HIL-955).

import {
  SESSION_ACK_PASSWORD_CHANGED,
  SESSION_ACK_REGISTERED,
  SESSION_ACK_SIGNED_IN,
} from '../session/sessionScope.js'

import { type AuthFlowState } from './authFlow.js'

/**
 * The flow patch a pending ack asks for, or null when there is nothing to show.
 *
 * Null covers three cases deliberately answered the same way: no ack, an ack the
 * person already dismissed, and a kind this build has no screen for. The last one
 * is the reason the default is null rather than a throw — a server one deploy
 * ahead must not leave the surface stuck on a panel it cannot draw. Null is a
 * patch that is not asked for, never an instruction to leave a screen: a caller
 * that wants to act on an ack being answered watches the mark itself change to
 * null, and decides on the step it is standing on (HIL-865).
 *
 * @param ack The ack the session carries, from `sessionPendingAck`.
 */
export function authAckToFlowPatch(
  ack: string | null,
): Partial<AuthFlowState> | null {
  switch (ack) {
    case SESSION_ACK_REGISTERED:
      return { step: 'done', intent: 'register' }
    case SESSION_ACK_PASSWORD_CHANGED:
      return { step: 'done', intent: 'recovery' }
    case SESSION_ACK_SIGNED_IN:
      return { step: 'done', intent: 'login' }
    default:
      return null
  }
}

/**
 * Whether a handshake that says the session owes nothing should take the
 * standing panel down (HIL-955).
 *
 * The first member is the RAW value off the frame, never
 * {@link authAckToFlowPatch} of it: an ack kind this build cannot draw
 * returns a null patch and still means the session owes a sentence.
 *
 * @param params The three facts the rule reads.
 * @param params.ackOnHandshake The raw ack off the handshake_response frame.
 * @param params.panelRaisedByAck Whether this panel was raised by the mark.
 * @param params.step The step the auth machine is standing on.
 */
export function shouldLowerAckPanel(params: {
  ackOnHandshake: string | null
  panelRaisedByAck: boolean
  step: AuthFlowState['step']
}): boolean {
  return (
    params.ackOnHandshake === null &&
    params.panelRaisedByAck &&
    params.step === 'done'
  )
}
