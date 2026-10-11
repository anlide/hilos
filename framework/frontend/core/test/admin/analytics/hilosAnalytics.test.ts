import { describe, expect, it } from 'vitest'

import {
  ANALYTICS_NO_VALUE,
  formatAnalyticsTime,
  formatAnalyticsSessionNumber,
} from '../../../src/index.js'

describe('Analytics labels', () => {
  it('formats source milliseconds as local date and time with seconds', () => {
    const ms = Date.UTC(2026, 9, 9, 10, 11, 12, 123)
    expect(formatAnalyticsTime(ms)).toBe(
      new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'medium',
      }).format(ms),
    )
  })

  it('keeps an absent-value label separate from a session number', () => {
    expect(ANALYTICS_NO_VALUE).toBe('—')
    expect(formatAnalyticsSessionNumber(57)).toBe('#57')
  })
})
