import { describe, expect, it } from 'vitest'

import { authConvergeSignalSchema } from '../../src/auth/authConvergeSignals.js'

const converge = {
  acceptKey: 'waiting-tab',
  identifier: 'ada@example.com',
  step: 'identifier',
  intent: 'login',
  code: 'identifier_taken',
}

describe('auth converge message', () => {
  it('preserves arbitrary server words beside the reason and target', () => {
    const frame = {
      ...converge,
      message: 'A server-authored notice with Unicode: déjà vu.',
    }
    expect(authConvergeSignalSchema.parse(frame)).toEqual(frame)
  })

  it('requires explicit null words while still allowing omitted code', () => {
    const quiet = {
      acceptKey: 'waiting-tab',
      identifier: 'ada@example.com',
      step: 'done',
      intent: 'register',
      message: null,
    }
    expect(authConvergeSignalSchema.parse(quiet)).toEqual({
      ...quiet,
      code: null,
    })
  })

  it('rejects a missing message', () => {
    expect(authConvergeSignalSchema.safeParse(converge).success).toBe(false)
  })

  it.each([undefined, 42, false, [], {}])(
    'rejects a message of the wrong type: %j',
    (message) => {
      expect(
        authConvergeSignalSchema.safeParse({ ...converge, message }).success,
      ).toBe(false)
    },
  )
})
