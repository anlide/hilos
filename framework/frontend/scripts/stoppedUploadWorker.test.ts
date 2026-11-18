import childProcess, { ChildProcess } from 'node:child_process'
import type { Stats } from 'node:fs'
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
function stand(owner = 1000) {
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
  vi.spyOn(process, 'getuid').mockReturnValue(owner)
  vi.spyOn(fs, 'stat').mockResolvedValue({ uid: owner } as Stats)
  const kill = vi.spyOn(process, 'kill').mockReturnValue(true)
  vi.spyOn(fs, 'readFile').mockImplementation(async (path) => {
    switch (String(path)) {
      case `/proc/${WORKER.pid}/cmdline`:
        return 'php\0backend/Bootstrap/worker.php\0--worker-id=3\0--monopolistic\0'
      case `/proc/${WORKER.pid}/environ`:
        return 'APP_ENV=test\0'
      case `/proc/${WORKER.pid}/status`:
        return `Uid:\t${owner}\t${owner}\t${owner}\t${owner}\n`
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
  for (const owner of [0, 1000]) {
    it.each([false, true])(
      `runs the bind-owner ${owner} protected flow with callback failure=%s`,
      async (fails) => {
        const s = stand(owner)
        s.proc.state = 'T'
        const failure = new Error('observation failed')
        const run = vi.fn(async ({ assertStopped }: Held) => {
          await assertStopped()
          if (fails) throw failure
        })
        const result = withStoppedUploadWorker(WORKER, s.record, run)
        if (fails) await expect(result).rejects.toBe(failure)
        else await result
        expect(fs.stat).toHaveBeenCalledWith('/hilos')
        expect(run).toHaveBeenCalledOnce()
        s.expectCleanup()
      },
    )
  }

  it.each([0, 1001, undefined])(
    'refuses checker UID %s for a nonroot bind',
    async (uid) => {
      const s = stand()
      vi.mocked(process.getuid).mockReturnValue(uid!)
      const run = vi.fn()
      await expect(
        withStoppedUploadWorker(WORKER, s.record, run),
      ).rejects.toThrow('Only the bind owner')
      expect(run).not.toHaveBeenCalled()
      expect(s.spawn).not.toHaveBeenCalled()
      expect(s.kill).not.toHaveBeenCalled()
    },
  )

  it.each([
    'Uid:\t1001\t1001\t1001\t1001\n',
    'Uid:\t1000\t0\t1000\t1000\n',
    'Uid:\t1000\t1000\t0\t1000\n',
    'Uid:\t1000\t1000\t1000\t0\n',
    'Uid:\t1000\t1000\t1000\n',
    'Uid:\t1000\t1000\t1000\tbad\n',
    'Uid:\t1000\t1000\t1000\t1000\textra\n',
    'Uid:\t1000\t1000\t1000\t1000\nUid:\t1000\t1000\t1000\t1000\n',
    'Name:\tphp\n',
  ])('refuses a foreign or malformed target UID: %j', async (status) => {
    const s = stand()
    const read = vi.mocked(fs.readFile).getMockImplementation()!
    vi.mocked(fs.readFile).mockImplementation(async (path, options) =>
      String(path).endsWith('/status') ? status : read(path, options),
    )
    const run = vi.fn()
    await expect(
      withStoppedUploadWorker(WORKER, s.record, run),
    ).rejects.toThrow('does not belong to the bind owner')
    expect(run).not.toHaveBeenCalled()
    expect(s.spawn).not.toHaveBeenCalled()
    expect(s.kill).not.toHaveBeenCalled()
  })

  it.each(['bind', 'status', 'cmdline', 'environ', 'stat'])(
    'fails closed on an unreadable %s',
    async (source) => {
      const s = stand()
      const failure = new Error('EACCES')
      if (source === 'bind') vi.mocked(fs.stat).mockRejectedValue(failure)
      else {
        const read = vi.mocked(fs.readFile).getMockImplementation()!
        vi.mocked(fs.readFile).mockImplementation(async (path, options) => {
          if (String(path).endsWith(`/${source}`)) throw failure
          return read(path, options)
        })
      }
      const run = vi.fn()
      await expect(withStoppedUploadWorker(WORKER, s.record, run)).rejects.toBe(
        failure,
      )
      expect(run).not.toHaveBeenCalled()
      expect(s.spawn).not.toHaveBeenCalled()
      expect(s.kill).not.toHaveBeenCalled()
    },
  )

  it.each([NaN, -1, 1.5])('refuses malformed bind UID %s', async (uid) => {
    const s = stand()
    vi.mocked(fs.stat).mockResolvedValue({ uid } as Stats)
    await expect(
      withStoppedUploadWorker(WORKER, s.record, vi.fn()),
    ).rejects.toThrow('Only the bind owner')
    expect(s.spawn).not.toHaveBeenCalled()
    expect(s.kill).not.toHaveBeenCalled()
  })

  it.each([
    { kind: 'ordinary' },
    { agentIds: [] },
    { agentIds: ['hilos_uploads', 'other'] },
    { agentIds: ['other'] },
    { index: 0 },
    { index: 1.5 },
    { pid: 1 },
    { pid: 1.5 },
  ])('refuses a foreign roster entry %j', async (change) => {
    const s = stand()
    await expect(
      withStoppedUploadWorker({ ...WORKER, ...change }, s.record, vi.fn()),
    ).rejects.toThrow('Only the isolated test')
    expect(s.spawn).not.toHaveBeenCalled()
    expect(s.kill).not.toHaveBeenCalled()
  })

  it.each([
    ['/environ', 'APP_ENV=prod\0'],
    ['/cmdline', 'php\0other.php\0--worker-id=3\0--monopolistic\0'],
    [
      '/cmdline',
      'php\0backend/Bootstrap/worker.php\0--worker-id=4\0--monopolistic\0',
    ],
    ['/cmdline', 'php\0backend/Bootstrap/worker.php\0--worker-id=3\0'],
    ['/stat', 'malformed'],
  ])('refuses mismatched process identity in %s: %j', async (source, value) => {
    const s = stand()
    const read = vi.mocked(fs.readFile).getMockImplementation()!
    vi.mocked(fs.readFile).mockImplementation(async (path, options) =>
      String(path).endsWith(source) ? value : read(path, options),
    )
    const run = vi.fn()
    await expect(
      withStoppedUploadWorker(WORKER, s.record, run),
    ).rejects.toBeInstanceOf(Error)
    expect(run).not.toHaveBeenCalled()
    expect(s.spawn).not.toHaveBeenCalled()
    expect(s.kill).not.toHaveBeenCalled()
  })

  it('never signals a PID replaced before STOP', async () => {
    const s = stand()
    const run = vi.fn()
    const record = (event: Record<string, unknown>) => {
      s.record(event)
      if (event.kind === 'worker-identity') s.proc.start = '279749999'
    }
    await expect(withStoppedUploadWorker(WORKER, record, run)).rejects.toThrow(
      'vanished before CONT',
    )
    expect(run).not.toHaveBeenCalled()
    expect(s.kill).not.toHaveBeenCalled()
    expect(s.killWatchdog).not.toHaveBeenCalled()
  })

  it.each(['before STOP', 'before CONT'])(
    'never signals a target whose UID changed %s',
    async (when) => {
      const s = stand()
      s.proc.state = 'T'
      const read = vi.mocked(fs.readFile).getMockImplementation()!
      let changed = false
      vi.mocked(fs.readFile).mockImplementation(async (path, options) =>
        changed && String(path).endsWith('/status')
          ? 'Uid:\t1001\t1001\t1001\t1001\n'
          : read(path, options),
      )
      const record = (event: Record<string, unknown>) => {
        s.record(event)
        if (event.kind === 'worker-identity' && when === 'before STOP')
          changed = true
      }
      await expect(
        withStoppedUploadWorker(WORKER, record, async () => {
          changed = true
        }),
      ).rejects.toThrow('does not belong to the bind owner')
      expect(s.kill.mock.calls).toEqual(
        when === 'before STOP' ? [] : [[WORKER.pid, 'SIGSTOP']],
      )
      expect(s.killWatchdog).not.toHaveBeenCalled()
    },
  )

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
    // Every readiness read is evidence, not only the one that found T.
    const stopping = s.events.filter(
      (event) => event.kind === 'worker-stopping',
    )
    expect(new Set(stopping.map((event) => event.state))).toEqual(
      new Set(['R', 'S', 'T']),
    )
    expect(stopping).toContainEqual({
      kind: 'worker-stopping',
      state: 'R',
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
    expect(s.spawn.mock.calls[0]?.[1]?.slice(-4)).toEqual([
      String(WORKER.pid),
      START,
      String(WINDOW_MS),
      '1000',
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
      ).rejects.toThrow(`The upload worker is not stopped: state ${state}`)
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
    ).rejects.toThrow('The upload worker is not stopped: state R')
    s.expectCleanup()
  })

  it.each([
    [
      'identity',
      `The upload worker changed incarnation: start ${START} became 279749999`,
    ],
    ['state', 'The upload worker is not stopped: state S'],
    [
      'deadline',
      `The uploads observation exceeded the watchdog window: ${WINDOW_MS} of ${WINDOW_MS} ms`,
    ],
  ])(
    'names the broken %s guarantee in a held observation',
    async (change, message) => {
      const s = stand()
      s.proc.state = 'T'
      const record = (event: Record<string, unknown>) => {
        s.record(event)
        // The original incarnation is back for the CONT that follows.
        if (event.kind === 'worker-held' && change === 'identity')
          s.proc.start = START
      }
      const run = vi.fn(async ({ assertStopped }: Held) => {
        if (change === 'identity') s.proc.start = '279749999'
        if (change === 'state') s.proc.state = 'S'
        if (change === 'deadline') vi.setSystemTime(NOW + WINDOW_MS)
        await assertStopped()
      })

      await expect(
        withStoppedUploadWorker(WORKER, record, run),
      ).rejects.toThrow(message)
      s.expectCleanup()
    },
  )

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
