import { describe, expect, it } from 'vitest'
import { parseUserPayloads } from '@/entities/parsers'
import { parsePartialUserPayloads } from '@/entities/partialUserPayload'

describe('user entity payload parsers', () => {
  it('accepts generic user entity payloads without runtime counters', () => {
    expect(parseUserPayloads([{ id: 7, name: 'Ada', lastActivity: null, presence: 'online' }])).toEqual([
      { id: 7, name: 'Ada', lastActivity: null, presence: 'online' },
    ])
  })

  it('rejects onlineSessionCount in full user entity payloads', () => {
    expect(parseUserPayloads([{ id: 7, name: 'Ada', onlineSessionCount: 2 }])).toBeNull()
  })

  it('rejects onlineSessionCount in partial user entity payloads', () => {
    expect(parsePartialUserPayloads([{ id: 7, presence: 'online', onlineSessionCount: 2 }])).toBeNull()
  })
})
