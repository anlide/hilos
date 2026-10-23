import { hilosTimeZone } from '../../src/timeZone/timeZoneState.js'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { applyServerTime } from '../../src/session/serverClock.js'
import {
  TABLE_FROZEN_COPY,
  hilosTableFrozenLabel,
} from '../../src/table/tableFrozen.js'

/** A browser clock parked at a known moment, so a drift is a chosen number. */
const LOCAL_NOW = 1_700_000_000_000

/** One minute in ms — the drift the cases give the server clock. */
const MINUTE_MS = 60_000

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(LOCAL_NOW)
})

afterEach(() => {
  applyServerTime(Date.now())
  vi.useRealTimers()
})

describe('hilosTableFrozenLabel', () => {
  it('has nothing to say while the table is live', () => {
    expect(hilosTableFrozenLabel(null)).toBeNull()
  })

  it('says the absolute failure time in the reader zone', () => {
    // Clock correction must not shift the absolute moment of the failure.
    applyServerTime(LOCAL_NOW + MINUTE_MS)

    const label = hilosTableFrozenLabel(LOCAL_NOW + MINUTE_MS)

    expect(label).toBe(
      TABLE_FROZEN_COPY.bar.replace(
        '{time}',
        new Date(LOCAL_NOW + MINUTE_MS).toLocaleTimeString(),
      ),
    )
    expect(label).toContain('This table has not updated since')
    expect(label).not.toContain('{time}')
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
      expect(hilosTableFrozenLabel(Date.parse('2026-01-01T00:30:05Z'))).toBe(
        'This table has not updated since 4:30:05 PM. The rows shown may be out of date.',
      )
      expect(hilosTableFrozenLabel(null)).toBeNull()
    } finally {
      zone.mockRestore()
      applyServerTime(Date.now())
    }
  },
)
