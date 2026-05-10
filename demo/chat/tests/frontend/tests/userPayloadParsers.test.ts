import { describe, expect, it } from 'vitest'
import { parseUserPayloads } from '@/entities/parsers'
import { parsePartialUserPayloads } from '@/entities/partialUserPayload'

describe('user frontend payload parsers', () => {
  it('accepts public user payloads without runtime fields', () => {
    expect(parseUserPayloads([{ id: 7, name: 'Ada', lastActivity: null }])).toEqual([
      { id: 7, name: 'Ada', lastActivity: null },
    ])
  })

  it('rejects onlineSessionCount in full user payloads', () => {
    expect(parseUserPayloads([{ id: 7, name: 'Ada', onlineSessionCount: 2 }])).toBeNull()
  })

  it('rejects onlineSessionCount in partial user payloads', () => {
    expect(parsePartialUserPayloads([{ id: 7, presence: 'online', onlineSessionCount: 2 }])).toBeNull()
  })

  it('rejects runtime and private fields in public user payloads', () => {
    expect(parseUserPayloads([{ id: 7, name: 'Ada', presence: 'online' }])).toBeNull()
    expect(parseUserPayloads([{ id: 7, name: 'Ada', sessionToken: 'secret' }])).toBeNull()
    expect(parseUserPayloads([{ id: 7, name: 'Ada', moderationState: 'pending' }])).toBeNull()
    expect(parsePartialUserPayloads([{ id: 7, presence: 'online' }])).toBeNull()
  })
})
