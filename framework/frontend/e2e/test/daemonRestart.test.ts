import { EventEmitter } from 'node:events'
import { spawn, type ChildProcess } from 'node:child_process'
import type { Stats } from 'node:fs'
import { readFile, stat } from 'node:fs/promises'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { RESTART_AGENT, withStoppedRestartWorker } from '../daemonRestart.js'

vi.mock('node:child_process', () => ({ spawn: vi.fn() }))
vi.mock('node:fs/promises', () => ({ readFile: vi.fn(), stat: vi.fn() }))

const worker = {
  index: 8,
  pid: 12345,
  kind: 'monopolistic',
  agentIds: [RESTART_AGENT],
}

/** No real child or signal: these tests observe the backstop's ownership boundary. */
function watchdog() {
  const child = Object.assign(new EventEmitter(), {
    exitCode: null as number | null,
    signalCode: null as NodeJS.Signals | null,
    unref: vi.fn(),
    kill: vi.fn((signal: NodeJS.Signals) => {
      queueMicrotask(() => {
        child.signalCode = signal
        child.emit('exit', null, signal)
      })
      return true
    }),
  })
  vi.mocked(spawn).mockImplementationOnce(() => {
    queueMicrotask(() => child.emit('spawn'))
    return child as unknown as ChildProcess
  })
  return child
}

beforeEach(() => {
  vi.spyOn(process, 'getuid').mockReturnValue(1000)
  vi.mocked(stat).mockResolvedValue({ uid: 1000 } as Stats)
  vi.spyOn(process, 'kill').mockReturnValue(true)
  vi.mocked(readFile).mockImplementation(async (path) => {
    if (String(path).endsWith('/cmdline'))
      return `php\0backend/Bootstrap/worker.php\0--worker-id=${worker.index}\0--monopolistic\0`
    if (String(path).endsWith('/environ')) return 'APP_ENV=test\0'
    if (String(path).endsWith('/status'))
      return 'Uid:\t1000\t1000\t1000\t1000\n'
    return `12345 (php worker) ${['S', ...Array(18).fill('0'), '98765'].join(' ')}`
  })
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.resetAllMocks()
})

