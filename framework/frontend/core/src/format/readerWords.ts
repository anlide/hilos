// The words a written date carries: month and weekday names, the three named
// days and the two halves of a twelve-hour clock. The region's templates are
// numeric only, so every word inside a date is an interface string — English,
// like the rest of the interface, until the interface is translated (HIL-2280).
// This module is the one place they change; the formatter and the date picker
// read the same names.
import { DEFAULT_DATE_TIME_PICKER_FACTORS } from '../primitives/dateTimePickerFactors.js'

/** Full month names, January first: index is the month minus one. */
export const READER_MONTH_NAMES: readonly string[] = Object.freeze(
  DEFAULT_DATE_TIME_PICKER_FACTORS.monthNames.map((name) => name.full),
)

/** Full weekday names, Sunday first: index 0 is Sunday. */
export const READER_WEEKDAY_NAMES: readonly string[] = Object.freeze(
  DEFAULT_DATE_TIME_PICKER_FACTORS.weekdayNames.map((name) => name.full),
)

/** The morning half of a twelve-hour clock. */
export const READER_AM = DEFAULT_DATE_TIME_PICKER_FACTORS.amLabel

/** The afternoon half of a twelve-hour clock. */
export const READER_PM = DEFAULT_DATE_TIME_PICKER_FACTORS.pmLabel

/** The local day before the reference day. */
export const READER_YESTERDAY = 'yesterday'

/** The reference day itself. */
export const READER_TODAY = 'today'

/** The local day after the reference day. */
export const READER_TOMORROW = 'tomorrow'
