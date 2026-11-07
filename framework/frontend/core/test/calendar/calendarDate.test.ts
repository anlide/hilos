import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  addCalendarDays,
  addCalendarMonths,
  addCalendarYears,
  calendarDateWeekday,
  calendarDaysInMonth,
  calendarMonthMatrix,
  calendarWeekEnd,
  calendarWeekStart,
  createCalendarDate,
  parseCalendarDate,
  type CalendarWeekday,
} from '../../src/base/index.js'

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('Gregorian calendar dates through /base', () => {
  it.each([
    ['0001-01-01', 1, 1, 1],
    ['0099-02-03', 99, 2, 3],
    ['0100-12-31', 100, 12, 31],
    ['1900-02-28', 1900, 2, 28],
    ['2000-02-29', 2000, 2, 29],
    ['2024-02-29', 2024, 2, 29],
    ['2100-03-01', 2100, 3, 1],
    ['9999-12-31', 9999, 12, 31],
  ])('roundtrips %s without changing early years', (date, year, month, day) => {
    expect(parseCalendarDate(date)).toEqual({ year, month, day })
    expect(createCalendarDate(year, month, day)).toBe(date)
    expect(addCalendarDays(date, 0)).toBe(date)
    expect(addCalendarMonths(date, 0)).toBe(date)
    expect(addCalendarYears(date, 0)).toBe(date)
  })

  it.each([
    [4, 29],
    [100, 28],
    [400, 29],
    [1900, 28],
    [2000, 29],
    [2024, 29],
    [2100, 28],
    [9999, 28],
  ])('applies the Gregorian leap-year rule in %i', (year, days) => {
    expect(calendarDaysInMonth(year, 2)).toBe(days)
  })

  it('counts every month in a common year', () => {
    expect(
      Array.from({ length: 12 }, (_, i) => calendarDaysInMonth(2026, i + 1)),
    ).toEqual([31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31])
  })

  it.each([
    '',
    '2026-1-01',
    '2026-01-1',
    '026-01-01',
    '02026-01-01',
    ' 2026-01-01',
    '2026-01-01 ',
    '2026-01-01\n',
    '+2026-01-01',
    '-0001-01-01',
    '２０２６-01-01',
    '2026/01/01',
    '2026-01-01T00:00:00',
    '2026-01-01Z',
    '2026-01-01+01:00',
    '0000-01-01',
    '10000-01-01',
    '2026-00-01',
    '2026-13-01',
    '2026-01-00',
    '2026-01-32',
    '2026-04-31',
    '2026-02-29',
    '1900-02-29',
    '2100-02-29',
  ])('refuses the non-strict or impossible date %s', (date) => {
    expect(parseCalendarDate(date)).toBeNull()
    expect(() => calendarDateWeekday(date)).toThrow(RangeError)
    for (const shift of [
      addCalendarDays,
      addCalendarMonths,
      addCalendarYears,
    ]) {
      expect(() => shift(date, 0)).toThrow(RangeError)
    }
    expect(() => calendarWeekStart(date, 1)).toThrow(RangeError)
    expect(() => calendarWeekEnd(date, 1)).toThrow(RangeError)
  })

  it('does not coerce an untyped value into a date', () => {
    const value = { toString: () => '2026-01-01' } as unknown as string
    expect(parseCalendarDate(value)).toBeNull()
    expect(() => addCalendarDays(value, 0)).toThrow(RangeError)
  })

  it.each([
    0,
    -1,
    10000,
    1.5,
    NaN,
    Infinity,
    -Infinity,
    Number.MAX_SAFE_INTEGER,
  ])('refuses invalid year %s', (year) => {
    expect(() => calendarDaysInMonth(year, 1)).toThrow(RangeError)
    expect(() => createCalendarDate(year, 1, 1)).toThrow(RangeError)
  })

  it.each([0, -1, 13, 1.5, NaN, Infinity, -Infinity])(
    'refuses invalid month %s',
    (month) => {
      expect(() => calendarDaysInMonth(2026, month)).toThrow(RangeError)
      expect(() => createCalendarDate(2026, month, 1)).toThrow(RangeError)
    },
  )

  it.each([0, -1, 32, 1.5, NaN, Infinity, -Infinity])(
    'refuses invalid day %s',
    (day) => {
      expect(() => createCalendarDate(2026, 1, day)).toThrow(RangeError)
    },
  )

  it.each([
    [2026, 2, 29],
    [1900, 2, 29],
    [2100, 2, 29],
    [2026, 4, 31],
  ])('refuses overflowing fields %i-%i-%i', (year, month, day) => {
    expect(() => createCalendarDate(year, month, day)).toThrow(RangeError)
  })
})

