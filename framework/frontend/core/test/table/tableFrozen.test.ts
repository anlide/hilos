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

  it('says since when the table froze, on the reader own clock', () => {
    // The server runs a minute ahead: its moment of the failure is the reader's "now".
    applyServerTime(LOCAL_NOW + MINUTE_MS)

    const label = hilosTableFrozenLabel(LOCAL_NOW + MINUTE_MS)

    expect(label).toBe(
      TABLE_FROZEN_COPY.bar.replace(
        '{time}',
        new Date(LOCAL_NOW).toLocaleTimeString(),
      ),
    )
    expect(label).toContain('This table has not updated since')
    expect(label).not.toContain('{time}')
  })
})
