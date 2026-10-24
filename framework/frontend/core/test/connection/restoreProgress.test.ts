import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  BACKUP_RESTORE_PROGRESS_SIGNAL,
  backupProgressPercent,
  createBackupProgressClock,
  createHilosConnection,
  createHilosRestoreProgress,
  elapsedSecondsSince,
  formatBackupEta,
  formatBackupProgressLabel,
  formatRestorePhaseLine,
  type HilosRestoreStatus,
  type WebSocketLike,
} from '../../src/base/index.js'
import { applyServerTime, offsetMs } from '../../src/session/serverClock.js'
import {
  bindTimeZoneState,
  hilosTimeZone,
} from '../../src/timeZone/timeZoneState.js'

/** A socket driven only by frames the test explicitly supplies. */
class Socket implements WebSocketLike {
  readonly send = vi.fn()
  readonly close = vi.fn()
  private readonly listeners = new Map<
    string,
    Array<(event: { data?: unknown }) => void>
  >()

  addEventListener(
    type: string,
    listener: (event: { data?: unknown }) => void,
  ): void {
    const list = this.listeners.get(type) ?? []
    list.push(listener)
    this.listeners.set(type, list)
  }

  emit(type: string, event: { data?: unknown } = {}): void {
    for (const listener of this.listeners.get(type) ?? []) listener(event)
  }
}

const status: HilosRestoreStatus = {
  running: true,
  backupId: 'b1',
  scope: 'full',
  phase: 'importing',
  phaseStartedAt: '2026-08-24T00:00:00Z',
  startedAt: '2026-08-24T00:00:00Z',
  finishedAt: null,
  outcome: null,
  failureReason: null,
  estimatedSeconds: 100,
  rehydrateComplete: false,
  rehydrateProblems: [],
  databaseTouched: true,
}

afterEach(() => {
  vi.useRealTimers()
  applyServerTime(Date.now())
})

describe('the shared restore shell', () => {
  it('listens only while started, without an admin import, page scope or action', () => {
    const socket = new Socket()
    const { connection } = createHilosConnection({
      url: 'ws://test/ws',
      webSocketFactory: () => socket,
    })
    const unknown = vi.fn()
    connection.on('unknownSignal', unknown)
    const progress = createHilosRestoreProgress(connection)
    const anotherTab = createHilosRestoreProgress(connection)
    connection.connect()
    socket.emit('open')
    function push(data: HilosRestoreStatus): void {
      socket.emit('message', {
        data: JSON.stringify({ type: BACKUP_RESTORE_PROGRESS_SIGNAL, data }),
      })
    }
    try {
      push(status)
      expect(progress.status.get()).toBeNull()
      expect(anotherTab.status.get()).toBeNull()
      progress.start()
      push(status)
      expect(progress.status.get()).toEqual(status)
      expect(anotherTab.status.get()).toBeNull()
      progress.dispose()
      progress.dispose()
      const terminal = { ...status, running: false, phase: 'succeeded' }
      push(terminal)
      expect(progress.status.get()).toEqual(status)
      progress.start()
      expect(progress.status.get()).toEqual(status)
      push(terminal)
      expect(progress.status.get()).toEqual(terminal)
      expect(unknown).not.toHaveBeenCalled()
      expect(socket.send).not.toHaveBeenCalled()
    } finally {
      progress.dispose()
      anotherTab.dispose()
      connection.close()
    }
  })

  it('ticks once a second until disposed, retaining the last published time', () => {
    vi.useFakeTimers()
    const start = Date.parse('2026-08-24T00:00:00Z')
    vi.setSystemTime(start)
    const clock = createBackupProgressClock()
    expect(clock.now.get()).toBe(start)
    vi.advanceTimersByTime(999)
    expect(clock.now.get()).toBe(start)
    vi.advanceTimersByTime(1)
    expect(clock.now.get()).toBe(start + 1000)
    clock.dispose()
    clock.dispose()
    vi.advanceTimersByTime(5000)
    expect(clock.now.get()).toBe(start + 1000)
    expect(vi.getTimerCount()).toBe(0)
  })

  it.each([-300000, 0, 300000])(
    'keeps Z-anchor durations, percent and ETA independent of reader zone and clock offset %i',
    (clockOffset) => {
      vi.useFakeTimers()
      const now = Date.parse('2026-08-24T00:00:30Z')
      vi.setSystemTime(now)
      applyServerTime(now + clockOffset)
      const socket = new Socket()
      const { connection } = createHilosConnection({
        url: 'ws://test/ws',
        webSocketFactory: () => socket,
      })
      const release = bindTimeZoneState(connection)
      const clock = createBackupProgressClock()
      connection.connect()
      socket.emit('open')
      try {
        for (const timeZone of ['UTC', 'America/New_York', 'Asia/Tokyo']) {
          socket.emit('message', {
            data: JSON.stringify({
              type: 'handshake_response',
              data: { entities: { currentUser: { id: 1, timeZone } } },
            }),
          })
          expect(hilosTimeZone.get()).toBe(timeZone)
          expect(offsetMs()).toBe(clockOffset)
          expect(clock.now.get()).toBe(now)
          for (const anchors of [
            {
              ...status,
              startedAt: '2026-08-24T00:00:00.000000Z',
              phaseStartedAt: '2026-08-24T00:00:20.000000Z',
            },
            {
              ...status,
              startedAt: '2026-08-24T02:00:00+02:00',
              phaseStartedAt: '2026-08-24T02:00:20+02:00',
            },
          ]) {
            expect(elapsedSecondsSince(anchors.startedAt, now)).toBe(30)
            expect(elapsedSecondsSince(anchors.phaseStartedAt, now)).toBe(10)
            expect(backupProgressPercent(anchors, now)).toBe(30)
            expect(formatBackupEta(anchors, now)).toBe('~70s left')
            expect(formatBackupProgressLabel(anchors, now)).toBe(
              'importing · 30% · ~70s left',
            )
          }
        }
      } finally {
        release()
        clock.dispose()
        connection.close()
      }
    },
  )

  it('keeps absent and malformed instants and unnamed phases honest', () => {
    expect(elapsedSecondsSince(null, 1000)).toBeNull()
    expect(elapsedSecondsSince('not an instant', 1000)).toBeNull()
    expect(elapsedSecondsSince('1970-01-01T00:00:00Z', 1500)).toBe(1.5)
    expect(formatRestorePhaseLine(status)).toBe('Restore b1 · importing')
    expect(
      formatRestorePhaseLine({ ...status, backupId: null, phase: '' }),
    ).toBe('Restore')
    expect(formatRestorePhaseLine({ ...status, phase: null })).toBe(
      'Restore b1',
    )
  })
})
