import { describe, expect, it } from 'vitest'
import {
  scopePayloadSchema,
  type ScopePayloadWire,
} from '../../src/protocol/scopePayload.js'
import { type ScopePayload } from '../../src/state/normalizer.js'

describe('scopePayloadSchema', () => {
  it('accepts a single-fragment slot plus plain data', () => {
    const result = scopePayloadSchema.safeParse({
      entities: { currentUser: { id: 7, name: 'User7' } },
      data: { theme: 'dark' },
    })
    expect(result.success).toBe(true)
    if (result.success) {
      expect(result.data.entities?.currentUser).toEqual({
        id: 7,
        name: 'User7',
      })
      expect(result.data.data?.theme).toBe('dark')
    }
  })

  it('accepts fragment lists and string ids', () => {
    const result = scopePayloadSchema.safeParse({
      entities: { users: [{ id: '1' }, { id: '2', name: 'B' }] },
    })
    expect(result.success).toBe(true)
  })

  it('accepts omitted sections and tolerates extra payload keys', () => {
    // Empty sections are omitted on the wire; unknown keys (e.g. a future
    // tables section) must survive the schema.
    expect(scopePayloadSchema.safeParse({}).success).toBe(true)
    expect(scopePayloadSchema.safeParse({ tables: {} }).success).toBe(true)
  })

  it('rejects a fragment without a stable id', () => {
    const result = scopePayloadSchema.safeParse({
      entities: { currentUser: { name: 'NoId' } },
    })
    expect(result.success).toBe(false)
  })

  it('stays assignable to the normalizer ScopePayload type', () => {
    const wire: ScopePayloadWire = {
      entities: { currentUser: { id: 7 } },
      data: { route: '/' },
    }
    // Compile-time alignment: the wire schema's output feeds ingest() as-is.
    const payload: ScopePayload = wire
    expect(payload).toBe(wire)
  })
})
