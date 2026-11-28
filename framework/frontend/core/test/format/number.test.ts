import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { formatNumber } from '../../src/format/number.js'
import { bindReader, type ReaderFixture } from './readerFixture.js'

/** The no-break space a template's space is printed as. */
const NBSP = ' '

let reader: ReaderFixture

beforeEach(() => {
  reader = bindReader()
})

afterEach(() => {
  reader.release()
})

describe('formatNumber', () => {
  it.each([
    ['1,000.00', '1,234,567', '1,234.5', '-1,234.568', '0.001'],
    [
      '1 000,00',
      `1${NBSP}234${NBSP}567`,
      `1${NBSP}234,5`,
      `-1${NBSP}234,568`,
      '0,001',
    ],
    [
      '1 000.00',
      `1${NBSP}234${NBSP}567`,
      `1${NBSP}234.5`,
      `-1${NBSP}234.568`,
      '0.001',
    ],
    ['1.000,00', '1.234.567', '1.234,5', '-1.234,568', '0,001'],
  ])(
    'writes numbers in the %s template',
    (number, integer, fraction, negative, small) => {
      reader.region({ number })
      expect(formatNumber(1234567)).toBe(integer)
      expect(formatNumber(1234.5)).toBe(fraction)
      expect(formatNumber(-1234.5678)).toBe(negative)
      expect(formatNumber(0.001)).toBe(small)
    },
  )

  it('prints the template space as a no-break space', () => {
    reader.region({ number: '1 000,00' })
    expect(formatNumber(1000)).toBe('1 000')
    expect(formatNumber(1000)).not.toContain(' ')
  })

  it('writes the built-in separators before the tab knows a region', () => {
    expect(formatNumber(1234.5)).toBe('1,234.5')
    reader.region({ number: '1.000,00' })
    expect(formatNumber(1234.5)).toBe('1.234,5')
    reader.region(null)
    expect(formatNumber(1234.5)).toBe('1,234.5')
  })

  it.each([
    [999, '999'],
    [1000, '1,000'],
    [0, '0'],
    [-0, '0'],
    [-7, '-7'],
    [1e21, '1,000,000,000,000,000,000,000'],
  ])('writes the whole number %s', (value, expected) => {
    expect(formatNumber(value)).toBe(expected)
  })

  it.each([
    [1.0005, '1.001'],
    [1.0004, '1'],
    [2.5, '2.5'],
    [0.0005, '0.001'],
    [0.0004, '0'],
    [-0.0004, '0'],
    [-0.0005, '-0.001'],
    [9.9995, '10'],
    [999.9995, '1,000'],
  ])(
    'rounds %s half up by magnitude at three fraction digits',
    (value, expected) => {
      expect(formatNumber(value)).toBe(expected)
    },
  )

  it('rounds the number as it is written, not its binary neighbor', () => {
    expect(formatNumber(1.005, { maximumFractionDigits: 2 })).toBe('1.01')
    expect(formatNumber(-1.005, { maximumFractionDigits: 2 })).toBe('-1.01')
  })

  it('keeps fraction digits between the minimum and the maximum', () => {
    expect(formatNumber(1.5, { minimumFractionDigits: 2 })).toBe('1.50')
    expect(
      formatNumber(1, { minimumFractionDigits: 1, maximumFractionDigits: 1 }),
    ).toBe('1.0')
    expect(formatNumber(1.25, { maximumFractionDigits: 1 })).toBe('1.3')
    expect(formatNumber(1.234, { maximumFractionDigits: 0 })).toBe('1')
    expect(formatNumber(0.5, { maximumFractionDigits: 0 })).toBe('1')
    expect(formatNumber(1.23456, { minimumFractionDigits: 5 })).toBe('1.23456')
    expect(formatNumber(12.3, { maximumFractionDigits: 2 })).toBe('12.3')
  })

  it.each([
    [{ minimumFractionDigits: 2, maximumFractionDigits: 1 }],
    [{ minimumFractionDigits: -1 }],
    [{ maximumFractionDigits: 1.5 }],
  ])('refuses fraction digits %o', (options) => {
    expect(() => formatNumber(1, options)).toThrow(RangeError)
  })

  it.each([
    [999, '999'],
    [1000, '1K'],
    [1234, '1.2K'],
    [1250, '1.3K'],
    [12345, '12.3K'],
    [999949, '999.9K'],
    [999950, '1M'],
    [1500000, '1.5M'],
    [2e9, '2B'],
    [1234e9, '1,234B'],
    [-1234, '-1.2K'],
    [12.34, '12.3'],
  ])('writes %s compactly', (value, expected) => {
    expect(formatNumber(value, { compact: true })).toBe(expected)
  })

  it('writes the compact fraction with the region separator', () => {
    reader.region({ number: '1.000,00' })
    expect(formatNumber(1234, { compact: true })).toBe('1,2K')
    expect(formatNumber(1234e9, { compact: true })).toBe('1.234B')
  })

  it.each([NaN, Infinity, -Infinity])('writes %s as String(value)', (value) => {
    expect(formatNumber(value)).toBe(String(value))
  })
})
