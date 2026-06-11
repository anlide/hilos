import { describe, expect, it } from 'vitest'
import {
  pageResponseSchema,
  scopePayloadSchema,
  type ScopePayloadWire,
} from './scopePayload.js'
import { type ScopePayload } from '../state/normalizer.js'

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
    // section) must survive the schema.
    expect(scopePayloadSchema.safeParse({}).success).toBe(true)
    expect(scopePayloadSchema.safeParse({ tables: {} }).success).toBe(true)
    expect(scopePayloadSchema.safeParse({ lists: {} }).success).toBe(true)
  })

  it('accepts a tables section of rowKey+slots rows', () => {
    const result = scopePayloadSchema.safeParse({
      tables: {
        users: {
          rows: [
            { rowKey: 1, slots: { user: { id: 1, name: 'Ann' } } },
            { rowKey: '2', slots: { user: { id: '2' }, online: true } },
          ],
        },
      },
    })
    expect(result.success).toBe(true)
  })

  it('rejects a table row without a rowKey', () => {
    const result = scopePayloadSchema.safeParse({
      tables: { users: { rows: [{ slots: {} }] } },
    })
    expect(result.success).toBe(false)
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
      tables: { users: { rows: [{ rowKey: 7, slots: { user: { id: 7 } } }] } },
    }
    // Compile-time alignment: the wire schema's output feeds ingest() as-is.
    const payload: ScopePayload = wire
    expect(payload).toBe(wire)
  })
})

describe('pageResponseSchema', () => {
  it('accepts a page key plus a scope payload', () => {
    const result = pageResponseSchema.safeParse({
      page: 'main',
      payload: {
        tables: {
          users: { rows: [{ rowKey: 1, slots: { user: { id: 1 } } }] },
        },
      },
    })
    expect(result.success).toBe(true)
    if (result.success) {
      expect(result.data.page).toBe('main')
    }
  })

  it('rejects a missing or empty page key', () => {
    expect(pageResponseSchema.safeParse({ payload: {} }).success).toBe(false)
    expect(
      pageResponseSchema.safeParse({ page: '', payload: {} }).success,
    ).toBe(false)
  })
})
