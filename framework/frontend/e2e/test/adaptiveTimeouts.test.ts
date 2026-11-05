import { join } from 'node:path'
import process from 'node:process'
import type { Fixtures, TestInfo, TestType } from '@playwright/test'
import { afterEach, beforeEach, expect, expectTypeOf, it, vi } from 'vitest'

import {
  startAdaptiveTimeoutController,
  withAdaptiveTimeouts,
  type AdaptiveTimeoutOptions,
} from '../adaptiveTimeouts.js'
import { createPlaywrightTimeoutAdapter } from '../playwrightTimeoutAdapter.js'

const SAMPLE_MS = 2_000
const IDLE = { loadPerCpu: 0.2, availableGib: 8 }
const DOUBLE = { loadPerCpu: 1.75, availableGib: 8 }
const TRIPLE = { loadPerCpu: 2.75, availableGib: 8 }

beforeEach(() => {
  vi.useFakeTimers()
  vi.stubEnv('HILOS_E2E_TIMEOUT_SCALE', '')
})

afterEach(() => {
  vi.clearAllTimers()
  vi.useRealTimers()
  vi.unstubAllEnvs()
  vi.restoreAllMocks()
})

function playwrightInfo(timeout = 30_000, scale = 1, retry = 0) {
  const slot = { timeout, elapsed: 0 }
  const running = {
    slot,
    runnable: { type: 'test' } as {
      type: string
      slot?: typeof slot
      fixture?: { phase: string; slot?: typeof slot }
    },
  }
  const manager = {
    defaultSlot: () => slot,
    _updateTimeout: vi.fn(),
    _running: running as typeof running | undefined,
  }
  const info = {
    get timeout() {
      return slot.timeout
    },
    set timeout(timeoutMs: number) {
      slot.timeout = timeoutMs
    },
    config: { version: '1.64.1', metadata: { hilosTimeoutScale: scale } },
    _timeoutManager: manager,
    _onDidFinishTestFunctionCallbacks: new Set<() => void>(),
    file: join(process.cwd(), 'tests', 'adaptive.spec.ts'),
    line: 17,
    titlePath: ['suite', 'extends a budget'],
    project: { name: 'chromium' },
    retry,
    repeatEachIndex: 2,
  }
  const finish = () => {
    for (const callback of info._onDidFinishTestFunctionCallbacks) callback()
  }
  return { info, manager, slot, finish }
}

function controller(timeout = 30_000, scale = 1, retry = 0) {
  const native = playwrightInfo(timeout, scale, retry)
  const budget = createPlaywrightTimeoutAdapter(
    native.info as unknown as TestInfo,
  )
  vi.spyOn(budget, 'extend')
  vi.spyOn(budget, 'dispose')
  const readPressure = vi
    .fn<NonNullable<AdaptiveTimeoutOptions['readPressure']>>()
    .mockReturnValue(IDLE)
  const log = vi.fn<(line: string) => void>()
  const unref = vi.fn()
  const clock = {
    setInterval: vi.fn((callback: () => void, delay: number) => {
      const timer = setInterval(callback, delay)
      vi.spyOn(timer, 'unref').mockImplementation(() => {
        unref()
        return timer
      })
      return timer
    }),
    clearInterval: vi.fn((timer: ReturnType<typeof setInterval>) =>
      clearInterval(timer),
    ),
  }
  const stop = startAdaptiveTimeoutController(native.info, scale, budget, {
    readPressure,
    clock,
    log,
  })
  return { ...native, budget, readPressure, log, unref, clock, stop }
}

function captureFixture(options: AdaptiveTimeoutOptions = {}) {
  type AutoFixture = { adaptiveTimeout: void }
  const extend =
    vi.fn<(fixtures: Fixtures<AutoFixture>) => TestType<object, object>>()
  const base = { extend } as unknown as TestType<object, object>
  extend.mockReturnValue(base)
  withAdaptiveTimeouts(base, options)
  const entry = extend.mock.calls[0][0].adaptiveTimeout
  if (!Array.isArray(entry) || typeof entry[0] !== 'function') {
    throw new Error('Expected an adaptiveTimeout fixture')
  }
  expect(entry[1]).toEqual({ scope: 'test', auto: true })
  return entry[0]
}

