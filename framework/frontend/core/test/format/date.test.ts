// Covers the calendar-date formatter (HIL-494): the day of a moment in the
// reader's locale, whatever the time of that day.
import { describe, expect, it } from 'vitest'
import { formatCalendarDate } from '../../src/format/date.js'

describe('formatCalendarDate', () => {
  it('names the day of the moment, and the same day for any hour of it', () => {
    const morning = new Date(2026, 8, 24, 8, 0).getTime()
    const evening = new Date(2026, 8, 24, 22, 30).getTime()

    expect(formatCalendarDate(morning)).toBe(
      new Date(2026, 8, 24).toLocaleDateString(undefined, {
        dateStyle: 'long',
      }),
    )
    expect(formatCalendarDate(evening)).toBe(formatCalendarDate(morning))
    expect(formatCalendarDate(morning)).toContain('2026')
  })
})