describe('isolated restart pause watchdog', () => {
  for (const fails of [false, true]) {
    it(`retires its watchdog after CONT when the callback ${fails ? 'fails' : 'succeeds'}`, async () => {
      const child = watchdog()
      const failure = new Error('observation failed')
      const result = withStoppedRestartWorker(worker, async () => {
        expect(child.kill).not.toHaveBeenCalled()
        if (fails) throw failure
      })
      if (fails) await expect(result).rejects.toBe(failure)
      else await result
      expect(process.kill).toHaveBeenNthCalledWith(1, worker.pid, 'SIGSTOP')
      expect(process.kill).toHaveBeenNthCalledWith(2, worker.pid, 'SIGCONT')
      expect(child.kill).toHaveBeenCalledExactlyOnceWith('SIGTERM')
      expect(child.signalCode).toBe('SIGTERM')
      expect(spawn).toHaveBeenCalledWith(
        process.execPath,
        [
          '-e',
          expect.stringContaining('now === start'),
          '12345',
          '98765',
          '10000',
          '1000',
        ],
        { detached: true, stdio: 'ignore' },
      )
    })
  }

  it.each([false, true])(
    'runs the root-owned bind flow with callback failure=%s',
    async (fails) => {
      vi.mocked(process.getuid).mockReturnValue(0)
      vi.mocked(stat).mockResolvedValue({ uid: 0 } as Stats)
      const read = vi.mocked(readFile).getMockImplementation()!
      vi.mocked(readFile).mockImplementation(async (path, options) =>
        String(path).endsWith('/status')
          ? 'Uid:\t0\t0\t0\t0\n'
          : read(path, options),
      )
      const child = watchdog()
      const failure = new Error('observation failed')
      const run = vi.fn(async () => {
        if (fails) throw failure
      })
      const result = withStoppedRestartWorker(worker, run)
      if (fails) await expect(result).rejects.toBe(failure)
      else await result
      expect(stat).toHaveBeenCalledWith('/hilos')
      expect(run).toHaveBeenCalledOnce()
      expect(process.kill).toHaveBeenNthCalledWith(1, worker.pid, 'SIGSTOP')
      expect(process.kill).toHaveBeenNthCalledWith(2, worker.pid, 'SIGCONT')
      expect(child.kill).toHaveBeenCalledExactlyOnceWith('SIGTERM')
    },
  )

  it.each([0, 1001, undefined])(
    'refuses checker UID %s for a nonroot bind',
    async (uid) => {
      vi.mocked(process.getuid).mockReturnValue(uid!)
      const run = vi.fn()
      await expect(withStoppedRestartWorker(worker, run)).rejects.toThrow(
        'Only the bind owner',
      )
      expect(run).not.toHaveBeenCalled()
      expect(spawn).not.toHaveBeenCalled()
      expect(process.kill).not.toHaveBeenCalled()
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
    const read = vi.mocked(readFile).getMockImplementation()!
    vi.mocked(readFile).mockImplementation(async (path, options) =>
      String(path).endsWith('/status') ? status : read(path, options),
    )
    const run = vi.fn()
    await expect(withStoppedRestartWorker(worker, run)).rejects.toThrow(
      'does not belong to the bind owner',
    )
    expect(run).not.toHaveBeenCalled()
    expect(spawn).not.toHaveBeenCalled()
    expect(process.kill).not.toHaveBeenCalled()
  })

  it.each(['bind', 'status', 'cmdline', 'environ', 'stat'])(
    'fails closed on an unreadable %s',
    async (source) => {
      const failure = new Error('EACCES')
      if (source === 'bind') vi.mocked(stat).mockRejectedValue(failure)
      else {
        const read = vi.mocked(readFile).getMockImplementation()!
        vi.mocked(readFile).mockImplementation(async (path, options) => {
          if (String(path).endsWith(`/${source}`)) throw failure
          return read(path, options)
        })
      }
      const run = vi.fn()
      await expect(withStoppedRestartWorker(worker, run)).rejects.toBe(failure)
      expect(run).not.toHaveBeenCalled()
      expect(spawn).not.toHaveBeenCalled()
      expect(process.kill).not.toHaveBeenCalled()
    },
  )

  it.each([NaN, -1, 1.5])('refuses malformed bind UID %s', async (uid) => {
    vi.mocked(stat).mockResolvedValue({ uid } as Stats)
    await expect(withStoppedRestartWorker(worker, vi.fn())).rejects.toThrow(
      'Only the bind owner',
    )
    expect(spawn).not.toHaveBeenCalled()
    expect(process.kill).not.toHaveBeenCalled()
  })

  it.each([
    { kind: 'ordinary' },
    { agentIds: [] },
    { agentIds: [RESTART_AGENT, 'other'] },
    { agentIds: ['other'] },
    { index: 0 },
    { index: 1.5 },
    { pid: 1 },
    { pid: 1.5 },
  ])('refuses a foreign roster entry %j', async (change) => {
    await expect(
      withStoppedRestartWorker({ ...worker, ...change }, vi.fn()),
    ).rejects.toThrow('Only the isolated test')
    expect(spawn).not.toHaveBeenCalled()
    expect(process.kill).not.toHaveBeenCalled()
  })

  it.each([
    ['/environ', 'APP_ENV=prod\0'],
    ['/cmdline', 'php\0other.php\0--worker-id=8\0--monopolistic\0'],
    [
      '/cmdline',
      'php\0backend/Bootstrap/worker.php\0--worker-id=9\0--monopolistic\0',
    ],
    ['/cmdline', 'php\0backend/Bootstrap/worker.php\0--worker-id=8\0'],
    ['/stat', 'malformed'],
  ])('refuses mismatched process identity in %s: %j', async (source, value) => {
    const read = vi.mocked(readFile).getMockImplementation()!
    vi.mocked(readFile).mockImplementation(async (path, options) =>
      String(path).endsWith(source) ? value : read(path, options),
    )
    const run = vi.fn()
    await expect(withStoppedRestartWorker(worker, run)).rejects.toBeInstanceOf(
      Error,
    )
    expect(run).not.toHaveBeenCalled()
    expect(spawn).not.toHaveBeenCalled()
    expect(process.kill).not.toHaveBeenCalled()
  })

  it.each(['before STOP', 'before CONT'])(
    'never signals a reused PID %s',
    async (when) => {
      const child = watchdog()
      const read = vi.mocked(readFile).getMockImplementation()!
      let changed = false
      let births = 0
      vi.mocked(readFile).mockImplementation(async (path, options) => {
        if (String(path).endsWith('/stat')) {
          births++
          if (changed || (when === 'before STOP' && births > 1))
            return `12345 (other) ${['S', ...Array(18).fill('0'), '99999'].join(' ')}`
        }
        return read(path, options)
      })
      const run = vi.fn(async () => {
        changed = true
      })
      await expect(withStoppedRestartWorker(worker, run)).rejects.toThrow(
        when === 'before STOP' ? 'changed before STOP' : 'vanished before CONT',
      )
      expect(vi.mocked(process.kill).mock.calls).toEqual(
        when === 'before STOP' ? [] : [[worker.pid, 'SIGSTOP']],
      )
      expect(child.kill).not.toHaveBeenCalled()
    },
  )

  it.each(['before STOP', 'before CONT'])(
    'never signals a target whose UID changed %s',
    async (when) => {
      const child = watchdog()
      const read = vi.mocked(readFile).getMockImplementation()!
      let changed = false
      let statuses = 0
      vi.mocked(readFile).mockImplementation(async (path, options) => {
        if (String(path).endsWith('/status')) {
          statuses++
          if (changed || (when === 'before STOP' && statuses > 1))
            return 'Uid:\t1001\t1001\t1001\t1001\n'
        }
        return read(path, options)
      })
      await expect(
        withStoppedRestartWorker(worker, async () => {
          changed = true
        }),
      ).rejects.toThrow('does not belong to the bind owner')
      expect(vi.mocked(process.kill).mock.calls).toEqual(
        when === 'before STOP' ? [] : [[worker.pid, 'SIGSTOP']],
      )
      expect(child.kill).not.toHaveBeenCalled()
    },
  )

  it('keeps the independent backstop armed if explicit CONT fails', async () => {
    const child = watchdog()
    const failure = new Error('CONT failed')
    vi.mocked(process.kill).mockImplementation((_pid, signal) => {
      if (signal === 'SIGCONT') throw failure
      return true
    })
    await expect(withStoppedRestartWorker(worker, async () => {})).rejects.toBe(
      failure,
    )
    expect(child.kill).not.toHaveBeenCalled()
  })

  it('joins the retiring watchdog before the next pause may begin', async () => {
    const child = watchdog()
    let retiring!: () => void
    const retirement = new Promise<void>((resolve) => {
      retiring = resolve
    })
    child.kill.mockImplementation(() => {
      retiring()
      return true
    })
    let finished = false
    const result = withStoppedRestartWorker(worker, async () => {}).then(() => {
      finished = true
    })
    await retirement
    expect(finished).toBe(false)
    expect(process.kill).toHaveBeenLastCalledWith(worker.pid, 'SIGCONT')
    child.signalCode = 'SIGTERM'
    child.emit('exit', null, 'SIGTERM')
    await result
    expect(finished).toBe(true)
  })

  it('does not wait for an already exited watchdog and keeps the pause red', async () => {
    const child = watchdog()
    vi.spyOn(Date, 'now').mockReturnValueOnce(1000).mockReturnValue(11000)
    await expect(
      withStoppedRestartWorker(worker, async () => {
        child.exitCode = 0
      }),
    ).rejects.toThrow('exceeded its safe pause window')
    expect(process.kill).toHaveBeenLastCalledWith(worker.pid, 'SIGCONT')
    expect(child.kill).not.toHaveBeenCalled()
  })

  it('still refuses observations that exhaust the ten-second pause', async () => {
    const child = watchdog()
    vi.spyOn(Date, 'now').mockReturnValueOnce(1000).mockReturnValue(11000)
    await expect(
      withStoppedRestartWorker(worker, async () => {}),
    ).rejects.toThrow('exceeded its safe pause window')
    expect(process.kill).toHaveBeenLastCalledWith(worker.pid, 'SIGCONT')
    expect(child.kill).toHaveBeenCalledExactlyOnceWith('SIGTERM')
  })
})