it('samples an idle host immediately and every two seconds without changing or logging the budget', () => {
  const test = controller()
  expect(test.readPressure).toHaveBeenCalledTimes(1)
  vi.advanceTimersByTime(SAMPLE_MS - 1)
  expect(test.readPressure).toHaveBeenCalledTimes(1)
  vi.advanceTimersByTime(SAMPLE_MS * 2 + 1)
  expect(test.readPressure).toHaveBeenCalledTimes(4)
  expect(test.budget.extend).not.toHaveBeenCalled()
  expect(test.log).not.toHaveBeenCalled()
})

it('extends the full budget from the start and logs the attempt and deciding readings', () => {
  const test = controller()
  vi.advanceTimersByTime(SAMPLE_MS * 5)
  test.readPressure.mockReturnValue(DOUBLE)
  vi.advanceTimersByTime(SAMPLE_MS)
  expect(test.budget.extend).toHaveBeenCalledExactlyOnceWith(60_000)
  expect(test.log).toHaveBeenCalledExactlyOnceWith(
    'hilos-timeout-extended: ' +
      JSON.stringify({
        file: join('tests', 'adaptive.spec.ts'),
        line: 17,
        titlePath: ['suite', 'extends a budget'],
        project: 'chromium',
        retry: 0,
        repeatEachIndex: 2,
        previousTimeoutMs: 30_000,
        timeoutMs: 60_000,
        factor: 2,
        ...DOUBLE,
        reason: 'load per cpu 1.75',
      }),
  )
})

it('retains the maximum scale when host pressure falls', () => {
  const test = controller()
  test.readPressure.mockReturnValue(TRIPLE)
  vi.advanceTimersByTime(SAMPLE_MS)
  test.readPressure.mockReturnValue(DOUBLE)
  vi.advanceTimersByTime(SAMPLE_MS)
  expect(test.info.timeout).toBe(90_000)
  expect(test.budget.extend).toHaveBeenCalledTimes(1)
  expect(test.log).toHaveBeenCalledTimes(1)
})

it('does not compound repeated readings or repeat extension logs', () => {
  const test = controller()
  test.readPressure.mockReturnValue(DOUBLE)
  vi.advanceTimersByTime(SAMPLE_MS * 20)
  expect(test.info.timeout).toBe(60_000)
  expect(test.budget.extend).toHaveBeenCalledTimes(1)
  expect(test.log).toHaveBeenCalledTimes(1)
})

it('caps the host factor while retaining the test own budget', () => {
  const test = controller(90_000)
  test.readPressure.mockReturnValue({ loadPerCpu: 40, availableGib: 8 })
  vi.advanceTimersByTime(SAMPLE_MS * 2)
  expect(test.budget.extend).toHaveBeenCalledExactlyOnceWith(360_000)
})

it('uses the config scale that is already included in the starting timeout', () => {
  const test = controller(75_000, 2.5)
  test.readPressure.mockReturnValue(DOUBLE)
  vi.advanceTimersByTime(SAMPLE_MS)
  expect(test.budget.extend).not.toHaveBeenCalled()
  test.readPressure.mockReturnValue(TRIPLE)
  vi.advanceTimersByTime(SAMPLE_MS)
  expect(test.budget.extend).toHaveBeenCalledExactlyOnceWith(90_000)
})

it('preserves slow declared after the initial sample', () => {
  const test = controller()
  test.info.timeout *= 3
  test.readPressure.mockReturnValue(DOUBLE)
  vi.advanceTimersByTime(SAMPLE_MS)
  expect(test.budget.extend).toHaveBeenCalledExactlyOnceWith(180_000)
})

it('preserves an explicit multiplier after a previous extension', () => {
  const test = controller()
  test.readPressure.mockReturnValue(DOUBLE)
  vi.advanceTimersByTime(SAMPLE_MS)
  test.info.timeout *= 2
  vi.advanceTimersByTime(SAMPLE_MS)
  expect(test.info.timeout).toBe(120_000)
  test.readPressure.mockReturnValue(TRIPLE)
  vi.advanceTimersByTime(SAMPLE_MS)
  expect(test.info.timeout).toBe(180_000)
})

