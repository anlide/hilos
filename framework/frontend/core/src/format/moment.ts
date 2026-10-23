import { offsetMs } from '../session/serverClock.js'
import { hilosTimeZone } from '../timeZone/timeZoneState.js'

/** Options for presenting an absolute moment in the effective reader's zone. */
export interface MomentFormatOptions {
  readonly kind?: 'date' | 'time' | 'dateTime' | 'calendar'
  readonly locale?: string | string[]
  /** Replaces the kind's preset; the effective reader's zone always wins. */
  readonly format?: Omit<Intl.DateTimeFormatOptions, 'timeZone'>
  /** Reference in server epoch milliseconds, used only by calendar labels. */
  readonly now?: number
}

/**
 * Read epoch milliseconds or a complete ISO UTC timestamp, without guessing a
 * unit or letting Date normalize an impossible calendar date.
 *
 * @param moment The absolute moment supplied by the caller.
 */
function readMoment(moment: number | string): number | null {
  if (typeof moment === 'number') {
    return Number.isFinite(moment) &&
      Number.isFinite(new Date(moment).getTime())
      ? moment
      : null
  }
  if (typeof moment !== 'string') return null
  const parts =
    /^(\d{4}|[+-]\d{6})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.(\d+))?Z$/.exec(
      moment,
    )
  if (parts === null || parts[0] !== moment) return null
  const date = new Date(moment)
  if (
    !Number.isFinite(date.getTime()) ||
    date.getUTCFullYear() !== Number(parts[1]) ||
    date.getUTCMonth() + 1 !== Number(parts[2]) ||
    date.getUTCDate() !== Number(parts[3]) ||
    date.getUTCHours() !== Number(parts[4]) ||
    date.getUTCMinutes() !== Number(parts[5]) ||
    date.getUTCSeconds() !== Number(parts[6])
  ) {
    return null
  }

  return date.getTime()
}

/**
 * Number a local Gregorian day, including years before AD 1 and the edges of
 * Date's range. March starts the year so leap days fall at its end.
 *
 * @param formatter The Gregorian, Latin-digit calendar in the reader's zone.
 * @param moment The absolute moment whose local day is numbered.
 */
function dayOrdinal(formatter: Intl.DateTimeFormat, moment: number): number {
  const parts = formatter.formatToParts(moment)
  const part = (type: Intl.DateTimeFormatPartTypes): string | undefined =>
    parts.find((item) => item.type === type)?.value
  const year = Number(part('year'))
  const month = Number(part('month'))
  const day = Number(part('day'))
  const adjustedYear =
    (part('era') === 'BC' ? 1 - year : year) - (month <= 2 ? 1 : 0)
  const era = Math.floor(adjustedYear / 400)
  const yearOfEra = adjustedYear - era * 400
  const dayOfYear =
    Math.floor((153 * (month + (month > 2 ? -3 : 9)) + 2) / 5) + day - 1

  return (
    era * 146097 +
    yearOfEra * 365 +
    Math.floor(yearOfEra / 4) -
    Math.floor(yearOfEra / 100) +
    dayOfYear
  )
}

/**
 * Present a server moment without correcting the moment for the device clock.
 * Calendar labels compare local days; only their default reference uses the
 * server clock offset. Invalid moments or an unavailable Intl formatter return
 * null; developer errors in locale or format options remain visible.
 *
 * @param moment Epoch milliseconds or a complete ISO UTC timestamp ending in Z.
 * @param options Presentation kind, locale, replacement format and reference.
 * @returns The localized text, or null when the moment cannot be presented.
 */
export function formatMoment(
  moment: number | string,
  options: MomentFormatOptions = {},
): string | null {
  const value = readMoment(moment)
  if (
    value === null ||
    typeof Intl === 'undefined' ||
    typeof Intl.DateTimeFormat !== 'function'
  ) {
    return null
  }
  const kind = options.kind ?? 'dateTime'
  const now =
    kind === 'calendar'
      ? readMoment(options.now ?? Date.now() + offsetMs())
      : null
  if (kind === 'calendar' && now === null) return null
  const timeZone = hilosTimeZone.get()
  const format: Intl.DateTimeFormatOptions =
    options.format ??
    (kind === 'time'
      ? { timeStyle: 'medium' }
      : kind === 'dateTime'
        ? { dateStyle: 'medium', timeStyle: 'medium' }
        : { dateStyle: 'medium' })
  const formatter = new Intl.DateTimeFormat(options.locale, {
    ...format,
    timeZone,
  })
  if (
    kind === 'calendar' &&
    now !== null &&
    typeof Intl.RelativeTimeFormat === 'function'
  ) {
    // Fixed metadata locale makes the era readable independently of the
    // presentation locale. The calendar and digits are explicit too.
    const calendar = new Intl.DateTimeFormat('en-US', {
      calendar: 'gregory',
      numberingSystem: 'latn',
      year: 'numeric',
      month: 'numeric',
      day: 'numeric',
      era: 'short',
      timeZone,
    })
    const delta = dayOrdinal(calendar, value) - dayOrdinal(calendar, now)
    if (delta >= -1 && delta <= 1) {
      return new Intl.RelativeTimeFormat(options.locale, {
        numeric: 'auto',
      }).format(delta, 'day')
    }
  }

  return formatter.format(value)
}
