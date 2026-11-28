// The date picker's display factors that the tab's region decides: the order
// of the date parts and their separator from the region's date template, and
// the twelve- or twenty-four-hour clock from its time template — the same
// templates the core formatter writes with. The first day of the week joins
// them here when the tab learns it (HIL-2318).
import { hilosReaderFormats } from '../format/readerDisplay.js'
import {
  DEFAULT_DATE_TIME_PICKER_FACTORS,
  type DateTimePickerDatePart,
  type DateTimePickerFactors,
} from './dateTimePickerFactors.js'

/** The region's date factors the picker takes. */
type TabPickerFactors = Pick<
  DateTimePickerFactors,
  'dateOrder' | 'dateSeparator' | 'hourCycle'
>

/** A date template: three tokens with the same separator between them. */
const DATE_TEMPLATE = /^(YYYY|MM|DD)(\D*)(YYYY|MM|DD)\2(YYYY|MM|DD)$/u
const DATE_PARTS: Readonly<Record<string, DateTimePickerDatePart>> = {
  YYYY: 'year',
  MM: 'month',
  DD: 'day',
}
/** The token of a twelve-hour clock in a time template. */
const TWELVE_HOUR_TOKEN = 'hh'

/**
 * The picker factors of the tab's region, read now: a picker takes them when
 * it opens, as it takes the zone, and an open picker keeps them. A template
 * that is not three distinct date tokens keeps the picker's default order and
 * separator.
 *
 * @returns The date order, the date separator and the hour cycle.
 */
export function hilosTabPickerFactors(): TabPickerFactors {
  const formats = hilosReaderFormats()
  const hourCycle = formats.time.includes(TWELVE_HOUR_TOKEN) ? 'h12' : 'h23'
  const parts = DATE_TEMPLATE.exec(formats.date)
  if (parts === null || new Set([parts[1], parts[3], parts[4]]).size !== 3) {
    return {
      dateOrder: DEFAULT_DATE_TIME_PICKER_FACTORS.dateOrder,
      dateSeparator: DEFAULT_DATE_TIME_PICKER_FACTORS.dateSeparator,
      hourCycle,
    }
  }

  return {
    dateOrder: [
      DATE_PARTS[parts[1]],
      DATE_PARTS[parts[3]],
      DATE_PARTS[parts[4]],
    ],
    dateSeparator: parts[2],
    hourCycle,
  }
}
