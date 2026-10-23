import { afterEach, describe, expect, it, vi } from 'vitest'
import { type HilosConnection } from '../../src/connection/HilosConnection.js'
import { type ProjectSignal } from '../../src/protocol/parseSignal.js'
import { formatCalendarDate } from '../../src/format/date.js'
import { applyServerTime } from '../../src/session/serverClock.js'
import { computedSignal } from '../../src/state/signal.js'
import { bindTimeZoneState } from '../../src/timeZone/timeZoneState.js'

function connection() {
  const listeners = new Set<(frame: ProjectSignal) => void>()
  return {
    projectTimeZone: 'UTC',
    on(event: string, listener: (payload: never) => void): () => void {
      const receive = listener as (frame: ProjectSignal) => void
      if (event === 'projectSignal') listeners.add(receive)
      return () => {
        listeners.delete(receive)
      }
    },
    choose(timeZone: string | null): void {
      for (const receive of listeners)
        receive({
          type: 'handshake_response',
          data: { entities: { currentUser: { id: 1, timeZone } } },
        } as ProjectSignal)
    },
  }
}

afterEach(() => {
  vi.unstubAllEnvs()
  applyServerTime(Date.now())
})

describe('formatCalendarDate', () => {
  it.each([-300_000, 300_000])(
    'uses an absolute moment with browser and own zones at drift %i',
    (drift) => {
      vi.stubEnv('TZ', 'America/Los_Angeles')
      applyServerTime(Date.now() + drift)
      const socket = connection()
      const release = bindTimeZoneState(socket as unknown as HilosConnection)
      try {
        const at = Date.parse('2026-01-01T00:01:00Z')
        const date = computedSignal(() => formatCalendarDate(at))
        expect(date.get()).toBe('December 31, 2025')
        socket.choose('Europe/Kyiv')
        expect(date.get()).toBe('January 1, 2026')
        socket.choose(null)
        expect(date.get()).toBe('December 31, 2025')
        expect(formatCalendarDate(NaN)).toBe('—')
        expect(formatCalendarDate(Infinity)).toBe('—')
        socket.choose('UTC')
        const morning = Date.UTC(2026, 8, 24, 8, 0)
        const evening = Date.UTC(2026, 8, 24, 22, 30)
        expect(formatCalendarDate(morning)).toBe('September 24, 2026')
        expect(formatCalendarDate(evening)).toBe(formatCalendarDate(morning))
        expect(formatCalendarDate(morning)).toContain('2026')
      } finally {
        release()
      }
    },
  )
})
