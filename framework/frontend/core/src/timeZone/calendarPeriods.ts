const HOUR_MS = 60 * 60 * 1000
const DAY_MS = 24 * HOUR_MS

/** Absolute UTC starts of the calendar periods containing one moment. */
export interface CalendarPeriodStarts {
  readonly weekStartTs: number
  readonly dayStartTs: number
  readonly hourStartTs: number
}

/** A UTC interval throughout which the zone's offset is constant. */
interface OffsetInterval {
  readonly start: number
  readonly end: number
  readonly offset: number
}

/**
 * Encode Gregorian fields as a numeric calendar coordinate, not a wire moment.
 * setUTCFullYear also preserves years 0 through 99 instead of adding 1900.
 */
function calendarCoordinate(
  year: number,
  month: number,
  day: number,
  hour: number,
  minute: number,
  second: number,
): number {
  const date = new Date(0)
  date.setUTCFullYear(year, month - 1, day)
  date.setUTCHours(hour, minute, second, 0)

  return date.getTime()
}

/** Read numeric Gregorian fields, independent of the browser's locale/calendar. */
function localCoordinate(
  formatter: Intl.DateTimeFormat,
  moment: number,
): number {
  const parts = formatter.formatToParts(moment)
  const part = (type: Intl.DateTimeFormatPartTypes): string | undefined =>
    parts.find((item) => item.type === type)?.value
  const year = Number(part('year'))

  return calendarCoordinate(
    part('era') === 'BC' ? 1 - year : year,
    Number(part('month')),
    Number(part('day')),
    Number(part('hour')),
    Number(part('minute')),
    Number(part('second')),
  )
}

/**
 * Walk IANA offset intervals with hourly probes; IANA transitions are farther
 * apart than a probe. Locate each change at its first millisecond, including
 * historical offsets with seconds and transitions away from whole hours.
 */
function offsetIntervals(
  formatter: Intl.DateTimeFormat,
  start: number,
  end: number,
): OffsetInterval[] {
  const offsetAt = (moment: number): number =>
    localCoordinate(formatter, moment) - Math.floor(moment / 1000) * 1000
  const intervals: OffsetInterval[] = []
  let intervalStart = start
  let cursor = start
  let offset = offsetAt(start)
  while (cursor < end) {
    const probe = Math.min(cursor + HOUR_MS, end)
    const nextOffset = offsetAt(probe)
    if (nextOffset !== offset) {
      let before = cursor
      let after = probe
      while (after - before > 1) {
        const middle = Math.floor((before + after) / 2)
        if (offsetAt(middle) === offset) before = middle
        else after = middle
      }
      intervals.push({ start: intervalStart, end: after, offset })
      intervalStart = after
      offset = nextOffset
    }
    cursor = probe
  }
  intervals.push({ start: intervalStart, end: end + 1, offset })

  return intervals
}

/**
 * Intersect a local calendar period with the real UTC offset intervals. A gap
 * starts at the transition; a fold supplies two starts. Days/weeks take the
 * earliest one, while hours take the latest occurrence reached by now.
 */
function periodStart(
  intervals: readonly OffsetInterval[],
  start: number,
  end: number,
  latest = false,
): number {
  let result: number | undefined
  for (const interval of intervals) {
    const candidate = Math.max(interval.start, start - interval.offset)
    if (candidate < interval.end && candidate + interval.offset < end) {
      result = candidate
      if (!latest) return result
    }
  }
  if (result === undefined)
    throw new RangeError('Calendar period is unavailable')

  return result
}

/**
 * Calculate calendar starts in an explicit IANA zone, preserving UTC moments.
 * Local day/week arithmetic never subtracts elapsed 24/168-hour durations.
 * UTC also works without Intl, as required by the reader-zone fallback.
 *
 * TODO: replace Monday with the reader's locale-defined first weekday.
 * TODO: move browser-computed bounds to their future authoritative owner.
 *
 * @param nowMs The snapshot reference in epoch milliseconds.
 * @param timeZone The usable zone captured for this snapshot.
 * @throws RangeError When the moment or zone cannot supply calendar starts.
 */
export function calendarPeriodStarts(
  nowMs: number,
  timeZone: string,
): CalendarPeriodStarts {
  const now = new Date(nowMs).getTime()
  if (!Number.isFinite(now)) throw new RangeError('Invalid calendar moment')
  const formatter =
    timeZone === 'UTC'
      ? null
      : new Intl.DateTimeFormat('en-US', {
          timeZone,
          calendar: 'gregory',
          numberingSystem: 'latn',
          era: 'short',
          year: 'numeric',
          month: 'numeric',
          day: 'numeric',
          hour: 'numeric',
          minute: 'numeric',
          second: 'numeric',
          hourCycle: 'h23',
        })
  const local = new Date(
    formatter === null ? now : localCoordinate(formatter, now),
  )
  local.setUTCMinutes(0, 0, 0)
  const hour = local.getTime()
  local.setUTCHours(0, 0, 0, 0)
  const day = local.getTime()
  // Calendar stepping here is on Gregorian fields, not on elapsed UTC time.
  local.setUTCDate(local.getUTCDate() - ((local.getUTCDay() + 6) % 7))
  const week = local.getTime()
  if (formatter === null) {
    return { weekStartTs: week, dayStartTs: day, hourStartTs: hour }
  }
  // Two days cover IANA offsets, including date-line changes. Only this UTC
  // search envelope is a duration; the period boundaries above are calendar ones.
  const intervals = offsetIntervals(formatter, week - 2 * DAY_MS, now)

  return {
    weekStartTs: periodStart(intervals, week, week + 7 * DAY_MS),
    dayStartTs: periodStart(intervals, day, day + DAY_MS),
    hourStartTs: periodStart(intervals, hour, hour + HOUR_MS, true),
  }
}
