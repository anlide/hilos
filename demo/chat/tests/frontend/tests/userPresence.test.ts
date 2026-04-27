import { describe, expect, it } from 'vitest'
import { userPresenceUpdate } from '@/signals/userPresence'

describe('userPresenceUpdate', () => {
  it('uses the plain presence signal dispatch key', () => {
    expect(userPresenceUpdate.dispatchKey).toBe('user_presence_update')
  })

  it('accepts a frontend state envelope with user presence', () => {
    expect(userPresenceUpdate.parse({
      frontend: {
        updates: {
          userPresence: [{ userId: 7, presence: 'online' }],
        },
      },
    })).toBeUndefined()
  })

  it('accepts a frontend state envelope with user presence and connection stats', () => {
    expect(userPresenceUpdate.parse({
      frontend: {
        updates: {
          userPresence: [{ userId: 7, presence: 'online' }],
          userConnectionStats: [{ userId: 7, onlineSessionCount: 2 }],
        },
      },
    })).toBeUndefined()
  })

  it('rejects malformed payloads', () => {
    expect(userPresenceUpdate.parse(null)).toBeNull()
    expect(userPresenceUpdate.parse([])).toBeNull()
    expect(userPresenceUpdate.parse({})).toBeNull()
    expect(userPresenceUpdate.parse({ frontend: [] })).toBeNull()
    expect(userPresenceUpdate.parse({ frontend: { updates: { userPresence: [{ userId: 7, presence: 'away' }] } } })).toBeNull()
  })
})
