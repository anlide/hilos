import type { TestInfo } from '@playwright/test'

const SUPPORTED_VERSION = '1.60.0'
const INCOMPATIBLE_INTERNALS =
  'hilos adaptive timeouts: incompatible Playwright 1.60.0 timeout internals'

/** The attempt's overall budget, independent of the active fixture's slot. @internal */
export interface TimeoutBudgetPort {
  readonly timeout: number
  /** Return the confirmed overall cap, or undefined when no write is allowed. */
  extend(timeoutMs: number): number | undefined
  onFinish(listener: () => void): void
  dispose(): void
}

interface TimeSlot {
  timeout: number
  elapsed: number
}

interface Runnable {
  type:
    | 'test'
    | 'beforeEach'
    | 'beforeAll'
    | 'afterEach'
    | 'afterAll'
    | 'teardown'
    | 'slow'
    | 'skip'
    | 'fail'
    | 'fixme'
  slot?: TimeSlot
  fixture?: { phase: 'setup' | 'teardown'; slot?: TimeSlot }
}

interface Running {
  slot: TimeSlot
  runnable: Runnable
}

interface TimeoutManager {
  defaultSlot(): TimeSlot
  _updateTimeout(running: Running): void
  _running?: Running
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

function isTimeSlot(value: unknown): value is TimeSlot {
  return (
    isRecord(value) &&
    typeof value.timeout === 'number' &&
    Number.isFinite(value.timeout) &&
    value.timeout >= 0 &&
    typeof value.elapsed === 'number' &&
    Number.isFinite(value.elapsed) &&
    value.elapsed >= 0
  )
}

function isRunnable(value: unknown): value is Runnable {
  if (!isRecord(value)) return false
  if (
    typeof value.type !== 'string' ||
    ![
      'test',
      'beforeEach',
      'beforeAll',
      'afterEach',
      'afterAll',
      'teardown',
      'slow',
      'skip',
      'fail',
      'fixme',
    ].includes(value.type)
  ) {
    return false
  }
  if (value.slot !== undefined && !isTimeSlot(value.slot)) return false
  if (value.fixture === undefined) return true
  return (
    isRecord(value.fixture) &&
    (value.fixture.phase === 'setup' || value.fixture.phase === 'teardown') &&
    (value.fixture.slot === undefined || isTimeSlot(value.fixture.slot))
  )
}

function isTimeoutManager(value: unknown): value is TimeoutManager {
  if (
    !isRecord(value) ||
    typeof value.defaultSlot !== 'function' ||
    typeof value._updateTimeout !== 'function'
  ) {
    return false
  }
  let slot: unknown
  try {
    slot = value.defaultSlot()
  } catch {
    return false
  }
  if (!isTimeSlot(slot)) return false
  if (value._running === undefined) return true
  return (
    isRecord(value._running) &&
    isTimeSlot(value._running.slot) &&
    isRunnable(value._running.runnable) &&
    value._running.slot ===
      (value._running.runnable.fixture?.slot ??
        value._running.runnable.slot ??
        slot)
  )
}

/**
 * Isolate the version-bound Playwright access. Validate before registering a
 * listener, sampling pressure or changing any slot; never load another runner.
 * @internal
 */
export function createPlaywrightTimeoutAdapter(
  testInfo: TestInfo,
): TimeoutBudgetPort {
  const version = testInfo.config?.version
  if (version !== SUPPORTED_VERSION) {
    throw new Error(
      `hilos adaptive timeouts: unsupported Playwright version "${version}"; supported: ${SUPPORTED_VERSION}`,
    )
  }
  const internals: unknown = testInfo
  if (
    !isRecord(internals) ||
    !isTimeoutManager(internals._timeoutManager) ||
    !(internals._onDidFinishTestFunctionCallbacks instanceof Set)
  ) {
    throw new Error(INCOMPATIBLE_INTERNALS)
  }
  const manager = internals._timeoutManager
  const callbacks: Set<() => void> = internals._onDidFinishTestFunctionCallbacks
  let closed = false
  let finishListener: (() => void) | undefined

  const dispose = () => {
    if (closed) return
    closed = true
    if (finishListener !== undefined) callbacks.delete(finishListener)
    finishListener = undefined
  }

  const currentSlot = () => {
    if (!isTimeoutManager(manager)) throw new Error(INCOMPATIBLE_INTERNALS)
    return manager.defaultSlot()
  }

  return {
    get timeout() {
      return currentSlot().timeout
    },
    extend(timeoutMs) {
      if (closed) return
      const slot = currentSlot()
      const running = manager._running
      if (
        !running ||
        (running.runnable.type !== 'test' &&
          running.runnable.type !== 'beforeEach') ||
        running.runnable.fixture?.phase === 'teardown' ||
        (running.runnable.slot !== undefined &&
          running.runnable.slot !== slot) ||
        slot.timeout === 0 ||
        !Number.isFinite(timeoutMs) ||
        timeoutMs <= slot.timeout
      ) {
        return
      }

      slot.timeout = timeoutMs
      if (slot.timeout !== timeoutMs) return
      // Keep the native accumulated elapsed and start. A separate timed fixture
      // resumes the new overall cap only when the runner returns to defaultSlot.
      if (running.slot === slot) manager._updateTimeout(running)
      return slot.timeout === timeoutMs ? timeoutMs : undefined
    },
    onFinish(listener) {
      if (closed) return
      if (finishListener !== undefined) callbacks.delete(finishListener)
      finishListener = () => {
        if (closed) return
        dispose()
        listener()
      }
      callbacks.add(finishListener)
    },
    dispose,
  }
}