describe('Gregorian calendar shifts', () => {
  it.each([
    ['2026-10-17', 1, '2026-10-18'],
    ['2026-10-17', -1, '2026-10-16'],
    ['2026-10-17', 7, '2026-10-24'],
    ['2026-10-17', -7, '2026-10-10'],
    ['2026-01-31', 1, '2026-02-01'],
    ['2026-03-01', -1, '2026-02-28'],
    ['2024-02-28', 1, '2024-02-29'],
    ['2000-03-01', -1, '2000-02-29'],
    ['1900-02-28', 1, '1900-03-01'],
    ['2100-03-01', -1, '2100-02-28'],
    ['2026-12-31', 1, '2027-01-01'],
    ['2026-01-01', -1, '2025-12-31'],
    ['0099-12-31', 1, '0100-01-01'],
    ['0100-01-01', -1, '0099-12-31'],
    ['0001-01-01', 3652058, '9999-12-31'],
    ['9999-12-31', -3652058, '0001-01-01'],
  ])('shifts %s by %i days to %s', (date, delta, expected) => {
    expect(addCalendarDays(date, delta)).toBe(expected)
  })

  it.each([
    ['2026-01-31', 1, '2026-02-28'],
    ['2024-01-31', 1, '2024-02-29'],
    ['2026-03-31', -1, '2026-02-28'],
    ['2026-01-31', -1, '2025-12-31'],
    ['2026-12-31', 2, '2027-02-28'],
    ['2024-02-29', 12, '2025-02-28'],
    ['0099-12-31', 1, '0100-01-31'],
    ['0001-01-31', 119987, '9999-12-31'],
    ['9999-12-31', -119987, '0001-01-31'],
  ])('shifts %s by %i months to %s', (date, delta, expected) => {
    expect(addCalendarMonths(date, delta)).toBe(expected)
  })

  it.each([
    ['2024-02-29', 1, '2025-02-28'],
    ['2024-02-29', -1, '2023-02-28'],
    ['2024-02-29', 4, '2028-02-29'],
    ['2000-02-29', 100, '2100-02-28'],
    ['2000-02-29', -100, '1900-02-28'],
    ['0001-12-31', 9998, '9999-12-31'],
    ['9999-12-31', -9998, '0001-12-31'],
  ])('shifts %s by %i years to %s', (date, delta, expected) => {
    expect(addCalendarYears(date, delta)).toBe(expected)
  })

  it('uses each supplied day without retaining an anchor', () => {
    const february = addCalendarMonths('2026-01-31', 1)
    expect(february).toBe('2026-02-28')
    expect(addCalendarMonths(february, 1)).toBe('2026-03-28')
    expect(addCalendarMonths(february, -1)).toBe('2026-01-28')
    expect(addCalendarYears(addCalendarYears('2024-02-29', 1), 3)).toBe(
      '2028-02-28',
    )
  })

  it.each([
    0.5,
    -0.5,
    NaN,
    Infinity,
    -Infinity,
    Number.MAX_SAFE_INTEGER + 1,
    Number.MIN_SAFE_INTEGER - 1,
  ])('refuses invalid delta %s', (delta) => {
    for (const shift of [
      addCalendarDays,
      addCalendarMonths,
      addCalendarYears,
    ]) {
      expect(() => shift('2026-10-17', delta)).toThrow(RangeError)
    }
  })

  it('refuses results beyond both outer boundaries without wrapping', () => {
    for (const shift of [
      addCalendarDays,
      addCalendarMonths,
      addCalendarYears,
    ]) {
      expect(() => shift('0001-01-01', -1)).toThrow(RangeError)
      expect(() => shift('9999-12-31', 1)).toThrow(RangeError)
      expect(() => shift('2026-10-17', Number.MAX_SAFE_INTEGER)).toThrow(
        RangeError,
      )
      expect(() => shift('2026-10-17', Number.MIN_SAFE_INTEGER)).toThrow(
        RangeError,
      )
    }
  })
})

