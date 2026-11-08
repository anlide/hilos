import { calendarDaysInMonth } from '../calendar/calendarDate.js'
import {
  calendarCoordinate,
  calendarFormatter,
  localCoordinate,
  offsetIntervals,
} from './calendarPeriods.js'
import { usableTimeZone } from './timeZoneRule.js'

// Only the UTC search envelope is a duration, including date-line changes.
const SEARCH_RADIUS_MS = 2 * 24 * 60 * 60 * 1000

/** Gregorian local fields in an explicit zone, with millisecond precision. */
export interface LocalDateTimeParts {
  readonly year: number
  readonly month: number
  readonly day: number
  readonly hour: number
  readonly minute: number
  readonly second: number
  readonly millisecond: number
}

/** An exact moment and its signed local-minus-UTC offset in seconds. */
export interface DateTimeZoneCandidate {
  readonly epochMs: number
  readonly offsetSeconds: number
}

/** Zone facts without a policy for shifting gaps or choosing folds. */
export type DateTimeZoneResolution =
  | { readonly status: 'invalid'; readonly reason: 'date' | 'time' | 'zone' }
  | { readonly status: 'unique'; readonly candidate: DateTimeZoneCandidate }
  | {
      readonly status: 'gap'
      readonly before: DateTimeZoneCandidate
      readonly after: DateTimeZoneCandidate
    }
  | {
      readonly status: 'fold'
      readonly candidates: readonly [
        DateTimeZoneCandidate,
        DateTimeZoneCandidate,
        ...DateTimeZoneCandidate[],
      ]
    }

/** Exact local fields of a moment whose local year can be edited. */
export type DateTimeZoneProjection =
  | { readonly status: 'invalid'; readonly reason: 'moment' | 'zone' }
  | {
      readonly status: 'valid'
      readonly parts: LocalDateTimeParts
      readonly candidate: DateTimeZoneCandidate
    }

/** Synchronous calendar conversion with no implicit clock or fallback zone. */
export interface DateTimeZoneAdapter {
  resolve(parts: LocalDateTimeParts, timeZone: string): DateTimeZoneResolution
  project(epochMs: number, timeZone: string): DateTimeZoneProjection
}

/** Null is the deterministic UTC path; undefined is an unusable zone. */
function adapterFormatter(
  timeZone: string,
): Intl.DateTimeFormat | null | undefined {
  if (timeZone === 'UTC') return null
  if (usableTimeZone(timeZone) === null) return undefined
  try {
    return calendarFormatter(timeZone)
  } catch (error) {
    if (!(error instanceof RangeError) && !(error instanceof TypeError)) {
      throw error
    }

    return undefined
  }
}

/** Restore the UTC millisecond remainder, also for moments before the epoch. */
function preciseLocalCoordinate(
  formatter: Intl.DateTimeFormat,
  epochMs: number,
): number {
  return (
    localCoordinate(formatter, epochMs) + new Date(epochMs).getUTCMilliseconds()
  )
}

function resolve(
  parts: LocalDateTimeParts,
  timeZone: string,
): DateTimeZoneResolution {
  const { year, month, day, hour, minute, second, millisecond } = parts
  if (
    !Number.isInteger(year) ||
    year < 1 ||
    year > 9999 ||
    !Number.isInteger(month) ||
    month < 1 ||
    month > 12 ||
    !Number.isInteger(day) ||
    day < 1 ||
    day > calendarDaysInMonth(year, month)
  ) {
    return { status: 'invalid', reason: 'date' }
  }
  if (
    !Number.isInteger(hour) ||
    hour < 0 ||
    hour > 23 ||
    !Number.isInteger(minute) ||
    minute < 0 ||
    minute > 59 ||
    !Number.isInteger(second) ||
    second < 0 ||
    second > 59 ||
    !Number.isInteger(millisecond) ||
    millisecond < 0 ||
    millisecond > 999
  ) {
    return { status: 'invalid', reason: 'time' }
  }
  const formatter = adapterFormatter(timeZone)
  if (formatter === undefined) return { status: 'invalid', reason: 'zone' }
  const coordinate =
    calendarCoordinate(year, month, day, hour, minute, second) + millisecond
  if (formatter === null) {
    return {
      status: 'unique',
      candidate: { epochMs: coordinate, offsetSeconds: 0 },
    }
  }
  const intervals = offsetIntervals(
    formatter,
    coordinate - SEARCH_RADIUS_MS,
    coordinate + SEARCH_RADIUS_MS,
  )
  const candidates: DateTimeZoneCandidate[] = []
  for (const interval of intervals) {
    const epochMs = coordinate - interval.offset
    if (
      epochMs >= interval.start &&
      epochMs < interval.end &&
      preciseLocalCoordinate(formatter, epochMs) === coordinate &&
      !candidates.some((candidate) => candidate.epochMs === epochMs)
    ) {
      candidates.push({ epochMs, offsetSeconds: interval.offset / 1000 })
    }
  }
  candidates.sort((a, b) => a.epochMs - b.epochMs)
  const [first, secondCandidate, ...rest] = candidates
  if (first !== undefined) {
    return secondCandidate === undefined
      ? { status: 'unique', candidate: first }
      : { status: 'fold', candidates: [first, secondCandidate, ...rest] }
  }
  for (let index = 1; index < intervals.length; index++) {
    const before = intervals[index - 1]!
    const after = intervals[index]!
    const transition = after.start
    if (
      before.end === transition &&
      after.offset > before.offset &&
      coordinate >= transition + before.offset &&
      coordinate < transition + after.offset
    ) {
      return {
        status: 'gap',
        before: {
          epochMs: transition - 1,
          offsetSeconds: before.offset / 1000,
        },
        after: { epochMs: transition, offsetSeconds: after.offset / 1000 },
      }
    }
  }
  // Valid fields must match an interval or a real positive offset transition.
  throw new RangeError('Local date-time has no matching zone interval')
}

function project(epochMs: number, timeZone: string): DateTimeZoneProjection {
  if (
    !Number.isInteger(epochMs) ||
    !Number.isFinite(new Date(epochMs).getTime())
  ) {
    return { status: 'invalid', reason: 'moment' }
  }
  const formatter = adapterFormatter(timeZone)
  if (formatter === undefined) return { status: 'invalid', reason: 'zone' }
  const coordinate =
    formatter === null ? epochMs : preciseLocalCoordinate(formatter, epochMs)
  const local = new Date(coordinate)
  const year = local.getUTCFullYear()
  if (!Number.isFinite(year) || year < 1 || year > 9999) {
    return { status: 'invalid', reason: 'moment' }
  }

  return {
    status: 'valid',
    parts: {
      year,
      month: local.getUTCMonth() + 1,
      day: local.getUTCDate(),
      hour: local.getUTCHours(),
      minute: local.getUTCMinutes(),
      second: local.getUTCSeconds(),
      millisecond: local.getUTCMilliseconds(),
    },
    candidate: { epochMs, offsetSeconds: (coordinate - epochMs) / 1000 },
  }
}

/** Create a stateless adapter without reading Intl, a zone or the clock. */
export function createIntlDateTimeZoneAdapter(): DateTimeZoneAdapter {
  return { resolve, project }
}
