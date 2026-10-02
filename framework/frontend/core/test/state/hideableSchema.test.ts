import { describe, expect, it } from 'vitest'
import { z } from 'zod'
import { HIDDEN_VALUE } from '../../src/state/hiddenValue.js'
import { hideable } from '../../src/state/hideableSchema.js'

describe('hideable schema combinator', () => {
  it('transforms the hidden value mark to the canonical HIDDEN_VALUE symbol', () => {
    const schema = hideable(z.string())
    const parsed = schema.parse({ _hidden: true })
    expect(Object.is(parsed, HIDDEN_VALUE)).toBe(true)
  })

  it('passes through strings, nulls, and records according to the inner schema', () => {
    const stringSchema = hideable(z.string())
    expect(stringSchema.parse('hello')).toBe('hello')

    const nullableSchema = hideable(z.string().nullable())
    expect(nullableSchema.parse(null)).toBeNull()
    expect(nullableSchema.parse('world')).toBe('world')

    const recordSchema = hideable(z.record(z.string(), z.unknown()))
    expect(recordSchema.parse({ key: 123 })).toEqual({ key: 123 })
  })

  it('correctly parses a mark instead of letting z.record swallow {_hidden: true} as a record', () => {
    const recordSchema = hideable(z.record(z.string(), z.unknown()))
    const parsed = recordSchema.parse({ _hidden: true })
    expect(Object.is(parsed, HIDDEN_VALUE)).toBe(true)
  })

  it('rejects invalid inputs when not a mark', () => {
    const numberSchema = hideable(z.number())
    expect(() => numberSchema.parse('not a number')).toThrow()
  })
})
