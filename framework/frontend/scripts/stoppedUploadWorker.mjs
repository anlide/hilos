import { spawn } from 'node:child_process'
import { readFile } from 'node:fs/promises'
import process from 'node:process'

/** Only the actual isolated uploads worker may be held by this proof. */
export const UPLOADS_WORKER_AGENT = 'hilos_uploads'
const WATCHDOG_MS = 10_000

/**
 * @typedef {object} UploadWorker
 * @property {number} index Worker index from the live Workers window.
 * @property {number} pid PID from that same window.
 * @property {string} kind Must be monopolistic.
 * @property {readonly string[]} agentIds Must name only hilos_uploads.
 */

/** Read state and Linux birth ticks, excluding spaces inside the process name. */
async function identity(pid) {
  const fields = (await readFile(`/proc/${pid}/stat`, 'utf8'))
    .split(') ')[1]
    ?.split(' ')
  if (!fields?.[19]) throw new Error('The upload worker has no birth identity')
  return { state: fields[0], start: fields[19] }
}

/**
 * Stop an exact test uploads incarnation and always resume it. The independent
 * watchdog survives runner loss; its CONT cannot make a successful observation.
 *
 * @param {UploadWorker} worker Real roster entry, never a caller-supplied PID.
 * @param {(event: Record<string, unknown>) => void} record Evidence sink.
 * @param {(held: {assertStopped: () => Promise<void>}) => Promise<void>} run Paused observations.
 */
export async function withStoppedUploadWorker(worker, record, run) {
  if (
    process.platform !== 'linux' ||
    process.getuid?.() !== 0 ||
    worker.kind !== 'monopolistic' ||
    worker.agentIds.length !== 1 ||
    worker.agentIds[0] !== UPLOADS_WORKER_AGENT ||
    !Number.isInteger(worker.index) ||
    worker.index <= 0 ||
    !Number.isInteger(worker.pid) ||
    worker.pid <= 1
  )
    throw new Error('Only the isolated test uploads worker may be paused')
  const argv = (await readFile(`/proc/${worker.pid}/cmdline`, 'utf8')).split(
    '\0',
  )
  const env = (await readFile(`/proc/${worker.pid}/environ`, 'utf8')).split(
    '\0',
  )
  if (
    !env.includes('APP_ENV=test') ||
    !argv.some(
      (arg) =>
        arg.endsWith('/backend/Bootstrap/worker.php') ||
        arg === 'backend/Bootstrap/worker.php',
    ) ||
    !argv.includes(`--worker-id=${worker.index}`) ||
    !argv.includes('--monopolistic')
  )
    throw new Error('The upload worker roster and /proc identity disagree')
  const birth = await identity(worker.pid)
  record({
    ...worker,
    kind: 'worker-identity',
    workerKind: worker.kind,
    ...birth,
    argv,
    appEnv: 'test',
  })
  // The child is independent of Playwright and signals only this incarnation.
  // Keeping it armed until normal CONT succeeds also protects a failing finally.
  const watchdog = spawn(
    process.execPath,
    [
      '-e',
      `
    const fs = require('node:fs');
    const [pid, start, wait] = process.argv.slice(1);
    setTimeout(() => {
      try {
        const fields = fs.readFileSync('/proc/' + pid + '/stat', 'utf8').split(') ')[1].split(' ');
        if (fields[19] === start) process.kill(Number(pid), 'SIGCONT');
      } catch { }
    }, Number(wait));
  `,
      String(worker.pid),
      birth.start,
      String(WATCHDOG_MS),
    ],
    {
      detached: true,
      stdio: 'ignore',
    },
  )
  await new Promise((resolve, reject) => {
    watchdog.once('spawn', resolve)
    watchdog.once('error', reject)
  })
  watchdog.unref()
  const stoppedAt = Date.now()
  const assertStopped = async () => {
    const current = await identity(worker.pid)
    record({
      kind: 'worker-held',
      ...current,
      elapsedMs: Date.now() - stoppedAt,
    })
    if (
      current.start !== birth.start ||
      current.state !== 'T' ||
      Date.now() - stoppedAt >= WATCHDOG_MS
    )
      throw new Error(
        'The uploads observation escaped its exact stopped incarnation or watchdog window',
      )
  }
  const resume = async () => {
    const current = await identity(worker.pid)
    if (current.start !== birth.start)
      throw new Error('The original upload worker vanished before CONT')
    process.kill(worker.pid, 'SIGCONT')
    record({
      kind: 'worker-cont',
      pid: worker.pid,
      start: birth.start,
      elapsedMs: Date.now() - stoppedAt,
      watchdogResumePossible: Date.now() - stoppedAt >= WATCHDOG_MS,
    })
    // No backstop process remains after the explicit resume succeeds.
    if (watchdog.exitCode === null && watchdog.signalCode === null) {
      const exited = new Promise((resolve) => watchdog.once('exit', resolve))
      watchdog.kill('SIGTERM')
      await exited
    }
  }
  try {
    if ((await identity(worker.pid)).start !== birth.start)
      throw new Error('The upload worker changed before STOP')
    process.kill(worker.pid, 'SIGSTOP')
    record({
      kind: 'worker-stop',
      pid: worker.pid,
      start: birth.start,
      watchdogMs: WATCHDOG_MS,
    })
    await assertStopped()
    await run({ assertStopped })
    await assertStopped()
  } finally {
    await resume()
  }
}
