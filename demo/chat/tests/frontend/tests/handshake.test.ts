import { describe, expect, it } from 'vitest'
import { handshakeResponse } from '@/signals/handshake'

describe('handshakeResponse', () => {
  it('parses the current user id and page catalog', () => {
    const pageCatalog = {
      main: {
        label: 'Main',
        pathTemplate: '/',
      },
    }

    expect(handshakeResponse.parse({
      self: { id: 7 },
      pageCatalog,
    })).toEqual({
      self: { id: 7 },
      pageCatalog,
    })
  })

  it('normalizes a missing page catalog to null', () => {
    expect(handshakeResponse.parse({
      self: { id: 7 },
    })).toEqual({
      self: { id: 7 },
      pageCatalog: null,
    })
  })

  it('rejects malformed self payloads', () => {
    expect(handshakeResponse.parse({ self: { id: '7' } })).toBeNull()
    expect(handshakeResponse.parse({ self: { id: 0 } })).toBeNull()
    expect(handshakeResponse.parse({ self: null })).toBeNull()
  })

  it('rejects the old frontend-only payload shape', () => {
    expect(handshakeResponse.parse({
      frontend: {
        full: {
          users: [{ id: 7, name: 'Ada' }],
        },
      },
    })).toBeNull()
  })
})
