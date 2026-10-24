import childProcess, { ChildProcess } from 'node:child_process'
import fs from 'node:fs/promises'
import { syncBuiltinESMExports } from 'node:module'
import process from 'node:process'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { withStoppedUploadWorker } from './stoppedUploadWorker.mjs'

const WORKER = {
  index: 3,
  pid: 43,
  kind: 'monopolistic',
  agentIds: ['hilos_uploads'],
}
const START = '279749231'
const NOW = 1_000_000
const WINDOW_MS = 10_000
type Held = Parameters<Parameters<typeof withStoppedUploadWorker>[2]>[0]

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(NOW)
})

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
  syncBuiltinESMExports()
})

/**
 * Replace only the Linux/process boundary: no host PID receives a signal and
 * no child is spawned. The real helper still decides admission and cleanup.
 */
function stand() {
  const proc = { state: 'R', start: START, missing: false }
  const events: Record<string, unknown>[] = []
  const watchdog = new ChildProcess()
  const unref = vi.spyOn(watchdog, 'unref').mockImplementation(() => {})
  const killWatchdog = vi.spyOn(watchdog, 'kill').mockImplementation(() => {
    queueMicrotask(() => watchdog.emit('exit', null, 'SIGTERM'))
    return true
  })
  const spawn = vi.spyOn(childProcess, 'spawn').mockImplementation(() => {
    queueMicrotask(() => watchdog.emit('spawn'))
    return watchdog
  })
  vi.spyOn(process, 'getuid').mockReturnValue(0)
  const kill = vi.spyOn(process, 'kill').mockReturnValue(true)
  vi.spyOn(fs, 'readFile').mockImplementation(async (path) => {
    switch (String(path)) {
      case `/proc/${WORKER.pid}/cmdline`:
        return 'php\0backend/Bootstrap/worker.php\0--worker-id=3\0--monopolistic\0'
      case `/proc/${WORKER.pid}/environ`:
        return 'APP_ENV=test\0'
      case `/proc/${WORKER.pid}/stat`:
        if (proc.missing) throw new Error('ENOENT: worker vanished')
        return `${WORKER.pid} (upload worker) ${[proc.state, ...Array<string>(18).fill('0'), proc.start].join(' ')}`
      default:
        throw new Error(`Unexpected procfs read: ${String(path)}`)
    }
  })
  syncBuiltinESMExports()
  const record = (event: Record<string, unknown>) => events.push(event)
  const expectCleanup = () => {
    expect(kill.mock.calls).toEqual([
      [WORKER.pid, 'SIGSTOP'],
      [WORKER.pid, 'SIGCONT'],
    ])
    expect(killWatchdog).toHaveBeenCalledExactlyOnceWith('SIGTERM')
    expect(unref).toHaveBeenCalledOnce()
    expect(events.at(-1)).toMatchObject({
      kind: 'worker-cont',
      pid: WORKER.pid,
      start: START,
    })
  }
  return { proc, events, kill, killWatchdog, spawn, record, expectCleanup }
}

