import { describe, expect, it } from 'vitest'

import {
  ANALYTICS_NO_VALUE,
  formatAnalyticsTime,
  formatAnalyticsSessionNumber,
  readAnalyticsRouteId,
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

describe('readAnalyticsRouteId', () => {
  it('reads a positive integer, with or without a plus sign', () => {
    expect(readAnalyticsRouteId('57')).toBe(57)
    expect(readAnalyticsRouteId('+57')).toBe(57)
  })

  it.each([
    undefined,
    '',
    '0',
    '-3',
    '1.5',
    '1e3',
    'abc',
    ' 7',
    '9007199254740993',
  ])('gives null for %j', (raw) => {
    expect(readAnalyticsRouteId(raw)).toBeNull()
  })
})
