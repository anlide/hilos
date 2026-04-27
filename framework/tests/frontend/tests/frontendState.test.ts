import { describe, expect, it } from 'vitest'
import { extractFrontendChangesEnvelope, hasFrontendChanges } from '@/types'

describe('frontend state envelope', () => {
  it('extracts full, updates, deleted, and replaceFull collections', () => {
    const envelope = extractFrontendChangesEnvelope({
      frontend: {
        full: { users: [{ id: 1 }] },
        updates: { userPresence: [{ userId: 1, presence: 'online' }] },
        deleted: { userConnectionStats: [1] },
        replaceFull: ['users'],
      },
    })

    expect(hasFrontendChanges({ frontend: {} })).toBe(true)
    expect(envelope).toEqual({
      full: { users: [{ id: 1 }] },
      updates: { userPresence: [{ userId: 1, presence: 'online' }] },
      deleted: { userConnectionStats: [1] },
      replaceFull: ['users'],
    })
  })

  it('rejects malformed frontend envelopes', () => {
    expect(extractFrontendChangesEnvelope(null)).toBeNull()
    expect(extractFrontendChangesEnvelope({})).toBeNull()
    expect(extractFrontendChangesEnvelope({ frontend: [] })).toBeNull()
    expect(hasFrontendChanges({ frontend: [] })).toBe(false)
  })
})
