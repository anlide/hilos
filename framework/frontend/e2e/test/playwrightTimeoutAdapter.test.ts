import type { TestInfo } from '@playwright/test'
import { expect, it, vi } from 'vitest'

import { createPlaywrightTimeoutAdapter } from '../playwrightTimeoutAdapter.js'

const INCOMPATIBLE =
  'hilos adaptive timeouts: incompatible Playwright 1.64.1 timeout internals'

interface Slot {
  timeout: number
  elapsed: number
}

interface Runnable {
  type: string
  slot?: Slot
  fixture?: { phase: string; slot?: Slot }
}

function nativeInfo() {
  const slot = { timeout: 30_000, elapsed: 4_000 }
  const running = {
    slot,
    runnable: { type: 'test' } as Runnable,
    start: 1_000,
    deadline: 27_000,
  }
  const manager = {
    defaultSlot: vi.fn(() => slot),
    _running: running as typeof running | undefined,
    _updateTimeout: vi.fn((active: typeof running) => {
      active.deadline = active.start + active.slot.timeout - active.slot.elapsed
    }),
  }
  const callbacks = new Set<() => void>()
  const info = {
    config: { version: '1.64.1' },
    _timeoutManager: manager,
    _onDidFinishTestFunctionCallbacks: callbacks,
    setTimeout: vi.fn(),
  }
  const create = () =>
    createPlaywrightTimeoutAdapter(info as unknown as TestInfo)
  return { info, slot, running, manager, callbacks, create }
}

it.each([
  undefined,
  null,
  '',
  '1.59.1',
  '1.60',
  '1.60.0',
  '1.60.1',
  '1.61.0',
  '1.64.0',
  '1.64.2',
  '2.0.0',
])(
  'rejects version %s before inspecting internals or registering anything',
  (version) => {
    const test = nativeInfo()
    const readManager = vi.fn(() => test.manager)
    Object.defineProperty(test.info, '_timeoutManager', { get: readManager })
    Object.defineProperty(test.info.config, 'version', { value: version })
    const add = vi.spyOn(test.callbacks, 'add')
    expect(test.create).toThrow(
      `hilos adaptive timeouts: unsupported Playwright version "${version}"; supported: 1.64.1`,
    )
    expect(readManager).not.toHaveBeenCalled()
    expect(add).not.toHaveBeenCalled()
    expect(test.slot).toEqual({ timeout: 30_000, elapsed: 4_000 })
    expect(test.manager._updateTimeout).not.toHaveBeenCalled()
    expect(test.info.setTimeout).not.toHaveBeenCalled()
  },
)

it.each([
  {},
  { defaultSlot: 1, _updateTimeout() {} },
  { defaultSlot: () => ({ timeout: 30_000, elapsed: 0 }) },
  { defaultSlot: () => null, _updateTimeout() {} },
  {
    defaultSlot: () => ({ timeout: '30000', elapsed: 0 }),
    _updateTimeout() {},
  },
  { defaultSlot: () => ({ timeout: NaN, elapsed: 0 }), _updateTimeout() {} },
  {
    defaultSlot: () => ({ timeout: Infinity, elapsed: 0 }),
    _updateTimeout() {},
  },
  { defaultSlot: () => ({ timeout: -1, elapsed: 0 }), _updateTimeout() {} },
  { defaultSlot: () => ({ timeout: 30_000 }), _updateTimeout() {} },
  {
    defaultSlot: () => ({ timeout: 30_000, elapsed: '0' }),
    _updateTimeout() {},
  },
  {
    defaultSlot: () => ({ timeout: 30_000, elapsed: NaN }),
    _updateTimeout() {},
  },
  {
    defaultSlot: () => ({ timeout: 30_000, elapsed: -1 }),
    _updateTimeout() {},
  },
  {
    defaultSlot() {
      throw new Error('Changed slot API')
    },
    _updateTimeout() {},
  },
])(
  'rejects an incompatible timeout manager %# without side effects',
  (manager) => {
    const test = nativeInfo()
    Object.defineProperty(test.info, '_timeoutManager', { value: manager })
    const add = vi.spyOn(test.callbacks, 'add')
    expect(test.create).toThrow(INCOMPATIBLE)
    expect(add).not.toHaveBeenCalled()
    expect(test.slot).toEqual({ timeout: 30_000, elapsed: 4_000 })
    expect(test.info.setTimeout).not.toHaveBeenCalled()
  },
)

