import { describe, expect, expectTypeOf, it } from 'vitest'
import { type HilosTableColumnOf } from '../../src/table/hilosTableColumn.js'
import {
  hilosTableRowHeight,
  hilosTableTextClass,
  type HilosTableLineCount,
  type HilosTableRowHeight,
  type HilosTableTextRem,
} from '../../src/table/tableTextHeight.js'

describe('table text height catalog', () => {
  it('defaults only omission to intrinsic content, without a preset class', () => {
    expect(hilosTableRowHeight(undefined)).toEqual({ mode: 'content' })
    expect(hilosTableRowHeight({ mode: 'content' })).toEqual({
      mode: 'content',
    })
    expect(hilosTableTextClass(hilosTableRowHeight(undefined))).toBe(
      'hilos-table-text',
    )
  })

  it.each([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12])(
    'accepts exactly %i lines and its Sass token',
    (count) => {
      const policy = hilosTableRowHeight({ mode: 'lines', count })
      expect(policy).toEqual({ mode: 'lines', count })
      expect(hilosTableTextClass(policy)).toBe(
        `hilos-table-text hilos-table-text-lines-${count}`,
      )
    },
  )

  it.each([
    [2, '2'],
    [3, '3'],
    [4.5, '4-5'],
    [6, '6'],
    [9, '9'],
    [12, '12'],
  ])('accepts %s rem with the explicit token %s', (rem, token) => {
    const policy = hilosTableRowHeight({ mode: 'height', rem })
    expect(policy).toEqual({ mode: 'height', rem })
    expect(hilosTableTextClass(policy)).toBe(
      `hilos-table-text hilos-table-text-height-${token}`,
    )
  })

  it.each([
    null,
    false,
    true,
    0,
    3,
    NaN,
    Infinity,
    'content',
    '3rem',
    [],
    [{ mode: 'content' }],
    () => ({ mode: 'content' }),
    {},
    { count: 3 },
    { mode: undefined },
    { mode: 'unknown' },
    { mode: 'CONTENT' },
    { mode: 'content', count: 3 },
    { mode: 'content', rem: undefined },
    { mode: 'content', extra: true },
    { mode: 'lines' },
    { mode: 'lines', count: undefined },
    { mode: 'lines', count: null },
    { mode: 'lines', count: true },
    { mode: 'lines', count: '3' },
    { mode: 'lines', count: '3lh' },
    { mode: 'lines', count: 0 },
    { mode: 'lines', count: -1 },
    { mode: 'lines', count: 1.5 },
    { mode: 'lines', count: 13 },
    { mode: 'lines', count: NaN },
    { mode: 'lines', count: Infinity },
    { mode: 'lines', count: -Infinity },
    { mode: 'lines', count: 3, rem: 3 },
    { mode: 'lines', count: 3, extra: undefined },
    { mode: 'height' },
    { mode: 'height', rem: undefined },
    { mode: 'height', rem: null },
    { mode: 'height', rem: true },
    { mode: 'height', rem: '4.5' },
    { mode: 'height', rem: '4.5rem' },
    { mode: 'height', rem: 0 },
    { mode: 'height', rem: -3 },
    { mode: 'height', rem: 4 },
    { mode: 'height', rem: 4.4999 },
    { mode: 'height', rem: 13 },
    { mode: 'height', rem: NaN },
    { mode: 'height', rem: Infinity },
    { mode: 'height', rem: -Infinity },
    { mode: 'height', rem: 3, count: 3 },
    { mode: 'height', rem: 3, extra: true },
    { mode: 'content', [Symbol('extra')]: true },
    Object.create({ mode: 'content' }),
    Object.defineProperty({ mode: 'content' }, 'extra', { value: true }),
  ])('refuses invalid runtime configuration %#', (value: unknown) => {
    expect(() => hilosTableRowHeight(value)).toThrow(TypeError)
  })

  it('validates the class boundary even when a caller bypasses TypeScript', () => {
    expect(() =>
      hilosTableTextClass({
        mode: 'height',
        rem: 4,
      } as unknown as HilosTableRowHeight),
    ).toThrow(TypeError)
  })

  it('copies and freezes the policy rather than retaining mutable configuration', () => {
    const declaration = { mode: 'lines', count: 3 }
    const normalized = hilosTableRowHeight(declaration)
    declaration.count = 12
    expect(normalized).toEqual({ mode: 'lines', count: 3 })
    expect(normalized).not.toBe(declaration)
    expect(Object.isFrozen(normalized)).toBe(true)
  })

  it('keeps the public catalogs and typed columns closed at compilation', () => {
    expectTypeOf<HilosTableLineCount>().toEqualTypeOf<
      1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 | 10 | 11 | 12
    >()
    expectTypeOf<HilosTableTextRem>().toEqualTypeOf<2 | 3 | 4.5 | 6 | 9 | 12>()
    expectTypeOf<HilosTableRowHeight>().toEqualTypeOf<
      | { readonly mode: 'content' }
      | { readonly mode: 'lines'; readonly count: HilosTableLineCount }
      | { readonly mode: 'height'; readonly rem: HilosTableTextRem }
    >()
    expectTypeOf<
      HilosTableColumnOf<{ name: string }>['heightContent']
    >().toEqualTypeOf<'text' | 'parts' | undefined>()
  })
})