it('preserves an addition to the reached budget when the scale later grows', () => {
  const test = controller()
  test.readPressure.mockReturnValue(DOUBLE)
  vi.advanceTimersByTime(SAMPLE_MS)
  test.info.timeout += 15_000
  vi.advanceTimersByTime(SAMPLE_MS)
  expect(test.info.timeout).toBe(75_000)
  test.readPressure.mockReturnValue(TRIPLE)
  vi.advanceTimersByTime(SAMPLE_MS)
  expect(test.info.timeout).toBe(112_500)
})

it('accepts an external reduction and scales from that newly declared budget', () => {
  const test = controller()
  test.readPressure.mockReturnValue(DOUBLE)
  vi.advanceTimersByTime(SAMPLE_MS)
  test.info.timeout = 40_000
  vi.advanceTimersByTime(SAMPLE_MS)
  expect(test.info.timeout).toBe(40_000)
  test.readPressure.mockReturnValue(TRIPLE)
  vi.advanceTimersByTime(SAMPLE_MS)
  expect(test.info.timeout).toBe(60_000)
})

it('never enables a timeout that started disabled', () => {
  const test = controller(0)
  test.readPressure.mockReturnValue(TRIPLE)
  vi.advanceTimersByTime(SAMPLE_MS * 3)
  expect(test.budget.extend).not.toHaveBeenCalled()
  expect(test.log).not.toHaveBeenCalled()
})

it('never restores a limit disabled by the spec after an extension', () => {
  const test = controller()
  test.readPressure.mockReturnValue(DOUBLE)
  vi.advanceTimersByTime(SAMPLE_MS)
  test.info.timeout = 0
  test.readPressure.mockReturnValue(TRIPLE)
  vi.advanceTimersByTime(SAMPLE_MS * 3)
  expect(test.info.timeout).toBe(0)
  expect(test.budget.extend).toHaveBeenCalledTimes(1)
})

it('keeps the reached budget when subsequent metrics are unavailable', () => {
  const test = controller()
  test.readPressure.mockReturnValue(DOUBLE)
  vi.advanceTimersByTime(SAMPLE_MS)
  test.readPressure.mockReturnValue({ loadPerCpu: null, availableGib: null })
  vi.advanceTimersByTime(SAMPLE_MS * 3)
  expect(test.info.timeout).toBe(60_000)
  expect(test.log).toHaveBeenCalledTimes(1)
})

it('uses the existing override and memory floor rule and keeps missing metrics null', () => {
  vi.stubEnv('HILOS_E2E_TIMEOUT_SCALE', '2')
  const test = controller(60_000, 2)
  test.readPressure.mockReturnValue({ loadPerCpu: 40, availableGib: 8 })
  vi.advanceTimersByTime(SAMPLE_MS)
  expect(test.budget.extend).not.toHaveBeenCalled()
  test.readPressure.mockReturnValue({ loadPerCpu: null, availableGib: 0.5 })
  vi.advanceTimersByTime(SAMPLE_MS)
  expect(test.info.timeout).toBe(90_000)
  expect(test.log).toHaveBeenCalledExactlyOnceWith(
    expect.stringContaining(
      '"factor":3,"loadPerCpu":null,"availableGib":0.5,"reason":"HILOS_E2E_TIMEOUT_SCALE=2 raised to 3 by 0.5 GiB available"',
    ),
  )
})

it('rounds an increased fractional budget up without compounding it', () => {
  const test = controller(30_001)
  test.readPressure.mockReturnValue({ loadPerCpu: 1.08, availableGib: null })
  vi.advanceTimersByTime(SAMPLE_MS * 3)
  expect(test.budget.extend).toHaveBeenCalledExactlyOnceWith(39_902)
})

