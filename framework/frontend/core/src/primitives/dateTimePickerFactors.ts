import type { DateTimePickerPart } from './dateTimePickerValue.js'

/** Sunday is 0, through Saturday as 6. */
export type DateTimePickerWeekday = 0 | 1 | 2 | 3 | 4 | 5 | 6
export type DateTimePickerDatePart = 'day' | 'month' | 'year'

export interface DateTimePickerName {
  readonly short: string
  readonly full: string
}

/** Explicit presentation factors, independent of locale and zone rules. */
export interface DateTimePickerFactors {
  readonly firstWeekday: DateTimePickerWeekday
  readonly weekendDays: readonly DateTimePickerWeekday[]
  readonly dateOrder: readonly [
    DateTimePickerDatePart,
    DateTimePickerDatePart,
    DateTimePickerDatePart,
  ]
  readonly dateSeparator: string
  readonly hourCycle: 'h12' | 'h23'
  readonly monthNames: readonly DateTimePickerName[]
  readonly weekdayNames: readonly DateTimePickerName[]
  readonly placeholders: Readonly<Record<DateTimePickerPart, string>>
  readonly amLabel: string
  readonly pmLabel: string
}

/** Visible and accessible words, including local draft reason templates. */
export interface DateTimePickerLabels {
  readonly from: string
  readonly to: string
  readonly today: string
  readonly done: string
  readonly previousMonth: string
  readonly nextMonth: string
  readonly chooseDate: string
  readonly chooseTime: string
  readonly chooseOffset: string
  readonly openCalendar: string
  readonly time: string
  readonly increase: string
  readonly decrease: string
  readonly firstOccurrence: string
  readonly secondOccurrence: string
  readonly year: string
  readonly month: string
  readonly day: string
  readonly hour: string
  readonly minute: string
  readonly second: string
  readonly meridiem: string
  readonly offset: string
  readonly invalidDate: string
  readonly invalidTime: string
  readonly invalidZone: string
  readonly gap: string
  readonly ambiguous: string
}

/** One shared default, including frozen nested arrays, names and placeholders. */
export const DEFAULT_DATE_TIME_PICKER_FACTORS: Readonly<DateTimePickerFactors> =
  Object.freeze({
    firstWeekday: 1,
    weekendDays: Object.freeze([0, 6] as const),
    dateOrder: Object.freeze(['day', 'month', 'year'] as const),
    dateSeparator: '/',
    hourCycle: 'h23',
    monthNames: Object.freeze([
      Object.freeze({ short: 'Jan', full: 'January' }),
      Object.freeze({ short: 'Feb', full: 'February' }),
      Object.freeze({ short: 'Mar', full: 'March' }),
      Object.freeze({ short: 'Apr', full: 'April' }),
      Object.freeze({ short: 'May', full: 'May' }),
      Object.freeze({ short: 'Jun', full: 'June' }),
      Object.freeze({ short: 'Jul', full: 'July' }),
      Object.freeze({ short: 'Aug', full: 'August' }),
      Object.freeze({ short: 'Sep', full: 'September' }),
      Object.freeze({ short: 'Oct', full: 'October' }),
      Object.freeze({ short: 'Nov', full: 'November' }),
      Object.freeze({ short: 'Dec', full: 'December' }),
    ]),
    weekdayNames: Object.freeze([
      Object.freeze({ short: 'Sun', full: 'Sunday' }),
      Object.freeze({ short: 'Mon', full: 'Monday' }),
      Object.freeze({ short: 'Tue', full: 'Tuesday' }),
      Object.freeze({ short: 'Wed', full: 'Wednesday' }),
      Object.freeze({ short: 'Thu', full: 'Thursday' }),
      Object.freeze({ short: 'Fri', full: 'Friday' }),
      Object.freeze({ short: 'Sat', full: 'Saturday' }),
    ]),
    placeholders: Object.freeze({
      year: 'yyyy',
      month: 'mm',
      day: 'dd',
      hour: '--',
      minute: '--',
      second: '--',
      meridiem: '--',
      offset: 'UTC±--',
    }),
    amLabel: 'AM',
    pmLabel: 'PM',
  })

export const DEFAULT_DATE_TIME_PICKER_LABELS: Readonly<DateTimePickerLabels> =
  Object.freeze({
    from: 'From',
    to: 'To',
    today: 'Today',
    done: 'Done',
    previousMonth: 'Previous month',
    nextMonth: 'Next month',
    chooseDate: 'Choose date',
    chooseTime: 'Choose time',
    chooseOffset: 'Choose UTC offset',
    openCalendar: 'Open calendar',
    time: 'Time',
    increase: 'Increase',
    decrease: 'Decrease',
    firstOccurrence: 'First',
    secondOccurrence: 'Second',
    year: 'Year',
    month: 'Month',
    day: 'Day',
    hour: 'Hours',
    minute: 'Minutes',
    second: 'Seconds',
    meridiem: 'AM/PM',
    offset: 'UTC offset',
    invalidDate: 'This date does not exist.',
    invalidTime: 'This time does not exist.',
    invalidZone: '{zone} is not a usable time zone.',
    gap: '{time} does not exist on {date} in {zone}: at {from} the clocks jump to {to}.',
    ambiguous: '{time} happens twice on {date} in {zone}. Which one?',
  })

/** Reject malformed configuration objects before reading their fields. */
function requireObject(value: unknown): void {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) {
    throw new RangeError('DateTimePicker configuration must be an object')
  }
}

