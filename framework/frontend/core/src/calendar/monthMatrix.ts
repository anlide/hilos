import {
  calendarDateWeekday,
  calendarDaysInMonth,
  createCalendarDate,
  type CalendarDate,
  type CalendarWeekday,
} from './calendarDate.js'

/** Seven weekday columns and the minimum four to six weeks of one month. */
export interface CalendarMonthMatrix {
  readonly weekdays: readonly CalendarWeekday[]
  readonly weeks: readonly (readonly (CalendarDate | null)[])[]
}

/**
 * Build a Gregorian month, keeping unused edge cells as null.
 * All dates belong to the requested month, including dates skipped in IANA zones.
 * Weekends, selection, today and other display policy belong to the consumer.
 * @throws RangeError When year, month or firstWeekday is invalid.
 */
export function calendarMonthMatrix(
  year: number,
  month: number,
  firstWeekday: CalendarWeekday,
): CalendarMonthMatrix {
  const days = calendarDaysInMonth(year, month)
  if (!Number.isInteger(firstWeekday) || firstWeekday < 0 || firstWeekday > 6) {
    throw new RangeError('Invalid calendar weekday')
  }
  const weekdays = Array.from(
    { length: 7 },
    (_, column) => ((firstWeekday + column) % 7) as CalendarWeekday,
  )
  const leading =
    (calendarDateWeekday(createCalendarDate(year, month, 1)) -
      firstWeekday +
      7) %
    7
  const weeks = Array.from(
    { length: Math.ceil((leading + days) / 7) },
    (_, row) =>
      Array.from({ length: 7 }, (_, column) => {
        const day = row * 7 + column - leading + 1

        return day < 1 || day > days
          ? null
          : createCalendarDate(year, month, day)
      }),
  )

  return { weekdays, weeks }
}
