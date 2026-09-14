import { describe, expect, it } from 'vitest'
import {
  authAckToFlowPatch,
  shouldLowerAckPanel,
} from '../../src/auth/authAck.js'
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

describe('shouldLowerAckPanel', () => {
  it('lowers when the handshake owes nothing, the mark raised the panel, and the machine stands on done', () => {
    expect(
      shouldLowerAckPanel({
        ackOnHandshake: null,
        panelRaisedByAck: true,
        step: 'done',
      }),
    ).toBe(true)
  })

  it('refuses when the frame names any non-empty ack, including a kind this build cannot draw', () => {
    expect(
      shouldLowerAckPanel({
        ackOnHandshake: SESSION_ACK_PASSWORD_CHANGED,
        panelRaisedByAck: true,
        step: 'done',
      }),
    ).toBe(false)
    expect(
      shouldLowerAckPanel({
        ackOnHandshake: 'auth_teleported',
        panelRaisedByAck: true,
        step: 'done',
      }),
    ).toBe(false)
  })

  it('refuses when the panel was not raised by the mark', () => {
    expect(
      shouldLowerAckPanel({
        ackOnHandshake: null,
        panelRaisedByAck: false,
        step: 'done',
      }),
    ).toBe(false)
  })

  it('refuses off the done step', () => {
    expect(
      shouldLowerAckPanel({
        ackOnHandshake: null,
        panelRaisedByAck: true,
        step: 'identifier',
      }),
    ).toBe(false)
  })
})
