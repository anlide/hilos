import {
  calendarCoordinate,
  calendarFormatter,
  localCoordinate,
  offsetIntervals,
} from './calendarPeriods.js'
import { usableTimeZone } from './timeZoneRule.js'

// Covers IANA offsets and date-line changes on either side of the local date.
const SEARCH_RADIUS_MS = 2 * 24 * 60 * 60 * 1000

/** Validate the exact reader zone, keeping the deterministic UTC path. */
function editorFormatter(zone: string): Intl.DateTimeFormat | null {
  if (zone !== 'UTC' && usableTimeZone(zone) === null) {
    throw new RangeError('Invalid local date-time zone')
  }

  return calendarFormatter(zone)
}

/**
 * Fill a datetime-local editor with YYYY-MM-DDTHH:mm:ss in the explicit zone.
 * Supports Gregorian years 0001..9999 and discards subsecond precision.
 * This edits calendar fields; display moments through formatMoment instead.
 * A repeated local time can be filled, but parsing it refuses the ambiguity.
 * UTC works without Intl; other zones require a usable named IANA zone.
 * Deliberately absent from the package root and base barrels.
 *
 * @param epochMs The absolute moment in epoch milliseconds.
 * @param zone The reader's explicit IANA zone, without a fallback.
 * @throws RangeError When the moment, local year or zone cannot be edited.
 */
export function localDateTimeValue(epochMs: number, zone: string): string {
  const moment = new Date(epochMs).getTime()
  if (!Number.isFinite(epochMs) || !Number.isFinite(moment)) {
    throw new RangeError('Invalid local date-time moment')
  }
  const formatter = editorFormatter(zone)
  const local = new Date(
    formatter === null ? moment : localCoordinate(formatter, moment),
  )
  if (local.getUTCFullYear() < 1 || local.getUTCFullYear() > 9999) {
    throw new RangeError('Local date-time year is outside 0001..9999')
  }

  return local.toISOString().slice(0, 19)
}

/**
 * Parse YYYY-MM-DDTHH:mm[:ss] in the explicit zone into epoch milliseconds.
 * Omitted seconds mean zero, as in a browser-normalized datetime-local value.
 * Years are 0001..9999; fractions, whitespace, offsets and calendar overflow
 * are refused. Only one exact Gregorian roundtrip is accepted: gaps and folds
 * return null, as do invalid values or unusable zones. UTC needs no Intl.
 * Deliberately absent from the package root and base barrels.
 *
 * @param value The local calendar fields from the editor.
 * @param zone The reader's explicit IANA zone, without a fallback.
 */
export function parseLocalDateTimeValue(
  value: string,
  zone: string,
): number | null {
  const parts = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(
    value,
  )
  if (parts === null || parts[0] !== value) return null
  const year = Number(parts[1])
  const month = Number(parts[2])
  const day = Number(parts[3])
  const hour = Number(parts[4])
  const minute = Number(parts[5])
  const second = Number(parts[6] ?? 0)
  const coordinate = calendarCoordinate(year, month, day, hour, minute, second)
  const local = new Date(coordinate)
  if (
    year < 1 ||
    local.getUTCFullYear() !== year ||
    local.getUTCMonth() + 1 !== month ||
    local.getUTCDate() !== day ||
    local.getUTCHours() !== hour ||
    local.getUTCMinutes() !== minute ||
    local.getUTCSeconds() !== second
  ) {
    return null
  }
  let formatter: Intl.DateTimeFormat | null
  try {
    formatter = editorFormatter(zone)
  } catch (error) {
    if (!(error instanceof RangeError)) throw error

    return null
  }
  if (formatter === null) return coordinate
  let result: number | null = null
  for (const interval of offsetIntervals(
    formatter,
    coordinate - SEARCH_RADIUS_MS,
    coordinate + SEARCH_RADIUS_MS,
  )) {
    const candidate = coordinate - interval.offset
    if (
      candidate >= interval.start &&
      candidate < interval.end &&
      localCoordinate(formatter, candidate) === coordinate
    ) {
      if (result !== null) return null
      result = candidate
    }
  }

  return result
}