it('starts a retry with its own config budget and attempt identity', () => {
  const first = controller()
  first.readPressure.mockReturnValue(TRIPLE)
  vi.advanceTimersByTime(SAMPLE_MS)
  first.stop()
  const retry = controller(30_000, 1, 1)
  vi.advanceTimersByTime(SAMPLE_MS)
  expect(retry.info.timeout).toBe(30_000)
  retry.readPressure.mockReturnValue(DOUBLE)
  vi.advanceTimersByTime(SAMPLE_MS)
  expect(retry.info.timeout).toBe(60_000)
  expect(retry.log).toHaveBeenCalledWith(expect.stringContaining('"retry":1'))
  expect(first.budget.extend).toHaveBeenCalledTimes(1)
})

it('isolates the next test and ignores a callback queued by its predecessor', () => {
  const first = controller()
  const queuedSample = first.clock.setInterval.mock.calls[0][0]
  first.stop()
  const next = controller(15_000)
  first.readPressure.mockReturnValue(TRIPLE)
  queuedSample()
  next.readPressure.mockReturnValue(DOUBLE)
  vi.advanceTimersByTime(SAMPLE_MS)
  expect(first.readPressure).toHaveBeenCalledTimes(1)
  expect(first.log).not.toHaveBeenCalled()
  expect(next.info.timeout).toBe(30_000)
})

it('unrefs and clears the timer with idempotent cleanup', () => {
  const test = controller()
  expect(test.unref).toHaveBeenCalledTimes(1)
  test.stop()
  test.stop()
  vi.advanceTimersByTime(SAMPLE_MS * 3)
  expect(test.clock.clearInterval).toHaveBeenCalledTimes(1)
  expect(test.readPressure).toHaveBeenCalledTimes(1)
  expect(vi.getTimerCount()).toBe(0)
})

it('rejects missing or invalid initial config metadata without reading the host', () => {
  const { info, budget } = controller()
  const readPressure = vi.fn()
  for (const invalid of [undefined, null, '2', NaN, Infinity, 0.9, 4.1]) {
    expect(() =>
      startAdaptiveTimeoutController(info, invalid, budget, { readPressure }),
    ).toThrow('metadata.hilosTimeoutScale must be a finite number from 1 to 4')
  }
  expect(readPressure).not.toHaveBeenCalled()
})

it('extends the budget before use and keeps the initial peak until cleanup', async () => {
  const readPressure = vi.fn().mockReturnValue(TRIPLE)
  const fixture = captureFixture({ readPressure })
  const { info, manager } = playwrightInfo(60_000, 2)
  const stdout = vi.spyOn(process.stdout, 'write').mockReturnValue(true)
  await fixture(
    { adaptiveTimeout: undefined },
    async () => {
      expect(vi.getTimerCount()).toBe(1)
      expect(readPressure).toHaveBeenCalledTimes(1)
      expect(info.timeout).toBe(90_000)
      expect(manager._updateTimeout).toHaveBeenCalledExactlyOnceWith(
        manager._running,
      )
      expect(stdout).toHaveBeenCalledTimes(1)
      readPressure.mockReturnValue(DOUBLE)
      vi.advanceTimersByTime(SAMPLE_MS)
      expect(readPressure).toHaveBeenCalledTimes(2)
      expect(info.timeout).toBe(90_000)
      expect(manager._updateTimeout).toHaveBeenCalledTimes(1)
      expect(stdout).toHaveBeenCalledTimes(1)
    },
    info as unknown as TestInfo,
  )
  vi.advanceTimersByTime(SAMPLE_MS * 3)
  expect(readPressure).toHaveBeenCalledTimes(2)
  expect(vi.getTimerCount()).toBe(0)
})

it('clears the automatic fixture when use fails and preserves that failure', async () => {
  const readPressure = vi.fn().mockReturnValue(IDLE)
  const fixture = captureFixture({ readPressure })
  const { info } = playwrightInfo()
  const failure = new Error('Test failed')
  await expect(
    fixture(
      { adaptiveTimeout: undefined },
      async () => {
        vi.advanceTimersByTime(SAMPLE_MS)
        throw failure
      },
      info as unknown as TestInfo,
    ),
  ).rejects.toBe(failure)
  vi.advanceTimersByTime(SAMPLE_MS * 3)
  expect(readPressure).toHaveBeenCalledTimes(2)
  expect(vi.getTimerCount()).toBe(0)
})

