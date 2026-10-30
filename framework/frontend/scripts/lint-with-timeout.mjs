// Linux frontend CLI entry; the npm lint script remains the raw ESLint command.
import { spawn } from 'node:child_process'
import { constants } from 'node:os'
import { setTimeout as delay } from 'node:timers/promises'
import { resolve } from 'node:path'
import { performance } from 'node:perf_hooks'
import process from 'node:process'
import { pathToFileURL } from 'node:url'

// Lint normally takes about 54s; the full post-unit tail takes 85–106s.
// Allow load headroom. Revisit after ESLint config changes, ESLint/plugin/parser
// version changes, or substantial growth in the number of checked files.
const ESLINT_TIMEOUT_MS = 600_000
const TERMINATION_GRACE_MS = 1_000
const TIMEOUT_EXIT_CODE = 124
const SIGNAL_CODES = { SIGHUP: 129, SIGINT: 130, SIGTERM: 143 }

/** Signal the private npm/ESLint process group, including surviving descendants. */
function signalGroup(pid, signal) {
  if (pid === undefined) return false
  try {
    process.kill(-pid, signal)
    return true
  } catch (error) {
    if (error.code === 'ESRCH') return false
    throw error
  }
}

/**
 * Run only npm lint. Options are a module-local regression seam, never CLI/env
 * configuration or an SDK export; production always uses the fixed deadline.
 */
export async function runLint(
  argv = [],
  {
    cwd = process.cwd(),
    timeoutMs = ESLINT_TIMEOUT_MS,
    graceMs = TERMINATION_GRACE_MS,
  } = {},
) {
  const child = spawn('npm', ['run', 'lint', ...argv], {
    cwd,
    detached: true,
    stdio: 'inherit',
  })
  let finish
  const completed = new Promise((resolve) => {
    finish = resolve
  })
  const onError = (error) => finish({ code: 1, error })
  const onClose = (code, signal) =>
    finish({ code: code ?? (signal ? 128 + constants.signals[signal] : 1) })
  child.once('error', onError)
  child.once('close', onClose)
  const reaped = new Promise((resolve) => child.once('close', resolve))
  const handlers = Object.fromEntries(
    Object.entries(SIGNAL_CODES).map(([signal, code]) => [
      signal,
      () => finish({ code }),
    ]),
  )
  for (const [signal, handler] of Object.entries(handlers))
    process.on(signal, handler)
  const deadline = globalThis.setTimeout(
    () => finish({ code: TIMEOUT_EXIT_CODE, timedOut: true }),
    timeoutMs,
  )
  try {
    const result = await completed
    globalThis.clearTimeout(deadline)
    if (result.timedOut)
      process.stderr.write(
        `ESLint timeout after ${timeoutMs / 1_000}s; lint infrastructure failure (no test verdict).\n`,
      )
    if (result.error)
      process.stderr.write(`ESLint launch failed: ${result.error.message}\n`)
    // Do this even after an ordinary npm exit: it may leave a descendant alive.
    // Keep the escalation independent of npm's exit/close event.
    if (signalGroup(child.pid, 'SIGTERM')) {
      const until = performance.now() + graceMs
      while (signalGroup(child.pid, 0) && performance.now() < until)
        await delay(Math.min(20, Math.max(1, until - performance.now())))
      signalGroup(child.pid, 'SIGKILL')
    }
    await reaped
    return result.code
  } finally {
    globalThis.clearTimeout(deadline)
    for (const [signal, handler] of Object.entries(handlers))
      process.off(signal, handler)
    child.off('error', onError)
    child.off('close', onClose)
  }
}

if (
  process.argv[1] &&
  import.meta.url === pathToFileURL(resolve(process.argv[1])).href
) {
  try {
    process.exitCode = await runLint(process.argv.slice(2))
  } catch (error) {
    process.stderr.write(`ESLint wrapper failed: ${error.message}\n`)
    process.exitCode = 1
  }
}
