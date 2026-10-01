import { describe, expect, it } from 'vitest'
import { HIDDEN_VALUE, isHiddenValue } from '../../src/state/hiddenValue.js'

describe('hidden value', () => {
  it('recognizes the mark the server writes in place of a hidden value', () => {
    expect(isHiddenValue({ _hidden: true })).toBe(true)
    expect(isHiddenValue(HIDDEN_VALUE)).toBe(true)
  })

  it.each([
    ['a mark with another key', { _hidden: true, x: 1 }],
    ['a mark set to false', { _hidden: false }],
    ['a mark set to a truthy non-boolean', { _hidden: 1 }],
    ['an empty object', {}],
    ['an array', []],
    ['null', null],
    ['undefined', undefined],
    ['the empty string', ''],
    ['a string', 'Hidden'],
  ])('takes %s for a value', (_what, value) => {
    expect(isHiddenValue(value)).toBe(false)
  })

  it('is one frozen instance', () => {
    expect(Object.isFrozen(HIDDEN_VALUE)).toBe(true)
    expect(HIDDEN_VALUE).toEqual({ _hidden: true })
  })
})