describe('Gregorian weekdays and week boundaries', () => {
  it.each([
    ['0001-01-01', 1],
    ['0099-01-01', 4],
    ['0100-01-01', 5],
    ['2000-01-01', 6],
    ['2026-10-18', 0],
    ['9999-12-31', 5],
  ])('reads the weekday of %s as %i', (date, expected) => {
    expect(calendarDateWeekday(date)).toBe(expected)
  })

  it.each([
    [0, '2025-12-28', '2026-01-03'],
    [1, '2025-12-29', '2026-01-04'],
    [2, '2025-12-30', '2026-01-05'],
    [3, '2025-12-31', '2026-01-06'],
    [4, '2026-01-01', '2026-01-07'],
    [5, '2025-12-26', '2026-01-01'],
    [6, '2025-12-27', '2026-01-02'],
  ] as const)('uses first weekday %i for Home/End', (first, start, end) => {
    expect(calendarWeekStart('2026-01-01', first)).toBe(start)
    expect(calendarWeekEnd('2026-01-01', first)).toBe(end)
  })

  it.each([-1, 7, 0.5, NaN, Infinity, -Infinity, undefined])(
    'requires an explicit valid first weekday %s',
    (first) => {
      expect(() =>
        calendarWeekStart('2026-10-17', first as CalendarWeekday),
      ).toThrow(RangeError)
      expect(() =>
        calendarWeekEnd('2026-10-17', first as CalendarWeekday),
      ).toThrow(RangeError)
    },
  )

  it('refuses only the boundary that actually leaves the editable years', () => {
    expect(calendarWeekStart('0001-01-01', 1)).toBe('0001-01-01')
    expect(calendarWeekEnd('0001-01-01', 0)).toBe('0001-01-06')
    expect(() => calendarWeekStart('0001-01-01', 0)).toThrow(RangeError)
    expect(calendarWeekStart('9999-12-31', 1)).toBe('9999-12-27')
    expect(calendarWeekEnd('9999-12-31', 6)).toBe('9999-12-31')
    expect(() => calendarWeekEnd('9999-12-31', 1)).toThrow(RangeError)
  })

  it('runs the whole API without Intl, implicit parsing, clock or local fields', () => {
    vi.stubGlobal('Intl', undefined)
    for (const method of ['parse', 'now'] as const) {
      vi.spyOn(Date, method).mockImplementation(() => {
        throw new Error('Implicit date or clock read')
      })
    }
    for (const method of [
      'getFullYear',
      'getMonth',
      'getDate',
      'getDay',
      'getHours',
      'getMinutes',
      'getSeconds',
      'getMilliseconds',
      'getTimezoneOffset',
      'setFullYear',
      'setMonth',
      'setDate',
      'setHours',
      'setMinutes',
      'setSeconds',
      'setMilliseconds',
    ] as const) {
      vi.spyOn(Date.prototype, method).mockImplementation(() => {
        throw new Error('Host-local calendar access')
      })
    }
    expect(parseCalendarDate('0099-02-03')).toEqual({
      year: 99,
      month: 2,
      day: 3,
    })
    expect(parseCalendarDate('1900-02-29')).toBeNull()
    expect(createCalendarDate(1, 1, 1)).toBe('0001-01-01')
    expect(calendarDaysInMonth(2000, 2)).toBe(29)
    expect(calendarDateWeekday('0001-01-01')).toBe(1)
    expect(addCalendarDays('0099-12-31', 1)).toBe('0100-01-01')
    expect(addCalendarMonths('2026-01-31', 1)).toBe('2026-02-28')
    expect(addCalendarYears('2024-02-29', -1)).toBe('2023-02-28')
    expect(calendarWeekStart('2026-01-01', 1)).toBe('2025-12-29')
    expect(calendarWeekEnd('2026-01-01', 1)).toBe('2026-01-04')
    expect(calendarMonthMatrix(2021, 2, 1)).toEqual({
      weekdays: [1, 2, 3, 4, 5, 6, 0],
      weeks: [
        [
          '2021-02-01',
          '2021-02-02',
          '2021-02-03',
          '2021-02-04',
          '2021-02-05',
          '2021-02-06',
          '2021-02-07',
        ],
        [
          '2021-02-08',
          '2021-02-09',
          '2021-02-10',
          '2021-02-11',
          '2021-02-12',
          '2021-02-13',
          '2021-02-14',
        ],
        [
          '2021-02-15',
          '2021-02-16',
          '2021-02-17',
          '2021-02-18',
          '2021-02-19',
          '2021-02-20',
          '2021-02-21',
        ],
        [
          '2021-02-22',
          '2021-02-23',
          '2021-02-24',
          '2021-02-25',
          '2021-02-26',
          '2021-02-27',
          '2021-02-28',
        ],
      ],
    })
  })
})
