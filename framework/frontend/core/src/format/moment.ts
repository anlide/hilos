// The one core formatter of a moment on a screen. The reader's zone decides
// which hour it is; the formats of the tab's region decide how it is written —
// the region's date template (DD, MM, YYYY and the separator as written) and
// its time template (HH:mm:ss or hh:mm:ss a). The zone is read from
// hilosTimeZone and the formats from hilosReaderFormats() on every call, and
// neither is cached. Intl only supplies the numeric local parts of a moment in
// a zone, in fixed Gregorian Latin digits; the text is assembled here from the
// template, and every word inside it comes from readerWords.ts. The client never
// formats by the browser's locale: the formats come from the server, which reads
// the browser's languages only on the two ladders
// (docs/agents/architecture/time-zones.md, language-and-region.md).
import { type HilosLocaleFormats } from '../language/tabLanguageState.js'
import { offsetMs } from '../session/serverClock.js'
import { usableTimeZone } from '../timeZone/timeZoneRule.js'
import { hilosTimeZone } from '../timeZone/timeZoneState.js'
import { hilosReaderFormats } from './readerDisplay.js'
import {
  READER_AM,
  READER_MONTH_NAMES,
  READER_PM,
  READER_TODAY,
  READER_TOMORROW,
  READER_WEEKDAY_NAMES,
  READER_YESTERDAY,
} from './readerWords.js'

/** Options for presenting an absolute moment in the effective reader's zone. */
export interface MomentFormatOptions {
  /**
   * What is written: the region's date, its time, both (the default), a
   * calendar label, «November 2026» or «Monday».
   */
  readonly kind?:
    'date' | 'time' | 'dateTime' | 'calendar' | 'monthYear' | 'weekday'
  /** Whether a time carries its seconds; true by default. */
  readonly seconds?: boolean
  /** Whether a time with seconds carries «.SSS» after them; false by default. */
  readonly milliseconds?: boolean
  /** Whether the zone's short English name follows the text; false by default. */
  readonly zoneName?: boolean
  /** Reference in server epoch milliseconds, used only by calendar labels. */
  readonly now?: number
}

/** A local Gregorian day; the year is astronomical, so 1 BC is year 0. */
interface LocalDay {
  readonly year: number
  readonly month: number
  readonly day: number
}

/** A moment's numeric local coordinate in one zone. */
interface LocalMoment extends LocalDay {
  readonly hour: number
  readonly minute: number
  readonly second: number
  /** Three digits, read only when milliseconds were asked for. */
  readonly millisecond: string
}

const DATE_TOKENS = /YYYY|MM|DD/gu
const TIME_TOKENS = /HH|hh|mm|ss|SSS|a/gu
const CALENDAR_DAY = /^(\d{4})-(\d{2})-(\d{2})$/u
const YEAR_DIGITS = 4
const PART_DIGITS = 2
const HALF_DAY_HOURS = 12
const DAYS_PER_WEEK = 7
/** The ordinal dayOrdinal() gives 1970-01-01, which was a Thursday. */
const EPOCH_DAY_ORDINAL = 719468
const EPOCH_WEEKDAY = 4
/** The calendar words by the local-day difference from the reference. */
const CALENDAR_WORDS: ReadonlyMap<number, string> = new Map([
  [-1, READER_YESTERDAY],
  [0, READER_TODAY],
  [1, READER_TOMORROW],
])

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
 * The numeric parts of a moment in one zone: Gregorian, Latin digits, a
 * 24-hour clock and a fixed metadata locale, so that nothing of the browser's
 * locale reaches the parts. The era makes years before AD 1 readable.
 *
 * @param timeZone The zone the moment is read in.
 * @param milliseconds Whether the fraction of a second is read too.
 */
function localFormatter(
  timeZone: string,
  milliseconds: boolean,
): Intl.DateTimeFormat {
  return new Intl.DateTimeFormat('en-US', {
    calendar: 'gregory',
    numberingSystem: 'latn',
    hourCycle: 'h23',
    era: 'short',
    year: 'numeric',
    month: 'numeric',
    day: 'numeric',
    hour: 'numeric',
    minute: 'numeric',
    second: 'numeric',
    ...(milliseconds ? { fractionalSecondDigits: 3 } : {}),
    timeZone,
  })
}

/**
 * Read one moment's local coordinate off the parts formatter.
 *
 * @param formatter The parts formatter of the reader's zone.
 * @param moment The absolute moment in epoch milliseconds.
 */
