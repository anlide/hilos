// A moment as the calendar date a person reads it on (HIL-494). One formatter so
// the three view layers name a day the same way: the date a removal of the second
// factor takes effect is said on the sign-in step, on its confirmation and in the
// profile, and three spellings of one date would read as three dates.

/**
 * A moment as its calendar date, in the reader's own locale and long form —
 * "September 24, 2026" — because the day is the whole of what is being said.
 *
 * @param moment The LOCAL epoch-ms moment.
 * @returns The date as the reader's locale writes it.
 */
export function formatCalendarDate(moment: number): string {
  return new Date(moment).toLocaleDateString(undefined, { dateStyle: 'long' })
}