it.each([
  undefined,
  null,
  {},
  { type: 'unknown' },
  { type: 1 },
  { type: { toString: () => 'test' } },
  { type: 'test', slot: {} },
  { type: 'test', fixture: { phase: 'unknown' } },
  { type: 'test', fixture: { phase: 'setup', slot: {} } },
])(
  'rejects an incompatible active runnable %# without registering a listener',
  (runnable) => {
    const test = nativeInfo()
    Object.defineProperty(test.running, 'runnable', { value: runnable })
    const add = vi.spyOn(test.callbacks, 'add')
    expect(test.create).toThrow(INCOMPATIBLE)
    expect(add).not.toHaveBeenCalled()
    expect(test.manager._updateTimeout).not.toHaveBeenCalled()
    expect(test.slot).toEqual({ timeout: 30_000, elapsed: 4_000 })
  },
)

it.each([null, {}, { runnable: { type: 'test' } }])(
  'rejects a malformed running slot %#',
  (running) => {
    const test = nativeInfo()
    Object.defineProperty(test.manager, '_running', { value: running })
    expect(test.create).toThrow(INCOMPATIBLE)
    expect(test.slot.timeout).toBe(30_000)
    expect(test.manager._updateTimeout).not.toHaveBeenCalled()
  },
)

it('requires a callback Set and leaves existing callbacks untouched', () => {
  const test = nativeInfo()
  Object.defineProperty(test.info, '_onDidFinishTestFunctionCallbacks', {
    value: [],
  })
  expect(test.create).toThrow(INCOMPATIBLE)
  expect(test.slot.timeout).toBe(30_000)
  expect(test.manager._updateTimeout).not.toHaveBeenCalled()
})

it('reschedules the active overall deadline without resetting elapsed or start', () => {
  const test = nativeInfo()
  const adapter = test.create()
  expect(test.callbacks.size).toBe(0)
  expect(adapter.timeout).toBe(30_000)
  expect(adapter.extend(60_000)).toBe(60_000)
  expect(adapter.timeout).toBe(60_000)
  expect(test.manager._updateTimeout).toHaveBeenCalledExactlyOnceWith(
    test.running,
  )
  expect(test.manager._running).toBe(test.running)
  expect(test.running).toEqual({
    slot: { timeout: 60_000, elapsed: 4_000 },
    runnable: { type: 'test' },
    start: 1_000,
    deadline: 57_000,
  })
  expect(test.info.setTimeout).not.toHaveBeenCalled()
})

it.each(['test', 'beforeEach'])(
  'extends setup on the overall slot during %s',
  (type) => {
    const test = nativeInfo()
    test.running.runnable = { type, fixture: { phase: 'setup' } }
    expect(test.create().extend(60_000)).toBe(60_000)
    expect(test.manager._updateTimeout).toHaveBeenCalledExactlyOnceWith(
      test.running,
    )
  },
)

it('saves the overall cap without touching a separate timed or lazy worker setup slot', () => {
  const test = nativeInfo()
  const fixtureSlot = { timeout: 5_000, elapsed: 250 }
  test.running.slot = fixtureSlot
  test.running.runnable.fixture = { phase: 'setup', slot: fixtureSlot }
  test.running.deadline = 5_750
  const adapter = test.create()
  expect(adapter.timeout).toBe(30_000)
  expect(adapter.extend(60_000)).toBe(60_000)
  expect(adapter.timeout).toBe(60_000)
  expect(test.manager._updateTimeout).not.toHaveBeenCalled()
  expect(test.running).toEqual({
    slot: { timeout: 5_000, elapsed: 250 },
    runnable: { type: 'test', fixture: { phase: 'setup', slot: fixtureSlot } },
    start: 1_000,
    deadline: 5_750,
  })
  expect(test.slot).toEqual({ timeout: 60_000, elapsed: 4_000 })
  test.running.slot = test.slot
  test.running.runnable = { type: 'test' }
  expect(adapter.extend(90_000)).toBe(90_000)
  expect(test.running.deadline).toBe(87_000)
  expect(test.info.setTimeout).not.toHaveBeenCalled()
})

