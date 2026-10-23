import { applyServerTime } from '../../../src/session/serverClock.js'
import { hilosTimeZone } from '../../../src/timeZone/timeZoneState.js'
import { describe, expect, it, vi } from 'vitest'

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

it.each([-300_000, 300_000])(
  'displays absolute moments in the reader zone with clock drift %i',
  (drift) => {
    const zone = vi
      .spyOn(hilosTimeZone, 'get')
      .mockReturnValue('America/Los_Angeles')
    applyServerTime(Date.now() + drift)
    try {
      expect(formatAnalyticsTime(Date.parse('2026-01-01T00:30:05Z'))).toBe(
        'Dec 31, 2025, 4:30:05 PM',
      )
      expect(formatAnalyticsTime(NaN)).toBe('Invalid Date')
    } finally {
      zone.mockRestore()
      applyServerTime(Date.now())
    }
  },
)