it('retries a refused extension without remembering its factor or logging it', () => {
  const test = controller()
  vi.mocked(test.budget.extend).mockReturnValueOnce(undefined)
  test.readPressure.mockReturnValue(TRIPLE)
  vi.advanceTimersByTime(SAMPLE_MS)
  expect(test.info.timeout).toBe(30_000)
  expect(test.log).not.toHaveBeenCalled()
  test.readPressure.mockReturnValue(DOUBLE)
  vi.advanceTimersByTime(SAMPLE_MS)
  expect(test.info.timeout).toBe(60_000)
  expect(test.log).toHaveBeenCalledTimes(1)
  test.readPressure.mockReturnValue(TRIPLE)
  vi.advanceTimersByTime(SAMPLE_MS)
  expect(test.info.timeout).toBe(90_000)
  expect(test.log).toHaveBeenCalledTimes(2)
})

it('ignores an unconfirmed write and retries the same factor', () => {
  const test = controller()
  vi.mocked(test.budget.extend).mockReturnValueOnce(30_000)
  test.readPressure.mockReturnValue(DOUBLE)
  vi.advanceTimersByTime(SAMPLE_MS)
  expect(test.log).not.toHaveBeenCalled()
  vi.advanceTimersByTime(SAMPLE_MS)
  expect(test.info.timeout).toBe(60_000)
  expect(test.log).toHaveBeenCalledTimes(1)
})

it('confirms and logs the saved overall cap while a separate setup fixture runs', () => {
  const test = controller()
  const fixtureSlot = { timeout: 5_000, elapsed: 123 }
  test.manager._running = {
    slot: fixtureSlot,
    runnable: { type: 'test', fixture: { phase: 'setup', slot: fixtureSlot } },
  }
  test.readPressure.mockReturnValue(DOUBLE)
  vi.advanceTimersByTime(SAMPLE_MS)
  expect(test.info.timeout).toBe(60_000)
  expect(test.log).toHaveBeenCalledTimes(1)
  expect(test.manager._updateTimeout).not.toHaveBeenCalled()
  expect(fixtureSlot).toEqual({ timeout: 5_000, elapsed: 123 })
  test.manager._running = { slot: test.slot, runnable: { type: 'test' } }
  test.readPressure.mockReturnValue(TRIPLE)
  vi.advanceTimersByTime(SAMPLE_MS)
  expect(test.info.timeout).toBe(90_000)
  expect(test.manager._updateTimeout).toHaveBeenCalledTimes(1)
})

it.each(['setup', 'body'])(
  'stops at the end of %s before fixture teardown and ignores a queued tick',
  (phase) => {
    const test = controller()
    if (phase === 'setup') {
      test.manager._running = {
        slot: test.slot,
        runnable: { type: 'beforeEach', fixture: { phase: 'setup' } },
      }
    }
    const queuedTick = test.clock.setInterval.mock.calls[0][0]
    test.finish()
    test.readPressure.mockReturnValue(TRIPLE)
    queuedTick()
    vi.advanceTimersByTime(SAMPLE_MS * 2)
    test.stop()
    expect(test.readPressure).toHaveBeenCalledTimes(1)
    expect(test.budget.extend).not.toHaveBeenCalled()
    expect(test.log).not.toHaveBeenCalled()
    expect(test.info._onDidFinishTestFunctionCallbacks.size).toBe(0)
    expect(test.clock.clearInterval).toHaveBeenCalledTimes(1)
    expect(test.budget.dispose).toHaveBeenCalledTimes(1)
  },
)

it('keeps the native initial cleanup cap and refuses growth before the finish listener', () => {
  const test = controller()
  test.readPressure.mockReturnValue(DOUBLE)
  vi.advanceTimersByTime(SAMPLE_MS)
  const cleanupSlot = { timeout: test.info.timeout, elapsed: 0 }
  test.manager._running = {
    slot: cleanupSlot,
    runnable: { type: 'test', slot: cleanupSlot },
  }
  test.readPressure.mockReturnValue(TRIPLE)
  vi.advanceTimersByTime(SAMPLE_MS)
  expect(test.info.timeout).toBe(60_000)
  expect(cleanupSlot).toEqual({ timeout: 60_000, elapsed: 0 })
  expect(test.log).toHaveBeenCalledTimes(1)
  expect(test.manager._updateTimeout).toHaveBeenCalledTimes(1)
  test.finish()
  vi.advanceTimersByTime(SAMPLE_MS)
  expect(test.info.timeout).toBe(60_000)
  expect(test.log).toHaveBeenCalledTimes(1)
})

