import {
  parseCalendarDate,
  type CalendarDate,
} from '../calendar/calendarDate.js'
import type {
  DateTimeZoneAdapter,
  DateTimeZoneCandidate,
} from '../timeZone/dateTimeZoneAdapter.js'

/** The meaning of one endpoint, independently of the endpoint count. */
export type DateTimePickerKind = 'date' | 'time' | 'datetime'
export type DateTimePickerCount = 'single' | 'range'

/** Strict 24-hour HH:mm:ss, without a date, zone or fractions. */
export type ClockTime = string

export interface DateTimePickerValueMap {
  date: CalendarDate
  time: ClockTime
  /** Integer epoch milliseconds whose local Gregorian year is editable. */
  datetime: number
}

export type DateTimePickerValue<
  K extends DateTimePickerKind,
  C extends DateTimePickerCount,
> = C extends 'range'
  ? readonly [DateTimePickerValueMap[K], DateTimePickerValueMap[K]]
  : DateTimePickerValueMap[K]

export type DateTimePickerEdge = 'start' | 'end'
export type DateTimePickerPart =
  | 'year'
  | 'month'
  | 'day'
  | 'hour'
  | 'minute'
  | 'second'
  | 'meridiem'
  | 'offset'
export type DateTimePickerNumericPart = Exclude<
  DateTimePickerPart,
  'meridiem' | 'offset'
>

/** Ready-to-render text and accessible values for one visible part. */
export interface DateTimePickerPartDraft {
  readonly part: DateTimePickerPart
  readonly text: string
  readonly prefix: string
  readonly placeholder: string
  readonly label: string
  readonly valueNow: number | null
  readonly valueMin: number
  readonly valueMax: number
  readonly valueText: string
}

/** A fold occurrence, including historical offsets measured in seconds. */
export interface DateTimePickerOffsetChoice {
  readonly epochMs: number
  readonly offsetSeconds: number
  readonly text: string
  readonly selected: boolean
}

/** Unresolved drafts never carry a canonical value. */
export type DateTimePickerEndpointResult<K extends DateTimePickerKind> =
  | { readonly status: 'empty' }
  | { readonly status: 'incomplete' }
  | { readonly status: 'invalid'; readonly reason: 'date' | 'time' | 'zone' }
  | { readonly status: 'gap' }
  | {
      readonly status: 'ambiguous'
      readonly candidates: readonly [
        DateTimeZoneCandidate,
        DateTimeZoneCandidate,
        ...DateTimeZoneCandidate[],
      ]
    }
  | { readonly status: 'valid'; readonly value: DateTimePickerValueMap[K] }

export interface DateTimePickerEndpointSnapshot<K extends DateTimePickerKind> {
  readonly parts: readonly DateTimePickerPartDraft[]
  readonly result: DateTimePickerEndpointResult<K>
  readonly reason: string | null
  readonly offsetChoices: readonly DateTimePickerOffsetChoice[]
}

export type DateTimePickerResult<
  K extends DateTimePickerKind,
  C extends DateTimePickerCount,
> =
  | { readonly status: 'empty' }
  | { readonly status: 'unresolved' }
  | { readonly status: 'valid'; readonly value: DateTimePickerValue<K, C> }

/** One coherent publication of both endpoint drafts and their whole result. */
export interface DateTimePickerSnapshot<
  K extends DateTimePickerKind,
  C extends DateTimePickerCount,
> {
  readonly start: DateTimePickerEndpointSnapshot<K>
  readonly end: C extends 'range' ? DateTimePickerEndpointSnapshot<K> : null
  readonly result: DateTimePickerResult<K, C>
}

/**
 * Validate a nonempty external endpoint without coercion or normalization.
 * Datetime projection belongs exclusively to the supplied zone adapter.
 * @throws RangeError When the value is malformed or cannot be projected.
 * @internal
 */
export function validateDateTimePickerEndpointValue<
  K extends DateTimePickerKind,
>(
  kind: K,
  value: unknown,
  adapter?: DateTimeZoneAdapter,
  timeZone?: string,
): asserts value is DateTimePickerValueMap[K] {
  if (kind === 'date') {
    if (typeof value === 'string' && parseCalendarDate(value) !== null) return
  } else if (kind === 'time') {
    if (typeof value === 'string') {
      const match = /^(\d{2}):(\d{2}):(\d{2})$/.exec(value)
      if (
        match !== null &&
        match[0] === value &&
        Number(match[1]) <= 23 &&
        Number(match[2]) <= 59 &&
        Number(match[3]) <= 59
      ) {
        return
      }
    }
  } else if (
    kind === 'datetime' &&
    typeof value === 'number' &&
    Number.isSafeInteger(value) &&
    adapter !== undefined &&
    typeof timeZone === 'string' &&
    adapter.project(value, timeZone).status === 'valid'
  ) {
    return
  }

  throw new RangeError('Invalid DateTimePicker endpoint value')
}

/**
 * Validate a full external value; only null represents an empty field.
 * Range endpoints must both exist, without imposing an ordering policy.
 * @throws RangeError When the count or any endpoint value is invalid.
 * @internal
 */
export function validateDateTimePickerValue<
  K extends DateTimePickerKind,
  C extends DateTimePickerCount,
>(
  kind: K,
  count: C,
  value: unknown,
  adapter?: DateTimeZoneAdapter,
  timeZone?: string,
): asserts value is DateTimePickerValue<K, C> | null {
  if (count !== 'single' && count !== 'range') {
    throw new RangeError('Invalid DateTimePicker count')
  }
  if (value === null) return
  if (count === 'single') {
    validateDateTimePickerEndpointValue(kind, value, adapter, timeZone)
    return
  }
  if (!Array.isArray(value) || value.length !== 2) {
    throw new RangeError('DateTimePicker range must contain two endpoints')
  }
  validateDateTimePickerEndpointValue(kind, value[0], adapter, timeZone)
  validateDateTimePickerEndpointValue(kind, value[1], adapter, timeZone)
}
