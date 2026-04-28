import { describe, expect, it } from 'vitest'
import { botJoined, botLeft } from '@/signals/botEvents'

describe('bot presence signals', () => {
  it('accepts bot joined frontend state payloads', () => {
    expect(botJoined.parse({
      frontend: {
        updates: {
          botPresence: [{ botId: 9, presence: 'online' }],
        },
      },
    })).toEqual({ botId: 9, presence: 'online' })
  })

  it('accepts bot left frontend state payloads', () => {
    expect(botLeft.parse({
      frontend: {
        updates: {
          botPresence: [{ botId: 9, presence: 'offline' }],
        },
      },
    })).toEqual({ botId: 9, presence: 'offline' })
  })

  it('rejects malformed bot presence payloads', () => {
    expect(botJoined.parse(null)).toBeNull()
    expect(botJoined.parse({})).toBeNull()
    expect(botJoined.parse({ frontend: { updates: { botPresence: [{ botId: 9, presence: 'away' }] } } })).toBeNull()
    expect(botJoined.parse({ frontend: { updates: { botPresence: [{ botId: 9, presence: 'offline' }] } } })).toBeNull()
  })
})
