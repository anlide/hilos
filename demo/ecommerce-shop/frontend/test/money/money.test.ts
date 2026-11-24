import { describe, expect, it } from 'vitest'

import cases from '../../../tests/money-cases.json'
import {
  discountOf,
  formatDeduction,
  formatMoney,
  lineTotal,
  moneyFieldLabel,
  moneyInputComparisonText,
  moneyInputText,
  sumMoney,
  totalAfterDiscount,
} from '../../src/money/money.js'

// The table the backend MoneyTest reads too, so both sides count and print alike.
const table = cases as {
  format: [number, string][]
  lineTotal: [number, number, number][]
  sum: [number[], number][]
  discountOf: [number, number, number][]
  totalAfterDiscount: [number, number, number][]
}

describe('money', () => {
  it.each(table.format)('formatMoney(%i) prints %s', (kopecks, printed) => {
    expect(formatMoney(kopecks)).toBe(printed)
  })

  it.each([
    ['0', '₴0'],
    ['5', '₴0.05'],
    ['10', '₴0.10'],
    ['100000', '₴1,000'],
    ['9099990809000091', '₴90,999,908,090,000.91'],
    ['9999999999999999', '₴99,999,999,999,999.99'],
    ['9318390588416093184', '₴93,183,905,884,160,931.84'],
  ])('formatMoney(%s) prints the exact amount %s', (kopecks, printed) => {
    expect(formatMoney(kopecks)).toBe(printed)
  })

  it.each(['', '00', '-1', '1.5', '1e3', '1\n'])(
    'rejects a noncanonical amount %j',
    (kopecks) => {
      expect(() => formatMoney(kopecks)).toThrow(RangeError)
    },
  )

  it.each(table.lineTotal)(
    'lineTotal(%i, %i) is %i',
    (price, quantity, total) => {
      expect(lineTotal(price, quantity)).toBe(total)
    },
  )

  it.each(table.sum)('sumMoney(%j) is %i', (amounts, sum) => {
    expect(sumMoney(amounts)).toBe(sum)
  })

  it.each(table.discountOf)(
    'discountOf(%i, %i) is %i',
    (items, percent, discount) => {
      expect(discountOf(items, percent)).toBe(discount)
    },
  )

  it.each(table.totalAfterDiscount)(
    'totalAfterDiscount(%i, %i) is %i',
    (items, discount, total) => {
      expect(totalAfterDiscount(items, discount)).toBe(total)
    },
  )

  it('prints a deduction with the minus sign U+2212', () => {
    expect(formatDeduction(25000)).toBe('−₴250')
  })

  it.each([
    [41000, '410'],
    [210000, '2 100'],
    [210050, '2 100.50'],
    [5, '0.05'],
  ])('moneyInputText(%i) writes %s', (kopecks, text) => {
    expect(moneyInputText(kopecks)).toBe(text)
  })

  it.each([
    ['00,00', '0'],
    ['000.5', '0.50'],
    ['999999999999999,98', '999 999 999 999 999.98'],
    ['999999999999999,99', '999 999 999 999 999.99'],
    ['1000000000000000,99', '1000000000000000,99'],
  ])('compares the exact input amount %s as %s', (text, comparable) => {
    expect(moneyInputComparisonText(text)).toBe(comparable)
  })

  it('names a money field with the sign', () => {
    expect(moneyFieldLabel('Price')).toBe('Price, ₴')
  })

  it.each([
    ['formatMoney(-1)', () => formatMoney(-1)],
    ['formatMoney(1.5)', () => formatMoney(1.5)],
    ['discountOf(100, 101)', () => discountOf(100, 101)],
    [
      'lineTotal(Number.MAX_SAFE_INTEGER, 2)',
      () => lineTotal(Number.MAX_SAFE_INTEGER, 2),
    ],
  ])('%s breaks a precondition', (_call, call) => {
    expect(call).toThrow(RangeError)
  })
})
