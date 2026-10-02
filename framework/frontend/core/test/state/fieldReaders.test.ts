import { describe, expect, it } from 'vitest'
import {
  readBoolean,
  readHideableBoolean,
  readHideableString,
  readHideableStringOrNull,
  readNumber,
  readNumberOrNull,
  readString,
  readStringOrNull,
} from '../../src/state/fieldReaders.js'
import { HIDDEN_VALUE } from '../../src/state/hiddenValue.js'

describe('field readers', () => {
  it('readString returns the string, or empty when absent or non-string', () => {
    expect(readString({ name: 'Iris' }, 'name')).toBe('Iris')
    expect(readString({}, 'name')).toBe('')
    expect(readString({ name: 7 }, 'name')).toBe('')
  })

  it('readStringOrNull returns the string, or null when absent or non-string', () => {
    expect(readStringOrNull({ note: 'hi' }, 'note')).toBe('hi')
    expect(readStringOrNull({}, 'note')).toBeNull()
    expect(readStringOrNull({ note: 7 }, 'note')).toBeNull()
  })

  it('readHideableString hands the one hidden value out for the mark, else reads as readString', () => {
    expect(readHideableString({ name: { _hidden: true } }, 'name')).toBe(
      HIDDEN_VALUE,
    )
    expect(readHideableString({ name: 'Iris' }, 'name')).toBe('Iris')
    expect(readHideableString({}, 'name')).toBe('')
    expect(readHideableString({ name: { _hidden: false } }, 'name')).toBe('')
  })

  it('readHideableStringOrNull hands the one hidden value out for the mark, else reads as readStringOrNull', () => {
    expect(readHideableStringOrNull({ note: { _hidden: true } }, 'note')).toBe(
      HIDDEN_VALUE,
    )
    expect(readHideableStringOrNull({ note: 'hi' }, 'note')).toBe('hi')
    expect(readHideableStringOrNull({ note: null }, 'note')).toBeNull()
    expect(readHideableStringOrNull({}, 'note')).toBeNull()
  })

  it('readNumber returns the number, or zero when absent or non-numeric', () => {
    expect(readNumber({ id: 7 }, 'id')).toBe(7)
    expect(readNumber({}, 'id')).toBe(0)
    expect(readNumber({ id: '7' }, 'id')).toBe(0)
  })

  it('readNumberOrNull returns the number, or null when absent or non-numeric', () => {
    expect(readNumberOrNull({ size: 3 }, 'size')).toBe(3)
    expect(readNumberOrNull({}, 'size')).toBeNull()
    expect(readNumberOrNull({ size: '3' }, 'size')).toBeNull()
  })

  it('readBoolean is true only when the value is exactly true', () => {
    expect(readBoolean({ active: true }, 'active')).toBe(true)
    expect(readBoolean({ active: false }, 'active')).toBe(false)
    expect(readBoolean({ active: 1 }, 'active')).toBe(false)
    expect(readBoolean({}, 'active')).toBe(false)
  })

  it('readHideableBoolean hands the one hidden value out for the mark, else reads as readBoolean', () => {
    expect(readHideableBoolean({ active: { _hidden: true } }, 'active')).toBe(
      HIDDEN_VALUE,
    )
    expect(readHideableBoolean({ active: true }, 'active')).toBe(true)
    expect(readHideableBoolean({ active: false }, 'active')).toBe(false)
    expect(readHideableBoolean({ active: 1 }, 'active')).toBe(false)
    expect(readHideableBoolean({}, 'active')).toBe(false)
  })
})
