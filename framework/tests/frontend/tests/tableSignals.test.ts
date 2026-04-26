import { describe, expect, it } from 'vitest'
import { tableActionError, tableMutation } from '@/signals/tableSignals'

/**
 * Infrastructure-level unit tests for the framework signal parsers.
 *
 * These pin down the wire-format contract of `table_mutation` and
 * `table_action_error`: signals parsed into typed payloads, or `null`
 * when the shape is invalid (router logs and skips on `null`).
 */
describe('tableMutation parser', () => {
  it.each(['create', 'update', 'delete'] as const)('parses %s mutation payloads', (type) => {
    const parsed = tableMutation.parse({
      tableKey: 'users',
      mutation: { type, rowKey: 42, row: { id: 42, name: 'Ada' } },
    })

    expect(parsed).toEqual({
      tableKey: 'users',
      mutation: { type, rowKey: 42, row: { id: 42, name: 'Ada' } },
    })
  })

  it('accepts mutations without the optional row snapshot', () => {
    const parsed = tableMutation.parse({
      tableKey: 'users',
      mutation: { type: 'delete', rowKey: 'abc' },
    })

    expect(parsed).toEqual({
      tableKey: 'users',
      mutation: { type: 'delete', rowKey: 'abc' },
    })
  })

  it('drops routing metadata from row mutation payloads', () => {
    const parsed = tableMutation.parse({
      tableKey: 'users',
      mutation: { type: 'update', rowKey: 42, row: { id: 42 }, acceptKey: 'client-a' },
    })

    expect(parsed).toEqual({
      tableKey: 'users',
      mutation: { type: 'update', rowKey: 42, row: { id: 42 } },
    })
  })

  it('returns null when mutation.type is not a known enum value', () => {
    const parsed = tableMutation.parse({
      tableKey: 'users',
      mutation: { type: 'moved', rowKey: 1 },
    })

    expect(parsed).toBeNull()
  })

  it('returns null when tableKey is missing', () => {
    const parsed = tableMutation.parse({
      mutation: { type: 'create', rowKey: 1 },
    })

    expect(parsed).toBeNull()
  })

  it('returns null on completely malformed input', () => {
    expect(tableMutation.parse(null)).toBeNull()
    expect(tableMutation.parse('not an object')).toBeNull()
    expect(tableMutation.parse([])).toBeNull()
  })
})

describe('tableActionError parser', () => {
  it('parses payload with optional message', () => {
    const parsed = tableActionError.parse({ tableKey: 'users', message: 'DB error' })

    expect(parsed).toEqual({ tableKey: 'users', message: 'DB error' })
  })

  it('parses payload without optional message', () => {
    const parsed = tableActionError.parse({ tableKey: 'users' })

    expect(parsed).toEqual({ tableKey: 'users' })
  })

  it('returns null when tableKey is missing', () => {
    expect(tableActionError.parse({ message: 'oops' })).toBeNull()
  })
})