function readLocal(
  formatter: Intl.DateTimeFormat,
  moment: number,
): LocalMoment {
  // A Date, not the bare number: a wrapped Intl, such as a test clock's, may
  // read the epoch's 0 as no moment at all and print the present instead.
  const parts = formatter.formatToParts(new Date(moment))
  const part = (type: Intl.DateTimeFormatPartTypes): string =>
    parts.find((item) => item.type === type)?.value ?? ''
  const year = Number(part('year'))

  return {
    year: part('era') === 'BC' ? 1 - year : year,
    month: Number(part('month')),
    day: Number(part('day')),
    hour: Number(part('hour')),
    minute: Number(part('minute')),
    second: Number(part('second')),
    millisecond: part('fractionalSecond'),
  }
}

/**
 * Number a local Gregorian day, including years before AD 1 and the edges of
 * Date's range. March starts the year so leap days fall at its end.
 *
 * @param local The local day to number.
 */
function dayOrdinal(local: LocalDay): number {
  const { month, day } = local
  const adjustedYear = local.year - (month <= 2 ? 1 : 0)
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
 * Two digits with a leading zero, as the templates write every part but the year.
 *
 * @param value The part's number.
 */
function pad(value: number): string {
  return String(value).padStart(PART_DIGITS, '0')
}

/**
 * A year as YYYY: four digits at least, a minus before years before 1 BC.
 *
 * @param year The astronomical year.
 */
function writeYear(year: number): string {
  const digits = String(Math.abs(year)).padStart(YEAR_DIGITS, '0')

  return year < 0 ? `-${digits}` : digits
}

/**
 * A local day in the region's date template: its tokens are replaced and the
 * separators kept as written.
 *
 * @param template The region's date template, such as DD.MM.YYYY.
 * @param local The local day.
 */
function writeDate(template: string, local: LocalDay): string {
  return template.replace(DATE_TOKENS, (token) =>
    token === 'YYYY'
      ? writeYear(local.year)
      : pad(token === 'MM' ? local.month : local.day),
  )
}

/**
 * A local time in the region's time template. Without seconds the «:ss» goes;
 * with milliseconds «.SSS» follows the seconds.
 *
 * @param template The region's time template, HH:mm:ss or hh:mm:ss a.
 * @param local The local moment.
 * @param seconds Whether the seconds are written.
 * @param milliseconds Whether the milliseconds follow the seconds.
 */
function writeTime(
  template: string,
  local: LocalMoment,
  seconds: boolean,
  milliseconds: boolean,
): string {
  const pattern = !seconds
    ? template.replace(':ss', '')
    : milliseconds
      ? template.replace('ss', 'ss.SSS')
      : template

  return pattern.replace(TIME_TOKENS, (token) => {
    switch (token) {
      case 'HH':
        return pad(local.hour)
      case 'hh':
        return pad(local.hour % HALF_DAY_HOURS || HALF_DAY_HOURS)
      case 'mm':
        return pad(local.minute)
      case 'ss':
        return pad(local.second)
      case 'SSS':
        return local.millisecond
      default:
        return local.hour < HALF_DAY_HOURS ? READER_AM : READER_PM
    }
  })
}

/**
 * The zone's short English name at that moment, such as CET or GMT+1.
 *
 * @param moment The absolute moment in epoch milliseconds.
 * @param timeZone The zone the moment is read in.
 */
function zoneShortName(moment: number, timeZone: string): string {
  return (
    new Intl.DateTimeFormat('en-US', { timeZone, timeZoneName: 'short' })
      .formatToParts(new Date(moment))
      .find((item) => item.type === 'timeZoneName')?.value ?? timeZone
  )
}

/**
 * Present a server moment without correcting the moment for the device clock.
 * Calendar labels compare local days; only their default reference uses the
 * server clock offset. Invalid moments or an unavailable Intl formatter return
 * null.
 *
 * @param moment Epoch milliseconds or a complete ISO UTC timestamp ending in Z.
 * @param options Presentation kind, its flags and the calendar reference.
 * @returns The text in the region's templates, or null when the moment cannot
 *   be presented.
 */
export function formatMoment(
  moment: number | string,
  options: MomentFormatOptions = {},
): string | null {
  return formatInTimeZone(
    moment,
    hilosTimeZone.get(),
    hilosReaderFormats(),
    options,
  )
}

/**
 * Internal entry for historical labels in an accepted snapshot's fixed zone,
 * written in the current formats of the tab's region: the zone is the
 * snapshot's data, the formats are how this tab reads it.
 * Calendar labels require the snapshot's asOfTs as options.now; without that
 * reference they return null rather than reading the live clock.
 * Deliberately absent from the base package barrel.
 *
 * @param moment Epoch milliseconds or a complete ISO UTC timestamp ending in Z.
 * @param timeZone The zone captured with the snapshot, never the live reader zone.
 * @param options Presentation kind, its flags and the calendar reference.
 */
export function formatSnapshotMoment(
  moment: number | string,
  timeZone: string,
  options: MomentFormatOptions = {},
): string | null {
  if (
    usableTimeZone(timeZone) === null ||
    (options.kind === 'calendar' && options.now === undefined)
  ) {
    return null
  }

  return formatInTimeZone(moment, timeZone, hilosReaderFormats(), options)
}

/**
 * Internal hour-and-minute preview of a Time zone choice in the region's time
 * template, independent of the live reader's zone.
 * Deliberately absent from the base package barrel.
 *
 * @param moment Epoch milliseconds or a complete ISO UTC timestamp ending in Z.
 * @param timeZone The exact named zone whose clock the picker previews.
 */
export function formatTimeZoneChoiceMoment(
  moment: number | string,
  timeZone: string,
): string | null {
  if (usableTimeZone(timeZone) === null) return null
  try {
    return formatInTimeZone(moment, timeZone, hilosReaderFormats(), {
      kind: 'time',
      seconds: false,
    })
  } catch {
    return null
  }
}

/**
 * A calendar date — a document revision's day, not a moment — in the region's
 * date template, with no zone: the day stays the day that was recorded.
 * Deliberately absent from the base package barrel.
 *
 * @param date The day as YYYY-MM-DD.
 * @returns The day in the region's date template, or null when the text is
 *   not a complete YYYY-MM-DD or names a day that does not exist.
 */
export function formatCalendarDay(date: string): string | null {
  const parts = CALENDAR_DAY.exec(date)
  if (parts === null || parts[0] !== date) return null
  const local: LocalDay = {
    year: Number(parts[1]),
    month: Number(parts[2]),
    day: Number(parts[3]),
  }
  const check = new Date(0)
  check.setUTCFullYear(local.year, local.month - 1, local.day)
  if (
    check.getUTCFullYear() !== local.year ||
    check.getUTCMonth() + 1 !== local.month ||
    check.getUTCDate() !== local.day
  ) {
    return null
  }

  return writeDate(hilosReaderFormats().date, local)
}

/**
 * Shared strict parser and presentation engine for live, snapshot and picker
 * entries.
 *
 * @param moment Epoch milliseconds or a complete ISO UTC timestamp ending in Z.
 * @param timeZone The zone the moment is read in.
 * @param formats The region's formats the text is written in.
 * @param options Presentation kind, its flags and the calendar reference.
 */
function formatInTimeZone(
  moment: number | string,
  timeZone: string,
  formats: HilosLocaleFormats,
  options: MomentFormatOptions,
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
  const seconds = options.seconds ?? true
  const milliseconds = seconds && options.milliseconds === true
  const formatter = localFormatter(timeZone, milliseconds)
  const local = readLocal(formatter, value)
  let text: string
  switch (kind) {
    case 'date':
      text = writeDate(formats.date, local)
      break
    case 'time':
      text = writeTime(formats.time, local, seconds, milliseconds)
      break
    case 'calendar':
      text =
        (now === null
          ? undefined
          : CALENDAR_WORDS.get(
              dayOrdinal(local) - dayOrdinal(readLocal(formatter, now)),
            )) ?? writeDate(formats.date, local)
      break
    case 'monthYear':
      text = `${READER_MONTH_NAMES[local.month - 1]} ${writeYear(local.year)}`
      break
    case 'weekday':
      text =
        READER_WEEKDAY_NAMES[
          (((dayOrdinal(local) - EPOCH_DAY_ORDINAL + EPOCH_WEEKDAY) %
            DAYS_PER_WEEK) +
            DAYS_PER_WEEK) %
            DAYS_PER_WEEK
        ]
      break
    default:
      text = `${writeDate(formats.date, local)} ${writeTime(formats.time, local, seconds, milliseconds)}`
  }

  return options.zoneName === true
    ? `${text} ${zoneShortName(value, timeZone)}`
    : text
}