describe('the stopped upload worker readiness boundary', () => {
  it('admits observations only after R then S becomes T on the same incarnation', async () => {
    const s = stand()
    const run = vi.fn(async ({ assertStopped }: Held) => {
      expect(s.proc).toMatchObject({ state: 'T', start: START })
      await assertStopped()
    })
    const result = withStoppedUploadWorker(WORKER, s.record, run).catch(
      (error: unknown) => error,
    )

    await vi.advanceTimersByTimeAsync(20)
    expect(s.kill).toHaveBeenCalledWith(WORKER.pid, 'SIGSTOP')
    expect(run).not.toHaveBeenCalled()
    s.proc.state = 'S'
    await vi.advanceTimersByTimeAsync(20)
    expect(run).not.toHaveBeenCalled()
    s.proc.state = 'T'
    await vi.advanceTimersByTimeAsync(20)

    expect(await result).toBeUndefined()
    expect(run).toHaveBeenCalledOnce()
    expect(s.events).toContainEqual({
      kind: 'worker-ready',
      state: 'T',
      start: START,
      elapsedMs: expect.any(Number),
    })
    // The initial, callback and final observations remain strict and distinct.
    expect(s.events.filter((event) => event.kind === 'worker-held')).toEqual(
      Array.from({ length: 3 }, () => ({
        kind: 'worker-held',
        state: 'T',
        start: START,
        elapsedMs: expect.any(Number),
      })),
    )
    expect(s.spawn).toHaveBeenCalledOnce()
    expect(s.spawn.mock.calls[0]?.[1]?.slice(-3)).toEqual([
      String(WORKER.pid),
      START,
      String(WINDOW_MS),
    ])
    s.expectCleanup()
  })

  it.each(['state', 'identity', 'deadline'])(
    'rechecks %s after readiness before admitting the callback',
    async (change) => {
      const s = stand()
      const run = vi.fn()
      s.proc.state = 'T'
      const record = (event: Record<string, unknown>) => {
        s.record(event)
        if (event.kind !== 'worker-ready') return
        if (change === 'state') s.proc.state = 'R'
        if (change === 'identity') s.proc.start = '279749999'
        if (change === 'deadline') vi.setSystemTime(NOW + WINDOW_MS)
      }

      await expect(
        withStoppedUploadWorker(WORKER, record, run),
      ).rejects.toBeInstanceOf(Error)
      expect(run).not.toHaveBeenCalled()
      if (change === 'identity') {
        expect(s.kill.mock.calls).toEqual([[WORKER.pid, 'SIGSTOP']])
        expect(s.killWatchdog).not.toHaveBeenCalled()
      } else s.expectCleanup()
    },
  )

  it.each(['R', 'T'])(
    'refuses readiness state %s at the original deadline and resumes the worker',
    async (state) => {
      const s = stand()
      const run = vi.fn()
      const result = withStoppedUploadWorker(WORKER, s.record, run).catch(
        (error: unknown) => error,
      )
      await vi.advanceTimersByTimeAsync(0)
      expect(s.kill).toHaveBeenCalledWith(WORKER.pid, 'SIGSTOP')
      vi.setSystemTime(NOW + WINDOW_MS)
      s.proc.state = state
      await vi.advanceTimersByTimeAsync(20)

      expect(await result).toBeInstanceOf(Error)
      expect(run).not.toHaveBeenCalled()
      expect(s.events.some((event) => event.kind === 'worker-ready')).toBe(
        false,
      )
      s.expectCleanup()
    },
  )

  it('keeps readiness time inside the subsequent observation budget', async () => {
    const s = stand()
    const run = vi.fn(async ({ assertStopped }: Held) => {
      vi.setSystemTime(NOW + WINDOW_MS)
      await assertStopped()
    })
    const result = withStoppedUploadWorker(WORKER, s.record, run).catch(
      (error: unknown) => error,
    )
    await vi.advanceTimersByTimeAsync(0)
    vi.setSystemTime(NOW + WINDOW_MS - 50)
    s.proc.state = 'T'
    await vi.advanceTimersByTimeAsync(20)

    expect(run).toHaveBeenCalledOnce()
    expect(await result).toMatchObject({
      message: expect.stringContaining('watchdog window'),
    })
    expect(s.events).toContainEqual({
      kind: 'worker-held',
      state: 'T',
      start: START,
      elapsedMs: WINDOW_MS,
    })
    s.expectCleanup()
  })

  it.each(['R', 'S', 'Z'])(
    'refuses a subsequent %s observation without waiting for T again',
    async (state) => {
      const s = stand()
      const run = vi.fn(async ({ assertStopped }: Held) => {
        s.proc.state = state
        await assertStopped()
      })
      s.proc.state = 'T'
      await expect(
        withStoppedUploadWorker(WORKER, s.record, run),
      ).rejects.toThrow('exact stopped incarnation or watchdog window')
      expect(run).toHaveBeenCalledOnce()
      s.expectCleanup()
    },
  )

  it('still checks the stopped incarnation after the callback returns', async () => {
    const s = stand()
    s.proc.state = 'T'
    await expect(
      withStoppedUploadWorker(WORKER, s.record, async () => {
        s.proc.state = 'R'
      }),
    ).rejects.toThrow('exact stopped incarnation or watchdog window')
    s.expectCleanup()
  })

  it('refuses a zombie during readiness and cleans up', async () => {
    const s = stand()
    const run = vi.fn()
    const result = withStoppedUploadWorker(WORKER, s.record, run).catch(
      (error: unknown) => error,
    )
    await vi.advanceTimersByTimeAsync(0)
    s.proc.state = 'Z'
    await vi.advanceTimersByTimeAsync(20)

    expect(await result).toBeInstanceOf(Error)
    expect(run).not.toHaveBeenCalled()
    s.expectCleanup()
  })

  it.each(['replaced', 'missing'])(
    'never signals another incarnation when the PID is %s during readiness',
    async (change) => {
      const s = stand()
      const run = vi.fn()
      const result = withStoppedUploadWorker(WORKER, s.record, run).catch(
        (error: unknown) => error,
      )
      await vi.advanceTimersByTimeAsync(0)
      s.proc.state = 'T'
      if (change === 'replaced') s.proc.start = '279749999'
      else s.proc.missing = true
      await vi.advanceTimersByTimeAsync(20)

      expect(await result).toBeInstanceOf(Error)
      expect(run).not.toHaveBeenCalled()
      expect(s.kill.mock.calls).toEqual([[WORKER.pid, 'SIGSTOP']])
      // Keep the independent, birth-guarded backstop armed if CONT is unsafe.
      expect(s.killWatchdog).not.toHaveBeenCalled()
    },
  )
})
