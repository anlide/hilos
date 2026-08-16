import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  applyServerTime,
  offsetMs,
  toLocal,
} from '../../src/session/serverClock.js'

/** A browser clock parked at a known moment, so a drift is a chosen number. */
const LOCAL_NOW = 1_700_000_000_000

/** One minute in ms — the drift the cases give the server clock. */
const MINUTE_MS = 60_000

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(LOCAL_NOW)
})

afterEach(() => {
  vi.useRealTimers()
})

describe('server clock', () => {
  it('reads a server moment as itself until a handshake measures the drift', () => {
    expect(offsetMs()).toBe(0)
    expect(toLocal(LOCAL_NOW + MINUTE_MS)).toBe(LOCAL_NOW + MINUTE_MS)
  })

  it('converts a server moment onto the local scale, whichever clock is ahead', () => {
    applyServerTime(LOCAL_NOW + MINUTE_MS)
    expect(offsetMs()).toBe(MINUTE_MS)
    // A code that expires a minute from the SERVER's now is a minute from the
    // browser's now too — which is the whole point: the countdown is the same
    // length on a browser whose clock is a minute behind.
    expect(toLocal(LOCAL_NOW + 2 * MINUTE_MS)).toBe(LOCAL_NOW + MINUTE_MS)

    applyServerTime(LOCAL_NOW - MINUTE_MS)
    expect(offsetMs()).toBe(-MINUTE_MS)
    expect(toLocal(LOCAL_NOW)).toBe(LOCAL_NOW + MINUTE_MS)
  })

  it('re-measures on every handshake, so a slept laptop comes back correct', () => {
    applyServerTime(LOCAL_NOW + MINUTE_MS)
    // The tab slept an hour and its clock came back a minute further behind.
    // Only a fresh measurement can tell, which is why the offset is taken again
    // on every handshake rather than once at boot.
    vi.setSystemTime(LOCAL_NOW + 60 * MINUTE_MS)
    applyServerTime(LOCAL_NOW + 62 * MINUTE_MS)
    expect(offsetMs()).toBe(2 * MINUTE_MS)
  })
})
