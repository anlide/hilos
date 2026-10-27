import { spawn } from 'node:child_process'
import { readFile } from 'node:fs/promises'
import type { Expect, Page } from '@playwright/test'
import {
  readMonopolisticWorker,
  type MonopolisticWorker,
} from './daemonWorker.js'
import { gotoPage, PAGE_READY } from './page.js'

/** The only isolated worker this stand's restart scenario may pause. */
export const RESTART_AGENT = 'hilos_settings_library'
const WATCHDOG_MS = 10_000

// The roster needs up to 0.5s (DaemonManager::PROCESS_ROSTER_COALESCE_SECONDS),
// then 5s (DaemonNodeAgent::CHANGE_INTERVAL_SECONDS) and 0.5s
// (DaemonCollectorAgent::FANOUT_WINDOW_SECONDS), plus ticks and delivery.
// Seeing the mark is still bounded by the WATCHDOG_MS pause: a missed mark
// cannot turn green by waiting past the worker's resumption (HIL-1542).
export const RESTART_MARK_TIMEOUT_MS = 10_000

export type RestartWorker = MonopolisticWorker

/**
 * Read only the settings worker; this restart API never selects another agent.
 *
 * @param expect The spec's expect.
 * @param page The page to navigate to the Workers table.
 */
export async function readRestartWorker(
  expect: Expect,
  page: Page,
): Promise<RestartWorker> {
  return readMonopolisticWorker(
    page,
    RESTART_AGENT,
    (workersPage) =>
      gotoPage(
        expect,
        workersPage,
        '/hilos/daemon/standalone/workers',
        PAGE_READY,
      ),
    async (predicate) => {
      await expect.poll(predicate).toBe(true)
    },
  )
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
    // A later pause may target the same process lifetime. Retire and join this
    // backstop after CONT succeeds, so it cannot resume that later pause early.
    // If CONT throws, leave the independent ten-second recovery armed.
    if (watchdog.exitCode === null && watchdog.signalCode === null) {
      const exited = new Promise<void>((resolve) =>
        watchdog.once('exit', () => resolve()),
      )
      watchdog.kill('SIGTERM')
      await exited
    }
  }
}
