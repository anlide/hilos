import { describe, expect, it } from 'vitest'

import { type HilosConnection } from '../../src/connection/HilosConnection.js'
import {
  bindCodeSendProgress,
  CODE_SEND_STATE_FAILED,
  CODE_SEND_STATE_NOT_SENT,
  CODE_SEND_STATE_QUEUED,
  CODE_SEND_STATE_SENT,
  codeSendProgressSchema,
  hilosCodeSendProgress,
  SIGNAL_CODE_SEND_PROGRESS,
} from '../../src/auth/authSendProgress.js'
import { SESSION_SIGNAL_SCHEMAS } from '../../src/session/sessionScope.js'

describe('code send progress frame', () => {
  it('parses a reported step with the provider sentence', () => {
    const parsed = codeSendProgressSchema.parse({
      state: CODE_SEND_STATE_FAILED,
      channel: 'email',
      detail: 'mailbox unavailable (550)',
    })

    expect(parsed.state).toBe(CODE_SEND_STATE_FAILED)
    expect(parsed.channel).toBe('email')
    // The provider's own words travel as text and untranslated: the refusal is
    // the one thing on this line no key of ours can hold.
    expect(parsed.detail).toBe('mailbox unavailable (550)')
  })

  it('parses the step of a letter that was only written down', () => {
    const parsed = codeSendProgressSchema.parse({
      state: CODE_SEND_STATE_NOT_SENT,
      channel: 'email',
      detail: null,
    })

    // Byte-equal to the PHP constant, because the wire is where the two meet:
    // the backend writes this word and the copy table is keyed by it.
    expect(CODE_SEND_STATE_NOT_SENT).toBe('not_sent')
    expect(parsed.state).toBe(CODE_SEND_STATE_NOT_SENT)
    // No sentence rides the mark: there is no provider and nothing went wrong.
    expect(parsed.detail).toBeNull()
  })

  it('parses the empty frame that takes the line away', () => {
    const parsed = codeSendProgressSchema.parse({
      state: null,
      channel: null,
      detail: null,
    })

    // The commonest frame the signal carries: it answers a handshake for a
    // session waiting for nothing, so refusing it would refuse silence its
    // meaning and leave a reconnecting tab under a line nothing would remove.
    expect(parsed.state).toBeNull()
    expect(parsed.channel).toBeNull()
    expect(parsed.detail).toBeNull()
  })

  it('reads a frame that omits what it has nothing to say about', () => {
    const parsed = codeSendProgressSchema.parse({
      state: CODE_SEND_STATE_QUEUED,
      channel: 'email',
    })

    // A queued send has no sentence yet, and a node one version behind may not
    // write the field at all; both are the same absence and both mean "none".
    expect(parsed.detail).toBeNull()
  })

  it('carries the outcome of a phone code on its closing step (HIL-1044)', () => {
    const parsed = codeSendProgressSchema.parse({
      state: CODE_SEND_STATE_SENT,
      channel: 'telegram',
      detail: null,
      ticket: 'a1b2c3d4e5f60718',
      reason: 'code_sent',
      resendAt: 1_790_000_060_000,
      expiresAt: 1_790_000_600_000,
    })

    // The ticket is how the tab that asked tells its own send from a line
    // replayed about another; the reason and the moments are what the code
    // screen needs from the ending.
    expect(parsed.ticket).toBe('a1b2c3d4e5f60718')
    expect(parsed.reason).toBe('code_sent')
    expect(parsed.resendAt).toBe(1_790_000_060_000)
    expect(parsed.expiresAt).toBe(1_790_000_600_000)
  })

  it('reads a step before the closing one as carrying no outcome', () => {
    const parsed = codeSendProgressSchema.parse({
      state: CODE_SEND_STATE_QUEUED,
      channel: 'telegram',
    })

    expect(parsed.ticket).toBeNull()
    expect(parsed.reason).toBeNull()
    expect(parsed.resendAt).toBeNull()
    expect(parsed.expiresAt).toBeNull()
  })

  it('holds what the server said, for a reader that arrives afterwards', () => {
    const listeners: ((signal: { type: string; data: unknown }) => void)[] = []
    const connection = {
      on: (_event: string, listener: (signal: never) => void) => {
        listeners.push(
          listener as (signal: { type: string; data: unknown }) => void,
        )

        return () => undefined
      },
    } as unknown as HilosConnection

    bindCodeSendProgress(connection)
    for (const listener of listeners) {
      listener({
        type: SIGNAL_CODE_SEND_PROGRESS,
        data: { state: CODE_SEND_STATE_SENT, channel: 'email', detail: null },
      })
    }

    // The whole reason this is bound at boot rather than by the surface: the
    // server publishes the line on the HANDSHAKE, and on a gated page the surface
    // mounts only after that handshake was answered. Reading the HELD value is
    // what makes a reload come back to the same line.
    expect(hilosCodeSendProgress.get()).toEqual({
      state: CODE_SEND_STATE_SENT,
      channel: 'email',
      detail: null,
    })

    for (const listener of listeners) {
      listener({
        type: SIGNAL_CODE_SEND_PROGRESS,
        data: { state: null, channel: null, detail: null },
      })
    }

    // And the empty frame is held as the absence it means, not ignored.
    expect(hilosCodeSendProgress.get()).toBeNull()
  })

  it('is merged into every connection rather than one project at a time', () => {
    // The reason the line rides the SESSION bundle and not the auth-code one:
    // this bundle is merged inside createHilosConnection, so a demo cannot end
    // up without the parse boundary and therefore without the line.
    expect(SESSION_SIGNAL_SCHEMAS[SIGNAL_CODE_SEND_PROGRESS]).toBe(
      codeSendProgressSchema,
    )
  })
})
