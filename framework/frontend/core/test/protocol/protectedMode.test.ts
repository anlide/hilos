import { describe, expect, it } from 'vitest'
import { parseSignal } from '../../src/protocol/parseSignal.js'
import {
  PROTECTED_MODE_FALLBACK_COPY,
  toProtectedModeStatus,
} from '../../src/protocol/protectedMode.js'

/**
 * The two ways a client learns it is locked out (wire side).
 *
 * The welcome's block is tolerated: an older or half-built daemon must still get
 * the client through its handshake, because the build check and the session ride
 * the same frame. The pushed `protected_mode` frame is the opposite — it carries
 * nothing else, so an unreadable one is rejected rather than half-believed.
 */
describe('the protected-mode block on the welcome', () => {
  it('is read into the handshake signal', () => {
    const result = parseSignal(
      JSON.stringify({
        type: 'handshake',
        data: {
          build: 'build-a',
          protectedMode: {
            active: true,
            operation: 'restore',
            title: 'Restoring a backup',
            message: 'Back shortly.',
          },
        },
      }),
    )

    expect(result.ok).toBe(true)
    if (!result.ok || result.signal.kind !== 'handshake') {
      throw new Error('expected a handshake signal')
    }
    expect(result.signal.protectedMode).toEqual({
      acceptsPass: false,
      passRejected: false,
      active: true,
      operation: 'restore',
      title: 'Restoring a backup',
      message: 'Back shortly.',
    })
  })

  it('reads as inactive when the daemon sends no block at all', () => {
    const result = parseSignal(
      JSON.stringify({ type: 'handshake', data: { build: 'build-a' } }),
    )

    expect(result.ok).toBe(true)
    if (!result.ok || result.signal.kind !== 'handshake') {
      throw new Error('expected a handshake signal')
    }
    expect(result.signal.protectedMode.active).toBe(false)
  })

  it('survives a malformed block instead of costing the client its welcome', () => {
    const result = parseSignal(
      JSON.stringify({
        type: 'handshake',
        data: { build: 'build-a', protectedMode: 'not-a-block' },
      }),
    )

    expect(result.ok).toBe(true)
    if (!result.ok || result.signal.kind !== 'handshake') {
      throw new Error('expected a handshake signal')
    }
    expect(result.signal.build).toBe('build-a')
    expect(result.signal.protectedMode.active).toBe(false)
  })
})

describe('the pushed protected_mode frame', () => {
  it('parses into its own signal kind', () => {
    const result = parseSignal(
      JSON.stringify({
        type: 'protected_mode',
        data: {
          active: true,
          operation: 'restore',
          title: 'Restoring a backup',
          message: 'Back shortly.',
        },
      }),
    )

    expect(result.ok).toBe(true)
    if (!result.ok || result.signal.kind !== 'protectedMode') {
      throw new Error('expected a protectedMode signal')
    }
    expect(result.signal.state.active).toBe(true)
    expect(result.signal.state.operation).toBe('restore')
  })

  it('normalizes the nulls of a lift frame to undefined', () => {
    const result = parseSignal(
      JSON.stringify({
        type: 'protected_mode',
        data: { active: false, operation: null, title: null, message: null },
      }),
    )

    expect(result.ok).toBe(true)
    if (!result.ok || result.signal.kind !== 'protectedMode') {
      throw new Error('expected a protectedMode signal')
    }
    expect(result.signal.state).toEqual({
      acceptsPass: false,
      passRejected: false,
      active: false,
      operation: undefined,
      title: undefined,
      message: undefined,
    })
  })

  it('is rejected when it cannot say whether the mode is on', () => {
    const result = parseSignal(
      JSON.stringify({ type: 'protected_mode', data: { title: 'Oops' } }),
    )

    expect(result.ok).toBe(false)
    if (result.ok) {
      throw new Error('expected a parse failure')
    }
    expect(result.failure.kind).toBe('invalid-signal-data')
  })
})

describe('the client-side pieces', () => {
  it('treats an absent block as no freeze', () => {
    expect(toProtectedModeStatus(undefined).active).toBe(false)
  })

  it('keeps a last-resort sentence for a freeze that arrived wordless', () => {
    expect(PROTECTED_MODE_FALLBACK_COPY.title).not.toBe('')
    expect(PROTECTED_MODE_FALLBACK_COPY.message).not.toBe('')
  })
})

describe('the window marker on the block', () => {
  it('is read off the frame that carries it', () => {
    const status = toProtectedModeStatus({ active: true, acceptsPass: true })

    expect(status.acceptsPass).toBe(true)
  })

  it('is false on a block that does not mention it', () => {
    // The frozen phases send no marker at all, and neither did the daemon before
    // the verification window existed: absent must read as "no code will help".
    const status = toProtectedModeStatus({ active: true })

    expect(status.acceptsPass).toBe(false)
  })

  it('never arrives from the wire as a rejection', () => {
    // A frame describes the node; whether THIS client's key was refused is the
    // connection's own conclusion, and a block must not be able to assert it.
    const status = toProtectedModeStatus({
      active: true,
      acceptsPass: true,
      passRejected: true,
    })

    expect(status.passRejected).toBe(false)
  })
})
