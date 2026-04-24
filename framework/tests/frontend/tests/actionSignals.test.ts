import { describe, expect, it } from 'vitest'
import { actionError } from '@/signals/actionSignals'

describe('actionError parser', () => {
  it('carries the "fail" outcome marker', () => {
    expect(actionError.outcome).toBe('fail')
  })

  it('parses a valid action error payload', () => {
    expect(actionError.parse({ action: 'message', reason: 'Message cannot be empty' })).toEqual({
      action: 'message',
      reason: 'Message cannot be empty',
    })
  })

  it('returns null for malformed payloads', () => {
    expect(actionError.parse(null)).toBeNull()
    expect(actionError.parse([])).toBeNull()
    expect(actionError.parse({ action: 'message' })).toBeNull()
    expect(actionError.parse({ reason: 'Message cannot be empty' })).toBeNull()
    expect(actionError.parse({ action: 42, reason: 'Message cannot be empty' })).toBeNull()
    expect(actionError.parse({ action: 'message', reason: 42 })).toBeNull()
  })

  it('uses a dispatch key that includes the outcome', () => {
    expect(actionError.dispatchKey).toBe('action_error::fail')
  })
})
