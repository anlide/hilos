import { describe, expect, it } from 'vitest'
import { hilosUserUpdateFail, hilosUserUpdateSuccess } from '@/signals/hilosUserUpdate'

/**
 * Unit tests for the `hilos_user_update_{success,fail}` ack signals.
 *
 * These mirror the backend DTO contract tests in
 * {@see \Demo\Chat\Tests\Unit\ActionSuccessSignalDataTest} and
 * {@see \Demo\Chat\Tests\Unit\ActionFailSignalDataTest} — the goal is to
 * pin down the wire shape on both sides of the worker→daemon→WS boundary.
 */
describe('hilosUserUpdateSuccess', () => {
  it('carries the "success" outcome marker', () => {
    expect(hilosUserUpdateSuccess.outcome).toBe('success')
  })

  it('accepts an empty body and returns undefined', () => {
    expect(hilosUserUpdateSuccess.parse({})).toBeUndefined()
    expect(hilosUserUpdateSuccess.parse(undefined)).toBeUndefined()
    expect(hilosUserUpdateSuccess.parse(null)).toBeUndefined()
  })

  it('uses a dispatch key that includes the outcome', () => {
    expect(hilosUserUpdateSuccess.dispatchKey).toBe('hilos_user_update_success::success')
  })
})

describe('hilosUserUpdateFail', () => {
  it('carries the "fail" outcome marker', () => {
    expect(hilosUserUpdateFail.outcome).toBe('fail')
  })

  it('parses a well-formed fail payload', () => {
    const parsed = hilosUserUpdateFail.parse({ reason: 'No such user' })

    expect(parsed).toEqual({ reason: 'No such user' })
  })

  it.each(['Invalid user id', 'User name cannot be empty', 'No such user'] as const)(
    'accepts failure reason %s',
    (reason) => {
      const parsed = hilosUserUpdateFail.parse({ reason })
      expect(parsed).toEqual({ reason })
    },
  )

  it('returns null when reason is missing or not a string', () => {
    expect(hilosUserUpdateFail.parse({ message: 'No such user' })).toBeNull()
    expect(hilosUserUpdateFail.parse({ reason: 42 })).toBeNull()
  })

  it('returns null for completely malformed input', () => {
    expect(hilosUserUpdateFail.parse(null)).toBeNull()
    expect(hilosUserUpdateFail.parse('string')).toBeNull()
    expect(hilosUserUpdateFail.parse([])).toBeNull()
  })

  it('uses a dispatch key that includes the outcome', () => {
    expect(hilosUserUpdateFail.dispatchKey).toBe('hilos_user_update_fail::fail')
  })
})
