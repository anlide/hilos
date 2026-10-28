// Look-alikes that print no moment past the core formatter: proven numbers,
// serialization, arithmetic, metadata, declarations, the formatter itself,
// text that only names a printer, getters that only count, and durations.
// Nothing here may be reported.
import { formatMoment } from '@hilos/core'

/** The five numeric shapes the SDK prints today, by their types, not their files. */
export interface HttpSummary {
  countingHours: number
}

/** A route whose slowest request may be missing. */
export interface RouteRow {
  slowestMs: number | null
}

/** An export node with a record count. */
export interface ExportNode {
  records: number
}

/** A ternary over a nullable measurement, narrowed by the guard before it. */
export function formatNumber(value: number | null | undefined): string {
  return value == null || !Number.isFinite(value)
    ? '—'
    : value.toLocaleString(undefined, { maximumFractionDigits: 2 })
}

/** A typed property. */
export function formatPeriod(http: HttpSummary): string {
  return http.countingHours === 0
    ? 'Since start, <1 h'
    : `Since start, ${http.countingHours.toLocaleString()} h`
}

/** A typed parameter. */
export function formatCount(count: number): string {
  return count.toLocaleString()
}

/** A nullable property after its null branch. */
export function formatSlowest(row: RouteRow): string {
  return row.slowestMs === null ? '—' : `${row.slowestMs.toLocaleString()} ms`
}

/** A typed property of a node. */
export function formatRecords(node: ExportNode): string {
  return node.records.toLocaleString()
}

/** A number under a name of its own. */
type Count = number

/** A numeric enum. */
export enum Level {
  Low = 1,
  High = 2,
}

/** A number with a brand on it. */
type Meters = number & { readonly unit: 'm' }

/** Literals, aliases, enums, brands, bounds and bigint are all numbers. */
export function formatNumbers<T extends number>(
  count: Count,
  level: Level,
  distance: Meters,
  bound: T,
  total: bigint,
  either: number | bigint,
): string[] {
  return [
    (1234.5).toLocaleString(),
    10n.toLocaleString(),
    count.toLocaleString(),
    level.toLocaleString(),
    distance.toLocaleString(),
    bound.toLocaleString(),
    total.toLocaleString(),
    either.toLocaleString(),
  ]
}

/** The optional chain keeps undefined from reaching the call. */
export function formatOptional(value?: number | null): string | undefined {
  return value?.toLocaleString() ?? value?.['toLocaleString']()
}

/** A name proves nothing; an epoch number is the caller's to call a number. */
export function formatEpoch(date: number): string {
  return date.toLocaleString()
}

/** Narrowed to a number, and the Date goes to the formatter. */
export function formatEither(value: number | Date): string | null {
  return typeof value === 'number'
    ? value.toLocaleString()
    : formatMoment(value.getTime())
}

/** Reading a moment, serializing it, and building one are no display. */
export function readMoments(at: Date): (number | string)[] {
  return [
    at.getTime(),
    at.valueOf(),
    Date.parse('2026-10-28T00:00:00Z'),
    Date.now(),
    new Date(0).toISOString(),
    at.toISOString(),
    at.toJSON(),
  ]
}

/** A duration and a number in another base are numbers. */
export function formatDuration(startMs: number, endMs: number): string {
  const seconds = Math.round((endMs - startMs) / 1000)
  return `${seconds.toString()} s, ${seconds.toString(16)}`
}

/** toString() of a value that is not proven a Date. */
export function stringify(text: string, value: unknown): string {
  return JSON.parse(text).toString() + String(value)
}

/** Checking the engine and naming the type print nothing. */
export function hasDateTimeFormat(
  formatter: Intl.DateTimeFormat | null,
): boolean {
  return (
    typeof Intl !== 'undefined' &&
    typeof Intl.DateTimeFormat === 'function' &&
    formatter instanceof Intl.DateTimeFormat
  )
}

/** The zone-name metadata chain is allowed wherever it is written. */
export function browserZones(): string[] {
  return [
    new Intl.DateTimeFormat().resolvedOptions().timeZone,
    Intl.DateTimeFormat().resolvedOptions().timeZone,
  ]
}

/** A typed formatter read for its options, not for a moment. */
export function formatterZone(formatter: Intl.DateTimeFormat): string {
  return formatter.resolvedOptions().timeZone
}

/** The constructor's static member builds nothing. */
export function supportedLocales(): string[] {
  return Intl.DateTimeFormat.supportedLocalesOf(['en-GB'])
}

/** Other Intl formatters print numbers, durations and lists. */
export function formatOthers(value: number, days: number): string[] {
  return [
    new Intl.NumberFormat('en-GB').format(value),
    new Intl.RelativeTimeFormat('en').format(days, 'day'),
    new Intl.ListFormat('en').format(['a', 'b']),
  ]
}

/** The core formatter and a wrapper over it are the way through. */
export function formatThrough(moment: number): string | null {
  return formatMoment(moment)
}

/** A declaration that carries a printer's name is not a call. */
export interface Printer {
  toLocaleDateString(): string
}

/** Nor is a method of that name; calling it is, and that is in the bad file. */
export class Clock implements Printer {
  toLocaleDateString(): string {
    return 'today'
  }
}

/** Text naming a printer: date.toLocaleString() in a comment, and in strings. */
export const EXAMPLES = [
  'date.toLocaleString()',
  `use formatMoment(), not date.toLocaleDateString()`,
]

/** A getter that only counts or compares prints nothing. */
export function isLeapYear(at: Date): boolean {
  const year = at.getFullYear()
  return (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0
}

/** Calendar arithmetic stays numeric, a shift by a literal included. */
export function nextDay(at: Date): number {
  const next = new Date(at.getTime())
  next.setUTCDate(next.getUTCDate() + 1)
  return next.getTime()
}

/** A duration counted off getTime() is a number, printed as one. */
export function formatElapsed(start: Date, end: Date): string {
  const seconds = Math.floor((end.getTime() - start.getTime()) / 1000)
  return `${seconds} s, ${Math.floor(seconds / 60)} min`
}

/** Arithmetic past a shift ends the component: a countdown is no moment. */
export function formatCountdown(now: Date, deadline: Date): string {
  const hours = deadline.getHours() - now.getHours()
  const quarter = Math.floor(now.getMinutes() / 15)
  return `${hours} h, quarter ${quarter}, ` + now.getSeconds() * 1000
}

/** Serialization in UTC and the epoch number are the wire, not a display. */
export function momentKey(at: Date): string {
  return `moment-${at.toISOString()}-${at.getTime()}`
}

/** A component compared picks a word; the word carries none of it. */
export function dayPart(at: Date): string {
  return `good ${at.getHours() < 12 ? 'morning' : 'evening'}`
}

/** A type of one's own with getters of the same names is not a Date. */
export interface Span {
  getHours(): number
  getMinutes(): number
}

/** Its parts in text print a duration, not a moment. */
export function formatSpan(span: Span): string {
  return `${span.getHours()}:${String(span.getMinutes()).padStart(2, '0')}`
}

/** A local function named String is not the global one. */
export function describeDay(at: Date): string {
  const String = (day: number): string => (day === 1 ? 'first' : 'later')
  return String(at.getDate())
}
