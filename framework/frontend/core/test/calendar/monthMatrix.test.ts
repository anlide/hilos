import { describe, expect, it } from 'vitest'
import {
  addCalendarDays,
  calendarMonthMatrix,
  type CalendarWeekday,
} from '../../src/base/index.js'

describe('Gregorian month matrix through /base', () => {
  it.each([
    [2021, 2, 1, 4],
    [2026, 10, 1, 5],
    [2026, 8, 0, 6],
  ] as const)(
    'uses minimal rows for %i-%i starting on %i',
    (year, month, first, rows) => {
      expect(calendarMonthMatrix(year, month, first).weeks).toHaveLength(rows)
    },
  )

  it.each([
    [0, [0, 1, 2, 3, 4, 5, 6]],
    [1, [1, 2, 3, 4, 5, 6, 0]],
    [2, [2, 3, 4, 5, 6, 0, 1]],
    [3, [3, 4, 5, 6, 0, 1, 2]],
    [4, [4, 5, 6, 0, 1, 2, 3]],
    [5, [5, 6, 0, 1, 2, 3, 4]],
    [6, [6, 0, 1, 2, 3, 4, 5]],
  ] as const)(
    'keeps all seven weekday columns starting on %i',
    (first, weekdays) => {
      expect(calendarMonthMatrix(2026, 10, first).weekdays).toEqual(weekdays)
    },
  )

  // Each fixture supplies an independent first weekday and month length.
  it.each([
    [1, 1, 1, 31],
    [99, 1, 4, 31],
    [100, 1, 5, 31],
    [2000, 2, 2, 29],
    [2021, 2, 1, 28],
    [2026, 8, 6, 31],
    [2026, 10, 4, 31],
    [2026, 12, 2, 31],
    [2027, 1, 5, 31],
    [9999, 12, 3, 31],
  ])(
    'covers %i-%i exactly once for every first weekday',
    (year, month, weekday, days) => {
      const prefix = `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}`
      const dates = Array.from(
        { length: days },
        (_, day) => `${prefix}-${String(day + 1).padStart(2, '0')}`,
      )
      for (const first of [0, 1, 2, 3, 4, 5, 6] as const) {
        const { weekdays, weeks } = calendarMonthMatrix(year, month, first)
        const leading = (weekday - first + 7) % 7
        const trailing = (7 - ((leading + days) % 7)) % 7
        expect(weeks.length).toBeGreaterThanOrEqual(4)
        expect(weeks.length).toBeLessThanOrEqual(6)
        for (const week of weeks) expect(week).toHaveLength(7)
        expect(weeks.flat()).toEqual([
          ...Array<null>(leading).fill(null),
          ...dates,
          ...Array<null>(trailing).fill(null),
        ])
        expect(weekdays).toHaveLength(7)
        expect(weekdays[leading]).toBe(weekday)
      }
    },
  )

  it('includes December 30, 2011 regardless of the Apia IANA gap', () => {
    const matrix = calendarMonthMatrix(2011, 12, 1)
    expect(matrix.weeks[4]).toEqual([
      '2011-12-26',
      '2011-12-27',
      '2011-12-28',
      '2011-12-29',
      '2011-12-30',
      '2011-12-31',
      null,
    ])
    expect(addCalendarDays('2011-12-29', 1)).toBe('2011-12-30')
  })

  it('lets a consumer mark weekends by weekday without removing cells', () => {
    const matrix = calendarMonthMatrix(2026, 10, 1)
    const weekendColumns = (days: readonly CalendarWeekday[]): boolean[] =>
      matrix.weekdays.map((weekday) => days.includes(weekday))
    expect(weekendColumns([0, 6])).toEqual([
      false,
      false,
      false,
      false,
      false,
      true,
      true,
    ])
    expect(weekendColumns([5, 6])).toEqual([
      false,
      false,
      false,
      false,
      true,
      true,
      false,
    ])
    expect(matrix.weeks[0]).toEqual([
      null,
      null,
      null,
      '2026-10-01',
      '2026-10-02',
      '2026-10-03',
      '2026-10-04',
    ])
    expect(matrix.weeks[4]).toEqual([
      '2026-10-26',
      '2026-10-27',
      '2026-10-28',
      '2026-10-29',
      '2026-10-30',
      '2026-10-31',
      null,
    ])
    expect(calendarMonthMatrix(2026, 10, 1)).toEqual(matrix)
  })

  it.each([0, -1, 10000, 2026.5, NaN, Infinity, -Infinity])(
    'refuses invalid year %s',
    (year) => {
      expect(() => calendarMonthMatrix(year, 1, 1)).toThrow(RangeError)
    },
  )

  it.each([0, -1, 13, 1.5, NaN, Infinity, -Infinity])(
    'refuses invalid month %s',
    (month) => {
      expect(() => calendarMonthMatrix(2026, month, 1)).toThrow(RangeError)
    },
  )

  it.each([-1, 7, 1.5, NaN, Infinity, -Infinity, undefined])(
    'refuses invalid first weekday %s',
    (first) => {
      expect(() =>
        calendarMonthMatrix(2026, 10, first as CalendarWeekday),
      ).toThrow(RangeError)
    },
  )
})
