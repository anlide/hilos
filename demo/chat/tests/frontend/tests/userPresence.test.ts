import { describe, expect, it } from 'vitest'
import { userPresenceUpdate } from '@/signals/userPresence'

describe('userPresenceUpdate', () => {
  it('uses the plain presence signal dispatch key', () => {
    expect(userPresenceUpdate.dispatchKey).toBe('user_presence_update')
  })

  it('accepts an entity envelope with user presence', () => {
    expect(userPresenceUpdate.parse({
      entities: {
        full: {
          users: [{ id: 7, name: 'Ada', presence: 'online' }],
        },
      },
    })).toBeUndefined()
  })

  it('rejects malformed payloads', () => {
    expect(userPresenceUpdate.parse(null)).toBeNull()
    expect(userPresenceUpdate.parse([])).toBeNull()
    expect(userPresenceUpdate.parse({})).toBeNull()
    expect(userPresenceUpdate.parse({ entities: [] })).toBeNull()
  })
})
