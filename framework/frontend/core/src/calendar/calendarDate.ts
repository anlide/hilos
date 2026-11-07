import { calendarCoordinate } from '../timeZone/calendarPeriods.js'

/** Strict Gregorian YYYY-MM-DD in years 0001..9999; validate at boundaries. */
export type CalendarDate = string

/** Sunday is 0, through Saturday as 6. */
export type CalendarWeekday = 0 | 1 | 2 | 3 | 4 | 5 | 6

/** The Gregorian fields of a validated calendar date. */
export interface CalendarDateParts {
  readonly year: number
  readonly month: number
  readonly day: number
}

/**
 * Read a strict Gregorian date without trimming, coercion or normalization.
 * Invalid strings and impossible dates return null.
 */
export function parseCalendarDate(value: string): CalendarDateParts | null {
  if (typeof value !== 'string') return null
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  if (match === null || match[0] !== value) return null
  const year = Number(match[1])
  const month = Number(match[2])
  const day = Number(match[3])
  if (
    year < 1 ||
    month < 1 ||
    month > 12 ||
    day < 1 ||
    day > calendarDaysInMonth(year, month)
  ) {
    return null
  }

  return { year, month, day }
}

/**
 * Create a Gregorian date from integer fields without normalizing overflow.
 * @throws RangeError When the fields do not identify a date in 0001..9999.
 */
export function createCalendarDate(
  year: number,
  month: number,
  day: number,
): CalendarDate {
  if (
    !Number.isInteger(day) ||
    day < 1 ||
    day > calendarDaysInMonth(year, month)
  ) {
    throw new RangeError('Invalid Gregorian calendar date')
  }

  return `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

/**
 * Count the days in a Gregorian month, including the 4/100/400 leap-year rule.
 * @throws RangeError When year or month is not an integer in range.
 */
export function calendarDaysInMonth(year: number, month: number): number {
  if (
    !Number.isInteger(year) ||
    year < 1 ||
    year > 9999 ||
    !Number.isInteger(month) ||
    month < 1 ||
    month > 12
  ) {
    throw new RangeError('Invalid Gregorian calendar month')
  }
  if (month === 2) {
    return year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0) ? 29 : 28
  }

  return [4, 6, 9, 11].includes(month) ? 30 : 31
}

/** Require a calendar date before using the normalizing coordinate helper. */
function requireCalendarDate(date: CalendarDate): CalendarDateParts {
  const parts = parseCalendarDate(date)
  if (parts === null) throw new RangeError('Invalid Gregorian calendar date')

  return parts
}

/** Require an exact integer shift before doing calendar arithmetic. */
function requireDelta(delta: number): void {
  if (!Number.isSafeInteger(delta)) {
    throw new RangeError('Calendar delta must be a safe integer')
  }
}

/** Require an explicit first weekday, including for untyped callers. */
function requireWeekday(firstWeekday: CalendarWeekday): void {
  if (!Number.isInteger(firstWeekday) || firstWeekday < 0 || firstWeekday > 6) {
    throw new RangeError('Invalid calendar weekday')
  }
}

/**
 * Read the Gregorian weekday without a time zone or locale.
 * @throws RangeError When date is not a strict Gregorian date in range.
 */
export function calendarDateWeekday(date: CalendarDate): CalendarWeekday {
  const { year, month, day } = requireCalendarDate(date)

  return new Date(
    calendarCoordinate(year, month, day, 0, 0, 0),
  ).getUTCDay() as CalendarWeekday
}

/**
 * Shift calendar days across months and years using only UTC coordinates.
 * The coordinate is arithmetic workspace, not the date's absolute moment.
 * @throws RangeError When date or delta is invalid, or the result leaves 0001..9999.
 */
export function addCalendarDays(
  date: CalendarDate,
  delta: number,
): CalendarDate {
  const { year, month, day } = requireCalendarDate(date)
  requireDelta(delta)
  const result = new Date(calendarCoordinate(year, month, day, 0, 0, 0))
  result.setUTCDate(result.getUTCDate() + delta)

  return createCalendarDate(
    result.getUTCFullYear(),
    result.getUTCMonth() + 1,
    result.getUTCDate(),
  )
}

/**
 * Shift months, clamping only the day to the target month's last day.
 * Each call uses the supplied date; no preferred day is retained.
 * @throws RangeError When date or delta is invalid, or the result leaves 0001..9999.
 */
export function addCalendarMonths(
  date: CalendarDate,
  delta: number,
): CalendarDate {
  const { year, month, day } = requireCalendarDate(date)
  requireDelta(delta)
  const target = (year - 1) * 12 + month - 1 + delta
  if (target < 0 || target >= 9999 * 12) {
    throw new RangeError('Calendar result is outside 0001..9999')
  }
  const targetYear = Math.floor(target / 12) + 1
  const targetMonth = (target % 12) + 1

  return createCalendarDate(
    targetYear,
    targetMonth,
    Math.min(day, calendarDaysInMonth(targetYear, targetMonth)),
  )
}

/**
 * Shift years, clamping only the day to the target month's last day.
 * @throws RangeError When date or delta is invalid, or the result leaves 0001..9999.
 */
export function addCalendarYears(
  date: CalendarDate,
  delta: number,
): CalendarDate {
  const { year, month, day } = requireCalendarDate(date)
  requireDelta(delta)
  const targetYear = year + delta

  return createCalendarDate(
    targetYear,
    month,
    Math.min(day, calendarDaysInMonth(targetYear, month)),
  )
}

/**
 * Find the containing week's first day using an explicit first weekday.
 * @throws RangeError When an input is invalid or the week starts before 0001.
 */
export function calendarWeekStart(
  date: CalendarDate,
  firstWeekday: CalendarWeekday,
): CalendarDate {
  requireWeekday(firstWeekday)

  return addCalendarDays(
    date,
    -((calendarDateWeekday(date) - firstWeekday + 7) % 7),
  )
}

/**
 * Find the containing week's last day using an explicit first weekday.
 * @throws RangeError When an input is invalid or the week ends after 9999.
 */
export function calendarWeekEnd(
  date: CalendarDate,
  firstWeekday: CalendarWeekday,
): CalendarDate {
  requireWeekday(firstWeekday)

  return addCalendarDays(
    date,
    6 - ((calendarDateWeekday(date) - firstWeekday + 7) % 7),
  )
}
