import { describe, expect, it } from 'vitest'
import { authAckToFlowPatch } from '../../src/auth/authAck.js'
import {
  SESSION_ACK_PASSWORD_CHANGED,
  SESSION_ACK_REGISTERED,
  SESSION_ACK_SIGNED_IN,
} from '../../src/session/sessionScope.js'

describe('authAckToFlowPatch', () => {
  it('sends each kind to the done step under its own intent', () => {
    expect(authAckToFlowPatch(SESSION_ACK_REGISTERED)).toStrictEqual({
      step: 'done',
      intent: 'register',
    })
    expect(authAckToFlowPatch(SESSION_ACK_PASSWORD_CHANGED)).toStrictEqual({
      step: 'done',
      intent: 'recovery',
    })
    expect(authAckToFlowPatch(SESSION_ACK_SIGNED_IN)).toStrictEqual({
      step: 'done',
      intent: 'login',
    })
  })

  it('asks for nothing when there is no ack', () => {
    expect(authAckToFlowPatch(null)).toBeNull()
  })

  it('asks for nothing for a kind this build has no screen for', () => {
    expect(authAckToFlowPatch('auth_teleported')).toBeNull()
    expect(authAckToFlowPatch('')).toBeNull()
  })
})
