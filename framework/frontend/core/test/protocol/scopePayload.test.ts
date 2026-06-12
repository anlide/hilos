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

  it('accepts a list section with items and deletes', () => {
    const result = scopePayloadSchema.safeParse({
      lists: {
        events: {
          items: [
            { itemKey: 1, slots: { db_event: { id: 1 }, message: 'hi' } },
          ],
          deleted: [2, '3'],
        },
        users: { items: [{ itemKey: '7', slots: {} }] },
      },
    })
    expect(result.success).toBe(true)
    if (result.success) {
      expect(result.data.lists?.events?.items?.[0]?.itemKey).toBe(1)
      expect(result.data.lists?.events?.deleted).toEqual([2, '3'])
    }
  })

  it('rejects a list item without an itemKey', () => {
    const result = scopePayloadSchema.safeParse({
      lists: { events: { items: [{ slots: {} }] } },
    })
    expect(result.success).toBe(false)
  })

  it('accepts an empty list section serialized as a JSON array', () => {
    // PHP serializes an empty map as `[]`; an empty list section arrives that
    // way beside non-empty ones and must not fail the whole payload.
    const result = scopePayloadSchema.safeParse({
      lists: {
        users: { items: [{ itemKey: 1, slots: {} }] },
        bots: [],
      },
    })
    expect(result.success).toBe(true)
    if (result.success) {
      expect(result.data.lists?.bots).toEqual({})
      expect(result.data.lists?.users?.items?.[0]?.itemKey).toBe(1)
    }
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
      lists: { events: { items: [{ itemKey: 1, slots: {} }], deleted: [2] } },
    }
    // Compile-time alignment: the wire schema's output feeds ingest() as-is.
    const payload: ScopePayload = wire
    expect(payload).toBe(wire)
  })
})
