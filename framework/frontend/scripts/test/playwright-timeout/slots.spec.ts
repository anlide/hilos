import assert from 'node:assert/strict'
import { writeFileSync } from 'node:fs'
import { relative, resolve } from 'node:path'
import process from 'node:process'
import { test as base, type TestInfo } from '@playwright/test'

import { startAdaptiveTimeoutController } from '../../../e2e/adaptiveTimeouts.js'
import { createPlaywrightTimeoutAdapter } from '../../../e2e/playwrightTimeoutAdapter.js'

// spec-owner: stand — native browserless slots and cleanup, with no clock waits.
const TEST_FIXTURE_CAP = 7_000
const WORKER_FIXTURE_CAP = 9_000
const IDLE = { loadPerCpu: 0.2, availableGib: 8 }

interface Slot {
  timeout: number
  elapsed: number
}

interface Running {
  slot: Slot
  start: number
  deadline: number
  timer: unknown
  runnable: {
    type: string
    slot?: Slot
    fixture?: { phase: string; title: string; slot?: Slot }
  }
}

interface NativeInfo {
  _timeoutManager: { defaultSlot(): Slot; _running?: Running }
  _onDidFinishTestFunctionCallbacks: Set<() => void>
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

function isSlot(value: unknown): value is Slot {
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

/** Read only the installed 1.64.1 fields needed to observe native deadlines. */
function nativeInfo(info: TestInfo): NativeInfo {
  assert.equal(info.config.version, '1.64.1')
  const value: unknown = info
  assert.ok(isRecord(value) && isRecord(value._timeoutManager))
  assert.ok(typeof value._timeoutManager.defaultSlot === 'function')
  assert.ok(isSlot(value._timeoutManager.defaultSlot()))
  assert.ok(value._onDidFinishTestFunctionCallbacks instanceof Set)
  return value as unknown as NativeInfo
}

function runningOf(native: NativeInfo): Running {
  const value: unknown = native._timeoutManager._running
  assert.ok(isRecord(value) && isSlot(value.slot))
  assert.equal(typeof value.start, 'number')
  assert.equal(typeof value.deadline, 'number')
  assert.ok(Number.isFinite(value.start) && Number.isFinite(value.deadline))
  assert.ok(value.timer)
  assert.ok(isRecord(value.runnable))
  assert.equal(typeof value.runnable.type, 'string')
  if (value.runnable.slot !== undefined) assert.ok(isSlot(value.runnable.slot))
  if (value.runnable.fixture !== undefined) {
    assert.ok(isRecord(value.runnable.fixture))
    assert.equal(typeof value.runnable.fixture.title, 'string')
    assert.ok(
      value.runnable.fixture.phase === 'setup' ||
        value.runnable.fixture.phase === 'teardown',
    )
    if (value.runnable.fixture.slot !== undefined)
      assert.ok(isSlot(value.runnable.fixture.slot))
  }
  return value as unknown as Running
}

function snapshot(running: Running) {
  return {
    timeout: running.slot.timeout,
    elapsed: running.slot.elapsed,
    start: running.start,
    deadline: running.deadline,
    timer: running.timer,
  }
}

function startAttempt(info: TestInfo) {
  const native = nativeInfo(info)
  const manager = native._timeoutManager
  const overall = manager.defaultSlot()
  const baseline = overall.timeout
  const phases: string[] = []
  const logs: string[] = []
  let pressure = IDLE
  let reads = 0
  let cleared = 0
  let unrefs = 0
  let queued: (() => void) | undefined
  let cleanupSlot: Slot | undefined
  let finishedTimeoutMs = baseline
  const timer = { unref: () => unrefs++ } as unknown as ReturnType<
    typeof setInterval
  >

  const tick = (factor: number) => {
    pressure = { loadPerCpu: factor - 0.25, availableGib: 8 }
    assert.ok(queued)
    queued()
  }

  const cleanup = (phase: string, closed = true, separate?: Slot) => {
    const running = manager._running ? runningOf(native) : undefined
    const before = running ? snapshot(running) : undefined
    const savedOverall = { ...overall }
    const savedSeparate = separate ? { ...separate } : undefined
    const logCount = logs.length
    const readCount = reads
    tick(4)
    assert.deepEqual(overall, savedOverall)
    assert.equal(info.timeout, finishedTimeoutMs)
    if (running) assert.deepEqual(snapshot(running), before)
    if (separate) assert.deepEqual(separate, savedSeparate)
    assert.equal(logs.length, logCount)
    assert.equal(reads, readCount + (closed ? 0 : 1))
    assert.equal(cleared, closed ? 1 : 0)
    phases.push(phase)
  }

  // This callback runs on the native afterHooksSlot before the adapter's stop
  // listener. A queued tick must refuse the phase even while still open.
  const beforeStop = () => {
    const running = runningOf(native)
    assert.equal(running.runnable.type, 'test')
    assert.equal(running.runnable.slot, running.slot)
    assert.notEqual(running.slot, overall)
    assert.equal(running.slot.elapsed, 0)
    assert.equal(
      running.slot.timeout,
      Math.max(info.project.timeout, finishedTimeoutMs),
    )
    cleanupSlot = running.slot
    cleanup('after-hooks-before-stop', false)
    native._onDidFinishTestFunctionCallbacks.delete(beforeStop)
  }
  const originalListeners = native._onDidFinishTestFunctionCallbacks.size
  native._onDidFinishTestFunctionCallbacks.add(beforeStop)
  const stop = startAdaptiveTimeoutController(
    info,
    info.config.metadata.hilosTimeoutScale,
    createPlaywrightTimeoutAdapter(info),
    {
      readPressure: () => {
        reads++
        return pressure
      },
      clock: {
        setInterval(callback, intervalMs) {
          assert.equal(intervalMs, 2_000)
          assert.equal(queued, undefined)
          queued = callback
          return timer
        },
        clearInterval(value) {
          assert.equal(value, timer)
          cleared++
        },
      },
      log: (line) => logs.push(line),
    },
  )
  assert.equal(reads, 1)
  assert.equal(unrefs, 1)
  assert.equal(logs.length, 0)
  assert.equal(overall.timeout, baseline)
  phases.push('initial')

  return {
    info,
    native,
    phases,
    extend(phase: string, factor: number, separateCap?: number) {
      const running = runningOf(native)
      const before = snapshot(running)
      const elapsed = overall.elapsed
      const logCount = logs.length
      const previousTimeoutMs = overall.timeout
      assert.equal(
        running.runnable.type,
        phase === 'body' ? 'test' : 'beforeEach',
      )
      if (separateCap !== undefined) {
        assert.equal(running.runnable.fixture?.phase, 'setup')
        assert.equal(running.runnable.fixture.slot, running.slot)
        assert.notEqual(running.slot, overall)
        assert.equal(running.slot.timeout, separateCap)
      } else {
        assert.equal(running.slot, overall)
        assert.equal(
          running.deadline,
          running.start + (overall.timeout - overall.elapsed),
        )
      }
      tick(factor)
      finishedTimeoutMs = baseline * factor
      assert.equal(manager.defaultSlot(), overall)
      assert.equal(info.timeout, finishedTimeoutMs)
      assert.equal(overall.elapsed, elapsed)
      assert.equal(manager._running, running)
      if (separateCap !== undefined) {
        assert.deepEqual(snapshot(running), before)
      } else {
        assert.equal(running.start, before.start)
        assert.equal(
          running.deadline,
          running.start + (overall.timeout - overall.elapsed),
        )
        assert.notEqual(running.timer, before.timer)
      }
      assert.equal(logs.length, logCount + 1)
      assert.equal(
        logs.at(-1),
        'hilos-timeout-extended: ' +
          JSON.stringify({
            file: relative(process.cwd(), info.file),
            line: info.line,
            titlePath: info.titlePath,
            project: info.project.name,
            retry: info.retry,
            repeatEachIndex: info.repeatEachIndex,
            previousTimeoutMs,
            timeoutMs: finishedTimeoutMs,
            factor,
            ...pressure,
            reason: `load per cpu ${pressure.loadPerCpu}`,
          }),
      )
      const after = snapshot(running)
      tick(factor)
      assert.equal(logs.length, logCount + 1)
      assert.deepEqual(snapshot(running), after)
      assert.equal(overall.timeout, finishedTimeoutMs)
      phases.push(phase)
      return running.slot
    },
    cleanup,
    afterEach() {
      const running = runningOf(native)
      assert.equal(running.runnable.type, 'afterEach')
      assert.equal(running.slot, cleanupSlot)
      assert.equal(
        native._onDidFinishTestFunctionCallbacks.size,
        originalListeners,
      )
      cleanup('after-each')
    },
    stop() {
      stop()
      stop()
      assert.equal(cleared, 1)
    },
    save() {
      writeFileSync(
        resolve('slots-proof.json'),
        JSON.stringify({
          phases,
          logs,
          baseline,
          finishedTimeoutMs,
          cleanupTimeoutMs: cleanupSlot?.timeout,
          cleared,
          unrefs,
        }),
      )
    },
  }
}

type Attempt = ReturnType<typeof startAttempt>
let current: Attempt | undefined
const test = base.extend<
  { slotController: Attempt; timedFixture: void },
  { lazyWorker: void }
>({
  slotController: [
    // eslint-disable-next-line no-empty-pattern -- Native auto-fixture with no dependencies.
    async ({}, use, info) => {
      current = startAttempt(info)
      try {
        await use(current)
      } finally {
        assert.equal(
          runningOf(current.native).runnable.fixture?.phase,
          'teardown',
        )
        current.cleanup('controller-teardown')
        current.stop()
      }
    },
    { auto: true },
  ],
  timedFixture: [
    async ({ slotController }, use) => {
      const slot = slotController.extend('timed-setup', 2, TEST_FIXTURE_CAP)
      await use()
      assert.equal(slot.timeout, TEST_FIXTURE_CAP)
      assert.equal(runningOf(slotController.native).slot, slot)
      assert.equal(
        runningOf(slotController.native).runnable.fixture?.phase,
        'teardown',
      )
      slotController.cleanup('test-teardown', true, slot)
    },
    { timeout: TEST_FIXTURE_CAP },
  ],
  lazyWorker: [
    // eslint-disable-next-line no-empty-pattern -- Lazy worker fixture with no dependencies.
    async ({}, use) => {
      assert.ok(
        current,
        'The test auto-fixture must start before lazy worker setup',
      )
      // WorkerInfo has no timeout manager; this is the installed runner's live
      // TestInfo, not a second Playwright runtime.
      assert.equal(base.info(), current.info)
      const slot = current.extend('worker-setup', 2.5, WORKER_FIXTURE_CAP)
      await use()
      assert.equal(slot.timeout, WORKER_FIXTURE_CAP)
      // On a passing worker's final shutdown, Playwright uses a fresh internal
      // TestInfo. The fixture retains this same native slot for its teardown.
      if (current.native._timeoutManager._running) {
        const running = runningOf(current.native)
        assert.equal(running.runnable.type, 'teardown')
        assert.equal(running.runnable.fixture?.phase, 'teardown')
        assert.equal(running.slot, slot)
      }
      current.cleanup('worker-teardown', true, slot)
      current.save()
    },
    { scope: 'worker', timeout: WORKER_FIXTURE_CAP },
  ],
})

test.beforeEach(async ({ timedFixture, lazyWorker, slotController }) => {
  assert.equal(timedFixture, undefined)
  assert.equal(lazyWorker, undefined)
  slotController.extend('before-each', 3)
  if (slotController.info.title === 'slot-setup-failure')
    throw new Error('synthetic slot setup refusal')
})

test.afterEach(() => {
  assert.ok(current)
  current.afterEach()
})

test.afterAll(() => {
  assert.ok(current)
  const running = runningOf(current.native)
  assert.equal(running.runnable.type, 'afterAll')
  assert.equal(running.slot.timeout, current.info.project.timeout)
  current.cleanup('after-all')
})

for (const name of [
  'slot-success',
  'slot-setup-failure',
  'slot-body-failure',
]) {
  test(name, async ({ slotController }) => {
    slotController.extend('body', 3.5)
    if (name === 'slot-body-failure')
      throw new Error('synthetic slot body failure')
  })
}
