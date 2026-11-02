import { expect, test as base } from '@playwright/test'
import { setTimeout as delay } from 'node:timers/promises'

import { withAdaptiveTimeouts } from '../../../e2e/adaptiveTimeouts.js'

// spec-owner: stand — synthetic CLI fixtures without an application or browser.
const IDLE = { loadPerCpu: 0.2, availableGib: 8 }
const BUSY = { loadPerCpu: 3.75, availableGib: 8 }
let pressure = IDLE
let startedAt = 0
const sampledAtMs: number[] = []

const readPressure = () => {
  if (startedAt !== 0) sampledAtMs.push(performance.now() - startedAt)
  return pressure
}

for (const [name, runner] of [
  ['adaptive', withAdaptiveTimeouts(base, { readPressure })],
  ['control', base],
] as const) {
  // A caller's own fixture and cap must survive the production auto-fixture.
  const test = runner.extend<{ localCap: number }>({ localCap: 3_000 })
  test(name, async ({ localCap }, testInfo) => {
    pressure = IDLE
    startedAt = 0
    sampledAtMs.length = 0
    testInfo.setTimeout(localCap)
    const initialTimeoutMs = testInfo.timeout
    expect(readPressure()).toEqual(IDLE)
    startedAt = performance.now()
    pressure = BUSY
    // The real two-second sampler must move Playwright's live three-second
    // deadline. This wait is the timer integration's approved subject.
    await delay(4_500)
    await testInfo.attach('timeout-proof', {
      contentType: 'application/json',
      body: JSON.stringify({
        initialTimeoutMs,
        elapsedMs: performance.now() - startedAt,
        sampledAtMs,
      }),
    })
  })
}
