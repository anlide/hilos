// Stretch every Playwright timeout when the host is starved, so a loaded box makes
// the run SLOWER instead of RED. Ported from demo/cluster/docker/cluster_e2e.py
// (HIL-367), which has carried the same heuristic since the cluster suite started
// sharing a box with everything else; HIL-527 needs it on the demo suites because
// the full run now puts two of them on the machine at once.
//
// Deliberate divergence from cluster_e2e: its other half — retry ONLY on a
// convergence timeout, never on a violated invariant — is NOT ported. Playwright
// cannot cheaply tell a timeout from a failed assertion when it decides to retry,
// so retries stay at 2 in CI and only the caps move here.
//
// The rule is pure and takes its inputs as arguments; only `readHostPressure` and
// `scaledTimeouts` touch the host, so the decision is unit-testable without one.
//
// Everything is biased towards NOT stretching: an unreadable load average or an
// unreadable /proc/meminfo leaves the factor at 1.0, i.e. today's behavior. A
// factor that is wrongly 1.0 costs a false red on a starved box; a factor that is
// wrongly 4.0 hides a hang for four times as long.

import { readFileSync } from 'node:fs'
import { availableParallelism, loadavg } from 'node:os'
import process from 'node:process'

/** Below this load per CPU the host is not considered contended at all. */
const LOAD_HEADROOM = 0.75

/** A runaway host must still fail in bounded time, so the factor is capped. */
const MAX_SCALE = 4.0

/** Environment override, mirroring cluster_e2e's CLUSTER_E2E_TIMEOUT_SCALE. */
const OVERRIDE_VAR = 'HILOS_E2E_TIMEOUT_SCALE'

/**
 * Unscaled caps, in milliseconds. `action` and `navigation` deliberately equal
 * `test`: Playwright leaves both at "no limit" by default, and a run whose whole
 * test is capped at `test` can never spend longer than that inside one action
 * anyway — so declaring them here makes all four caps move together (what this
 * module is for) without introducing a limit that could fail a test the serial
 * run passed.
 */
const BASE_TIMEOUTS = {
  test: 30_000,
  expect: 5_000,
  action: 30_000,
  navigation: 30_000,
}

/**
 * What the host is currently under. Either field is null when this platform does
 * not report it — /proc/meminfo is Linux-only, and a load average needs a kernel
 * that keeps one.
 *
 * @returns {{ loadPerCpu: number | null, availableGib: number | null }}
 */
export function readHostPressure() {
  return { loadPerCpu: readLoadPerCpu(), availableGib: readAvailableGib() }
}

/**
 * The 1-minute load average normalized per CPU, or null when unavailable.
 *
 * @returns {number | null}
 */
function readLoadPerCpu() {
  const [load] = loadavg()
  // Linux is the only platform where loadavg() is not a constant zero, and zero
  // is also what a genuinely idle box reports — either way there is nothing to
  // stretch for, so both collapse to "no pressure from load".
  if (!(load > 0)) return null
  return load / (availableParallelism() || 1)
}

/**
 * MemAvailable in GiB, or null when /proc/meminfo cannot be read or parsed.
 *
 * @returns {number | null}
 */
function readAvailableGib() {
  let meminfo
  try {
    meminfo = readFileSync('/proc/meminfo', 'ascii')
  } catch {
    return null
  }
  const match = /^MemAvailable:\s+(\d+) kB$/m.exec(meminfo)
  return match === null ? null : Number(match[1]) / (1024 * 1024)
}

/**
 * The factor every timeout is multiplied by, and the sentence explaining it.
 *
 * @param {object} pressure
 * @param {string | undefined} pressure.override Raw value of the override
 *   variable; a non-numeric one is named in `reason` and ignored rather than
 *   fatal, so a typo in CI slows nothing down and breaks nothing — but still says
 *   so in the log, instead of leaving someone to wonder why the knob did nothing.
 * @param {number | null} pressure.loadPerCpu 1-minute load average per CPU.
 * @param {number | null} pressure.availableGib Available memory in GiB.
 * @returns {{ factor: number, reason: string }} `reason` names what decided the
 *   factor, so a slow run stays explainable from its log afterwards.
 */
export function deriveTimeoutScale({ override, loadPerCpu, availableGib }) {
  let ignored = ''
  if (override !== undefined && override.trim() !== '') {
    const parsed = Number(override)
    if (Number.isFinite(parsed)) {
      return {
        factor: round(Math.min(Math.max(parsed, 1.0), MAX_SCALE)),
        reason: `${OVERRIDE_VAR}=${override}`,
      }
    }
    ignored = `ignoring non-numeric ${OVERRIDE_VAR}=${override}; `
  }

  let factor = 1.0
  let reason = 'host is not contended'
  if (loadPerCpu !== null && loadPerCpu > LOAD_HEADROOM) {
    factor = 1.0 + (loadPerCpu - LOAD_HEADROOM)
    reason = `load per cpu ${round(loadPerCpu)}`
  }
  // Memory is a floor rather than a term: a box this short on memory is about to
  // swap, and swapping costs far more than its load average admits at the moment
  // the config is read.
  const memoryFloor = availableGib === null ? 1.0 : memoryFloorFor(availableGib)
  if (memoryFloor > factor) {
    factor = memoryFloor
    reason = `${round(availableGib)} GiB available`
  }

  const capped = Math.min(factor, MAX_SCALE)
  if (capped < factor) reason = `${reason}, capped at ${MAX_SCALE}`
  return { factor: round(capped), reason: ignored + reason }
}

/**
 * The smallest factor a given amount of free memory forces on its own.
 *
 * @param {number} availableGib Available memory in GiB.
 * @returns {number}
 */
function memoryFloorFor(availableGib) {
  if (availableGib < 1.0) return 3.0
  if (availableGib < 2.0) return 2.0
  return 1.0
}

/**
 * Two decimals, the precision cluster_e2e reports its own factor with.
 *
 * @param {number} value
 * @returns {number}
 */
function round(value) {
  return Math.round(value * 100) / 100
}

/**
 * Every Playwright cap, scaled for this host, after printing the factor and the
 * numbers behind it.
 *
 * Printing is the point of doing this in the config rather than per test: the
 * factor lands at the top of the step's log file, where whoever reads a 20-minute
 * e2e step tomorrow can see it was 3.2 because the box had 1.4 GiB left.
 *
 * @returns {{ scale: number, test: number, expect: number, action: number,
 *   navigation: number }} Milliseconds, except `scale`.
 */
export function scaledTimeouts() {
  const pressure = readHostPressure()
  const { factor, reason } = deriveTimeoutScale({
    override: process.env[OVERRIDE_VAR],
    ...pressure,
  })
  process.stdout.write(
    `playwright timeout scale ${factor} (${reason}; ` +
      `load/cpu ${describe(pressure.loadPerCpu)}, ` +
      `${describe(pressure.availableGib)} GiB available)\n`,
  )
  return {
    scale: factor,
    test: BASE_TIMEOUTS.test * factor,
    expect: BASE_TIMEOUTS.expect * factor,
    action: BASE_TIMEOUTS.action * factor,
    navigation: BASE_TIMEOUTS.navigation * factor,
  }
}

/**
 * A measurement for the log line, or `unknown` when the host did not report it.
 *
 * @param {number | null} value
 * @returns {string}
 */
function describe(value) {
  return value === null ? 'unknown' : String(round(value))
}
