import { calendarDaysInMonth } from '../calendar/calendarDate.js'
import type { DateTimePickerFactors } from './dateTimePickerFactors.js'
import type { DateTimePickerNumericPart } from './dateTimePickerValue.js'

/** Internal numeric draft, including seconds while they are hidden. */
export type DateTimePickerTexts = Record<DateTimePickerNumericPart, string>

export function pickerPartWidth(part: DateTimePickerNumericPart): number {
  return part === 'year' ? 4 : 2
}

/** The editable range; an unknown February year deliberately permits day 31. */
export function pickerPartRange(
  part: DateTimePickerNumericPart,
  texts: DateTimePickerTexts,
  hourCycle: DateTimePickerFactors['hourCycle'],
): readonly [number, number] {
  switch (part) {
    case 'year':
      return [1, 9999]
    case 'month':
      return [1, 12]
    case 'day': {
      const month = Number(texts.month)
      const year = texts.year.length === 4 ? Number(texts.year) : 0
      return [
        1,
        month >= 1 &&
        month <= 12 &&
        (month !== 2 || (year >= 1 && year <= 9999))
          ? calendarDaysInMonth(year >= 1 && year <= 9999 ? year : 2000, month)
          : 31,
      ]
    }
    case 'hour':
      return hourCycle === 'h12' ? [1, 12] : [0, 23]
    default:
      return [0, 59]
  }
}

export function readPickerPart(
  part: DateTimePickerNumericPart,
  text: string,
  range: readonly [number, number],
): number | null {
  if (text === '' || (part === 'year' && text.length !== 4)) return null
  const value = Number(text)
  return value >= range[0] && value <= range[1] ? value : null
}

/** A short prefix below the minimum is incomplete, such as day or h12 hour 0. */
export function pickerPartInvalid(
  part: DateTimePickerNumericPart,
  text: string,
  range: readonly [number, number],
): boolean {
  if (text === '') return false
  const value = Number(text)
  return (
    value > range[1] ||
    (value < range[0] && text.length === pickerPartWidth(part))
  )
}

export function validatePickerPartText(
  part: DateTimePickerNumericPart,
  text: string,
): void {
  if (
    typeof text !== 'string' ||
    /[^0-9]/.test(text) ||
    text.length > pickerPartWidth(part)
  ) {
    throw new RangeError('Expected ASCII digits within the part width')
  }
}

/** Append or restart a digit and report completion for the view's focus move. */
export function inputPickerDigit(
  part: DateTimePickerNumericPart,
  text: string,
  digit: string,
  range: readonly [number, number],
): { text: string; complete: boolean } {
  const width = pickerPartWidth(part)
  let next = text + digit
  if (
    text.length === width ||
    (next.length === width &&
      (Number(next) < range[0] || Number(next) > range[1]))
  ) {
    next = digit
  }
  const complete = next.length === width || Number(next) * 10 > range[1]
  return { text: complete ? next.padStart(width, '0') : next, complete }
}

export function stepPickerNumber(
  value: number | null,
  range: readonly [number, number],
  direction: -1 | 1,
): number {
  if (value === null) return direction === 1 ? range[0] : range[1]
  return (
    range[0] +
    ((value - range[0] + direction + range[1] - range[0] + 1) %
      (range[1] - range[0] + 1))
  )
}

export function pickerHour23(hour: number, meridiem: 'am' | 'pm'): number {
  return (hour % 12) + (meridiem === 'pm' ? 12 : 0)
}

export function pickerHour12(hour: number): string {
  return String(hour % 12 || 12).padStart(2, '0')
}

export function pickerOffsetText(offsetSeconds: number): string {
  const absolute = Math.abs(offsetSeconds)
  const hours = String(Math.floor(absolute / 3600)).padStart(2, '0')
  const minutes = String(Math.floor((absolute % 3600) / 60)).padStart(2, '0')
  const seconds = absolute % 60
  return `UTC${offsetSeconds < 0 ? '-' : '+'}${hours}:${minutes}${seconds === 0 ? '' : `:${String(seconds).padStart(2, '0')}`}`
}