it('cleans the finish listener if the immediate pressure read fails', async () => {
  const failure = new Error('Pressure failed')
  const readPressure = vi.fn(() => {
    throw failure
  })
  const fixture = captureFixture({ readPressure })
  const { info } = playwrightInfo()
  const use = vi.fn()
  await expect(
    fixture({ adaptiveTimeout: undefined }, use, info as unknown as TestInfo),
  ).rejects.toBe(failure)
  expect(use).not.toHaveBeenCalled()
  expect(info._onDidFinishTestFunctionCallbacks.size).toBe(0)
  expect(vi.getTimerCount()).toBe(0)
})

it('rejects unsupported versions before pressure, listener registration or use', async () => {
  const readPressure = vi.fn().mockReturnValue(TRIPLE)
  const fixture = captureFixture({ readPressure })
  const { info, manager, slot } = playwrightInfo()
  info.config.version = '1.64.2'
  const add = vi.spyOn(info._onDidFinishTestFunctionCallbacks, 'add')
  const interval = vi.spyOn(globalThis, 'setInterval')
  const use = vi.fn()
  await expect(
    fixture({ adaptiveTimeout: undefined }, use, info as unknown as TestInfo),
  ).rejects.toThrow(
    'hilos adaptive timeouts: unsupported Playwright version "1.64.2"; supported: 1.64.1',
  )
  expect(readPressure).not.toHaveBeenCalled()
  expect(add).not.toHaveBeenCalled()
  expect(interval).not.toHaveBeenCalled()
  expect(manager._updateTimeout).not.toHaveBeenCalled()
  expect(slot).toEqual({ timeout: 30_000, elapsed: 0 })
  expect(use).not.toHaveBeenCalled()
})

it('rejects incompatible internals before pressure, listener registration or use', async () => {
  const readPressure = vi.fn().mockReturnValue(TRIPLE)
  const fixture = captureFixture({ readPressure })
  const { info, manager, slot } = playwrightInfo()
  manager._running = {
    slot,
    runnable: { type: 'unknown' },
  }
  const add = vi.spyOn(info._onDidFinishTestFunctionCallbacks, 'add')
  const interval = vi.spyOn(globalThis, 'setInterval')
  const use = vi.fn()
  await expect(
    fixture({ adaptiveTimeout: undefined }, use, info as unknown as TestInfo),
  ).rejects.toThrow(
    'hilos adaptive timeouts: incompatible Playwright 1.64.1 timeout internals',
  )
  expect(readPressure).not.toHaveBeenCalled()
  expect(add).not.toHaveBeenCalled()
  expect(interval).not.toHaveBeenCalled()
  expect(manager._updateTimeout).not.toHaveBeenCalled()
  expect(slot).toEqual({ timeout: 30_000, elapsed: 0 })
  expect(use).not.toHaveBeenCalled()
})

it('retains generic test and worker fixtures and supports a subsequent extend', () => {
  type TestArgs = { account: string }
  type WorkerArgs = { stand: number }
  type AdaptiveTest = ReturnType<
    typeof withAdaptiveTimeouts<TestArgs, WorkerArgs>
  >
  expectTypeOf<AdaptiveTest>().toEqualTypeOf<
    TestType<TestArgs & { adaptiveTimeout: void }, WorkerArgs>
  >()
  const extend = (test: AdaptiveTest) =>
    test.extend<{ another: boolean }>({ another: true })
  expectTypeOf(extend).returns.toEqualTypeOf<
    TestType<
      TestArgs & { adaptiveTimeout: void } & { another: boolean },
      WorkerArgs
    >
  >()
})
