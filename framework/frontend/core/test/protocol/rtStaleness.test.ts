import { describe, expect, it, vi } from 'vitest'
import { applyServerTime } from '../../src/session/serverClock.js'
import { hilosTimeZone } from '../../src/timeZone/timeZoneState.js'
import {
  rtStalenessLabel,
  rtStalenessCompactLabel,
  toRtStalenessStatus,
  RT_STALENESS_FRESH,
} from '../../src/protocol/rtStaleness.js'

describe('runtime staleness moment', () => {
  it.each([-300_000, 300_000])('preserves UTC at clock drift %i', (drift) => {
    const engineDateTimeFormat = Intl.DateTimeFormat
    const locale = vi
      .spyOn(Intl, 'DateTimeFormat')
      .mockImplementation(function (_locales, options) {
        return new engineDateTimeFormat('en-US', options)
      })
    const clock = vi
      .spyOn(Date, 'now')
      .mockReturnValue(Date.parse('2026-01-01T00:00:00Z'))
    const zone = vi
      .spyOn(hilosTimeZone, 'get')
      .mockReturnValue('America/Los_Angeles')
    applyServerTime(Date.now() + drift)
    try {
      expect(
        rtStalenessLabel({
          stale: true,
          since: Date.parse('2026-01-01T00:30:05Z'),
        }),
      ).toBe(
        'Some data here may be out of date - the link to its source was lost at 4:30:05 PM.',
      )
      expect(
        rtStalenessCompactLabel({
          stale: true,
          since: Date.parse('2026-01-01T00:30:05Z'),
        }),
      ).toBe('Some data may be out of date since 4:30:05 PM')
      expect(rtStalenessLabel({ stale: true, since: undefined })).toBe(
        'Some data here may be out of date - the link to its source was lost at an unknown time.',
      )
      expect(rtStalenessCompactLabel({ stale: true, since: undefined })).toBe(
        'Some data may be out of date since an unknown time',
      )
      expect(rtStalenessLabel(RT_STALENESS_FRESH)).toBeUndefined()
      expect(rtStalenessCompactLabel(RT_STALENESS_FRESH)).toBeUndefined()
      expect(toRtStalenessStatus({ stale: true, since: 1000 })).toEqual({
        stale: true,
        since: 1000,
      })
      expect(toRtStalenessStatus({ stale: true, since: null })).toEqual({
        stale: true,
        since: undefined,
      })
      expect(toRtStalenessStatus(undefined)).toBe(RT_STALENESS_FRESH)
      expect(toRtStalenessStatus({ stale: false, since: 1000 })).toBe(
        RT_STALENESS_FRESH,
      )
    } finally {
      zone.mockRestore()
      clock.mockRestore()
      locale.mockRestore()
      applyServerTime(Date.now())
    }
  })
})