function requireNonemptyString(value: unknown): void {
  if (typeof value !== 'string' || value.length === 0) {
    throw new RangeError(
      'DateTimePicker names and labels must be nonempty strings',
    )
  }
}

function validWeekday(value: DateTimePickerWeekday): boolean {
  return Number.isInteger(value) && value >= 0 && value <= 6
}

/** Validate and copy names so callers retain ownership of their input arrays. */
function resolveNames(
  names: readonly DateTimePickerName[],
  count: number,
): readonly DateTimePickerName[] {
  if (!Array.isArray(names) || names.length !== count) {
    throw new RangeError(`DateTimePicker requires exactly ${count} names`)
  }

  return Object.freeze(
    Array.from(names, (name: DateTimePickerName) => {
      requireObject(name)
      requireNonemptyString(name.short)
      requireNonemptyString(name.full)

      return Object.freeze({ short: name.short, full: name.full })
    }),
  )
}

/**
 * Resolve each input afresh against defaults, replacing arrays and placeholders.
 * Unknown keys are ignored; caller-owned objects are never frozen or retained.
 * @throws RangeError When a supplied factor is malformed or out of range.
 * @internal
 */
export function resolveDateTimePickerFactors(
  partial: Partial<DateTimePickerFactors> = {},
): Readonly<DateTimePickerFactors> {
  requireObject(partial)
  const defaults = DEFAULT_DATE_TIME_PICKER_FACTORS
  const factors: DateTimePickerFactors = {
    firstWeekday: partial.firstWeekday ?? defaults.firstWeekday,
    weekendDays: partial.weekendDays ?? defaults.weekendDays,
    dateOrder: partial.dateOrder ?? defaults.dateOrder,
    dateSeparator: partial.dateSeparator ?? defaults.dateSeparator,
    hourCycle: partial.hourCycle ?? defaults.hourCycle,
    monthNames: partial.monthNames ?? defaults.monthNames,
    weekdayNames: partial.weekdayNames ?? defaults.weekdayNames,
    placeholders: partial.placeholders ?? defaults.placeholders,
    amLabel: partial.amLabel ?? defaults.amLabel,
    pmLabel: partial.pmLabel ?? defaults.pmLabel,
  }
  // Undefined is an omitted Partial field; explicit null is malformed input.
  for (const key of Object.keys(factors) as (keyof DateTimePickerFactors)[]) {
    if (partial[key] === null) {
      throw new RangeError(`Invalid DateTimePicker factor: ${key}`)
    }
  }
  if (!validWeekday(factors.firstWeekday)) {
    throw new RangeError('Invalid DateTimePicker first weekday')
  }
  if (
    !Array.isArray(factors.weekendDays) ||
    !Array.from(factors.weekendDays).every(validWeekday) ||
    new Set(factors.weekendDays).size !== factors.weekendDays.length
  ) {
    throw new RangeError('DateTimePicker weekend days must be a weekday set')
  }
  if (
    !Array.isArray(factors.dateOrder) ||
    factors.dateOrder.length !== 3 ||
    !Array.from(factors.dateOrder).every((part) =>
      ['day', 'month', 'year'].includes(part),
    ) ||
    new Set(factors.dateOrder).size !== 3
  ) {
    throw new RangeError('DateTimePicker date order must be a permutation')
  }
  if (factors.hourCycle !== 'h12' && factors.hourCycle !== 'h23') {
    throw new RangeError('Invalid DateTimePicker hour cycle')
  }
  if (typeof factors.dateSeparator !== 'string') {
    throw new RangeError('DateTimePicker date separator must be a string')
  }
  requireNonemptyString(factors.amLabel)
  requireNonemptyString(factors.pmLabel)
  requireObject(factors.placeholders)
  const placeholders = { ...defaults.placeholders }
  for (const key of Object.keys(placeholders) as DateTimePickerPart[]) {
    const value = factors.placeholders[key]
    if (typeof value !== 'string') {
      throw new RangeError(`Missing DateTimePicker placeholder: ${key}`)
    }
    placeholders[key] = value
  }

  return Object.freeze({
    ...factors,
    weekendDays: Object.freeze([...factors.weekendDays]),
    dateOrder: Object.freeze([
      factors.dateOrder[0],
      factors.dateOrder[1],
      factors.dateOrder[2],
    ] as const),
    monthNames: resolveNames(factors.monthNames, 12),
    weekdayNames: resolveNames(factors.weekdayNames, 7),
    placeholders: Object.freeze(placeholders),
  })
}

/**
 * Complete only known label keys from the shared defaults.
 * @throws RangeError When a supplied label is not a nonempty string.
 * @internal
 */
export function resolveDateTimePickerLabels(
  partial: Partial<DateTimePickerLabels> = {},
): Readonly<DateTimePickerLabels> {
  requireObject(partial)
  const labels = { ...DEFAULT_DATE_TIME_PICKER_LABELS }
  for (const key of Object.keys(labels) as (keyof DateTimePickerLabels)[]) {
    const value = partial[key]
    if (value === undefined) continue
    requireNonemptyString(value)
    labels[key] = value
  }

  return Object.freeze(labels)
}

/** Substitute the five known slots once, leaving unfamiliar slots untouched. @internal */
export function fillDateTimePickerTemplate(
  template: string,
  slots: Readonly<
    Partial<Record<'time' | 'date' | 'zone' | 'from' | 'to', string>>
  >,
): string {
  return template.replace(
    /\{(time|date|zone|from|to)\}/g,
    (slot, key: string) => {
      const value = slots[key as keyof typeof slots]

      return value === undefined ? slot : value
    },
  )
}
