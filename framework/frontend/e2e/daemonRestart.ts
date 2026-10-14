import { spawn } from 'node:child_process'
import { readFile } from 'node:fs/promises'
import type { Page, WebSocket } from '@playwright/test'

/** The only isolated worker this stand's restart scenario may pause. */
export const RESTART_AGENT = 'hilos_settings_library'
const WATCHDOG_MS = 10_000

export interface RestartWorker {
  readonly index: number
  readonly pid: number
  readonly kind: string
  readonly agentIds: readonly string[]
}

function record(value: unknown): Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {}
}

/**
 * Read the selected worker only from the real Workers page's table window.
 *
 * @param page An administrator's page, before navigation to Workers.
 * @param gotoWorkers Navigate through the consumer's existing page helper.
 * @param waitFor Wait for the predicate using the consumer's Playwright runtime.
 */
export async function readRestartWorker(
  page: Page,
  gotoWorkers: (page: Page) => Promise<void>,
  waitFor: (predicate: () => boolean) => Promise<void>,
): Promise<RestartWorker> {
  let worker: RestartWorker | undefined
  const watch = (socket: WebSocket) => {
    socket.on('framereceived', (frame) => {
      if (typeof frame.payload !== 'string') return
      let envelope: Record<string, unknown>
      try {
        envelope = record(JSON.parse(frame.payload))
      } catch {
        return
      }
      const data = record(envelope.data)
      if (data.page !== 'hilos_daemon_workers') return
      const window =
        envelope.type === 'page_response'
          ? record(record(record(data.payload).windows).hilosDaemonWorkers)
          : data.tableKey === 'hilosDaemonWorkers' &&
              envelope.type === 'table_window'
            ? data
            : {}
      if (!Array.isArray(window.rows)) return
      for (const row of window.rows) {
        const fields = record(record(record(row).slots).worker)
        if (
          fields.kind !== 'monopolistic' ||
          !Array.isArray(fields.agentIds) ||
          fields.agentIds.length !== 1 ||
          fields.agentIds[0] !== RESTART_AGENT
        )
          continue
        if (
          !Number.isInteger(fields.index) ||
          typeof fields.index !== 'number' ||
          fields.index <= 0 ||
          !Number.isInteger(fields.pid) ||
          typeof fields.pid !== 'number' ||
          fields.pid <= 1
        )
          throw new Error(
            'The Workers window carries an invalid restart worker identity',
          )
        worker = {
          index: fields.index,
          pid: fields.pid,
          kind: fields.kind,
          agentIds: [RESTART_AGENT],
        }
      }
    })
  }
  page.on('websocket', watch)
  try {
    await gotoWorkers(page)
    await waitFor(() => worker !== undefined)
    if (worker === undefined)
      throw new Error('The settings library worker never arrived')
    return worker
  } finally {
    page.off('websocket', watch)
  }
}

/**
 * Hold that test worker, always resume it, and arm an independent ten-second backstop.
 * No PID arrives from user input; the worker roster, /proc identity and test env must agree.
 *
 * @param worker The worker read from the Workers table window.
 * @param run Assertions made while that isolated worker cannot report its stop.
 */
export async function withStoppedRestartWorker(
  worker: RestartWorker,
  run: () => Promise<void>,
): Promise<void> {
  if (
    process.platform !== 'linux' ||
    process.getuid?.() !== 0 ||
    worker.kind !== 'monopolistic' ||
    worker.agentIds.length !== 1 ||
    worker.agentIds[0] !== RESTART_AGENT ||
    !Number.isInteger(worker.pid) ||
    worker.pid <= 1
  ) {
    throw new Error(
      'Only the isolated test settings-library worker may be paused',
    )
  }
  const argv = (await readFile(`/proc/${worker.pid}/cmdline`, 'utf8')).split(
    '\0',
  )
  const environment = (
    await readFile(`/proc/${worker.pid}/environ`, 'utf8')
  ).split('\0')
  if (
    !environment.includes('APP_ENV=test') ||
    !argv.some(
      (arg) =>
        arg.endsWith('/backend/Bootstrap/worker.php') ||
        arg === 'backend/Bootstrap/worker.php',
    ) ||
    !argv.includes(`--worker-id=${worker.index}`) ||
    !argv.includes('--monopolistic')
  ) {
    throw new Error(
      "The Workers PID does not identify this stand's monopolistic worker",
    )
  }
  const start = (await readFile(`/proc/${worker.pid}/stat`, 'utf8'))
    .split(') ')[1]
    ?.split(' ')[19]
  if (start === undefined)
    throw new Error('The worker start identity is missing')
  // The backstop survives a Playwright timeout or runner loss; it only signals the same
  // process lifetime, so PID reuse cannot send SIGCONT to an unrelated process.
  const watchdog = spawn(
    process.execPath,
    [
      '-e',
      `
    const fs = require('node:fs');
    const [pid, start, wait] = process.argv.slice(1);
    setTimeout(() => {
      try {
        const now = fs.readFileSync('/proc/' + pid + '/stat', 'utf8').split(') ')[1].split(' ')[19];
        if (now === start) process.kill(Number(pid), 'SIGCONT');
      } catch { }
    }, Number(wait));
  `,
      String(worker.pid),
      start,
      String(WATCHDOG_MS),
    ],
    { detached: true, stdio: 'ignore' },
  )
  await new Promise<void>((resolve, reject) => {
    watchdog.once('spawn', resolve)
    watchdog.once('error', reject)
  })
  watchdog.unref()
  const stoppedAt = Date.now()
  process.kill(worker.pid, 'SIGSTOP')
  try {
    await run()
    if (Date.now() - stoppedAt >= WATCHDOG_MS) {
      throw new Error('The restart observation exceeded its safe pause window')
    }
  } finally {
    process.kill(worker.pid, 'SIGCONT')
  }
}
