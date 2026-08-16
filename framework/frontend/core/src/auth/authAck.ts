// The one translation between what the server says was achieved and where the
// auth machine has to stand to draw it (HIL-422). The mark travels as a value,
// the machine speaks in step+intent, and the screen falls out of the pair the
// machine already knows (authFlow.ts DONE_SCREENS) — so this module maps the
// value onto that pair and owns nothing else. A view applies the result through
// `applyExternal`, the same door a converge comes in by, which is why the flow
// machine itself needs no branch for acks at all.

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
 * ahead must not leave the surface stuck on a panel it cannot draw.
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
