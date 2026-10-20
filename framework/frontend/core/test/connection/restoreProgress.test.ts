import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  BACKUP_RESTORE_PROGRESS_SIGNAL,
  createBackupProgressClock,
  createHilosConnection,
  createHilosRestoreProgress,
  elapsedSecondsSince,
  formatRestorePhaseLine,
  type HilosRestoreStatus,
  type WebSocketLike,
} from '../../src/base/index.js'

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

afterEach(() => vi.useRealTimers())

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