it.each(['beforeAll', 'afterEach', 'afterAll', 'teardown'])(
  'refuses writes during %s before finish callbacks run',
  (type) => {
    const test = nativeInfo()
    test.running.runnable = { type }
    const adapter = test.create()
    expect(adapter.extend(60_000)).toBeUndefined()
    expect(test.slot).toEqual({ timeout: 30_000, elapsed: 4_000 })
    expect(test.manager._updateTimeout).not.toHaveBeenCalled()
  },
)

it('refuses fixture teardown even when it still uses the default slot', () => {
  const test = nativeInfo()
  test.running.runnable.fixture = { phase: 'teardown' }
  expect(test.create().extend(60_000)).toBeUndefined()
  expect(test.slot.timeout).toBe(30_000)
  expect(test.manager._updateTimeout).not.toHaveBeenCalled()
})

it('refuses the explicit cleanup slot before the finish listener, including timed fixture setup', () => {
  const test = nativeInfo()
  const cleanupSlot = { timeout: 30_000, elapsed: 0 }
  const fixtureSlot = { timeout: 5_000, elapsed: 250 }
  test.running.slot = fixtureSlot
  test.running.runnable = {
    type: 'test',
    slot: cleanupSlot,
    fixture: { phase: 'setup', slot: fixtureSlot },
  }
  const adapter = test.create()
  expect(adapter.extend(60_000)).toBeUndefined()
  expect(test.slot.timeout).toBe(30_000)
  expect(cleanupSlot).toEqual({ timeout: 30_000, elapsed: 0 })
  expect(fixtureSlot).toEqual({ timeout: 5_000, elapsed: 250 })
  expect(test.manager._updateTimeout).not.toHaveBeenCalled()
})

it('reads an inactive default slot but never writes without an active runnable', () => {
  const test = nativeInfo()
  test.manager._running = undefined
  const adapter = test.create()
  expect(adapter.timeout).toBe(30_000)
  expect(adapter.extend(60_000)).toBeUndefined()
  expect(test.slot.timeout).toBe(30_000)
  expect(test.manager._updateTimeout).not.toHaveBeenCalled()
})

it('does not confirm an ignored assignment', () => {
  const test = nativeInfo()
  Object.defineProperty(test.slot, 'timeout', {
    get: () => 30_000,
    set: () => {},
  })
  expect(test.create().extend(60_000)).toBeUndefined()
  expect(test.slot.timeout).toBe(30_000)
  expect(test.manager._updateTimeout).not.toHaveBeenCalled()
})

it('propagates a native rescheduling failure without confirming the write', () => {
  const test = nativeInfo()
  const failure = new Error('Native rescheduling failed')
  test.manager._updateTimeout.mockImplementation(() => {
    throw failure
  })
  expect(() => test.create().extend(60_000)).toThrow(failure)
})

it('checks the current runnable again before changing a slot', () => {
  const test = nativeInfo()
  const adapter = test.create()
  test.running.runnable.fixture = { phase: 'unknown' }
  expect(() => adapter.extend(60_000)).toThrow(INCOMPATIBLE)
  expect(test.slot.timeout).toBe(30_000)
  expect(test.manager._updateTimeout).not.toHaveBeenCalled()
})

it('disposes its finish listener once and ignores queued endings and writes', () => {
  const test = nativeInfo()
  const existing = vi.fn()
  test.callbacks.add(existing)
  const adapter = test.create()
  const finish = vi.fn()
  adapter.onFinish(finish)
  expect(test.callbacks.size).toBe(2)
  const queuedFinish = [...test.callbacks][1]
  queuedFinish()
  queuedFinish()
  adapter.dispose()
  expect(finish).toHaveBeenCalledTimes(1)
  expect(test.callbacks).toEqual(new Set([existing]))
  expect(adapter.extend(60_000)).toBeUndefined()
  expect(test.slot.timeout).toBe(30_000)
  expect(test.manager._updateTimeout).not.toHaveBeenCalled()
  expect(existing).not.toHaveBeenCalled()
})

it('removes its listener on early dispose without calling it', () => {
  const test = nativeInfo()
  const adapter = test.create()
  const finish = vi.fn()
  adapter.onFinish(finish)
  const queuedFinish = [...test.callbacks][0]
  adapter.dispose()
  adapter.dispose()
  queuedFinish()
  adapter.onFinish(finish)
  expect(test.callbacks.size).toBe(0)
  expect(finish).not.toHaveBeenCalled()
  expect(adapter.extend(60_000)).toBeUndefined()
  expect(test.slot.timeout).toBe(30_000)
})
