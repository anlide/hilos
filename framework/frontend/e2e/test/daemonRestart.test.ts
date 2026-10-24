import { EventEmitter } from 'node:events'
import { spawn, type ChildProcess } from 'node:child_process'
import { readFile } from 'node:fs/promises'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { RESTART_AGENT, withStoppedRestartWorker } from '../daemonRestart.js'

vi.mock('node:child_process', () => ({ spawn: vi.fn() }))
vi.mock('node:fs/promises', () => ({ readFile: vi.fn() }))

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
  vi.spyOn(process, 'getuid').mockReturnValue(0)
  vi.spyOn(process, 'kill').mockReturnValue(true)
  vi.mocked(readFile).mockImplementation(async (path) => {
    if (String(path).endsWith('/cmdline'))
      return `php\0backend/Bootstrap/worker.php\0--worker-id=${worker.index}\0--monopolistic\0`
    if (String(path).endsWith('/environ')) return 'APP_ENV=test\0'
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
        ],
        { detached: true, stdio: 'ignore' },
      )
    })
  }

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
