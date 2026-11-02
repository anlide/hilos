import { relative } from 'node:path'
import process from 'node:process'
import type { Fixtures, TestInfo, TestType } from '@playwright/test'

import {
  deriveTimeoutScale,
  readHostPressure,
} from '../scripts/timeout-scale.mjs'
import {
  createPlaywrightTimeoutAdapter,
  type TimeoutBudgetPort,
} from './playwrightTimeoutAdapter.js'

const SAMPLE_INTERVAL_MS = 2_000

export interface AdaptiveTimeoutOptions {
  /** Programmatic pressure readings for a synthetic runner. */
  readPressure?: typeof readHostPressure
}

interface AdaptiveTimeoutFixtures {
  adaptiveTimeout: void
}

type TimeoutInfo = Pick<
  TestInfo,
  'file' | 'line' | 'titlePath' | 'retry' | 'repeatEachIndex'
> & { project: Pick<TestInfo['project'], 'name'> }

interface ControllerOptions extends AdaptiveTimeoutOptions {
  clock?: {
    setInterval(
      callback: () => void,
      intervalMs: number,
    ): ReturnType<typeof setInterval>
    clearInterval(timer: ReturnType<typeof setInterval>): void
  }
  log?: (line: string) => void
}

/**
 * Start one attempt's sampler and return its teardown. The scheduler and log
 * seams let unit tests exercise the real controller without a host or a wait.
 * @internal
 */
export function startAdaptiveTimeoutController(
  testInfo: TimeoutInfo,
  initialScale: unknown,
  budget: TimeoutBudgetPort,
  {
    readPressure = readHostPressure,
    clock = globalThis,
    log = (line) => process.stdout.write(`${line}\n`),
  }: ControllerOptions = {},
): () => void {
  if (
    typeof initialScale !== 'number' ||
    !Number.isFinite(initialScale) ||
    initialScale < 1 ||
    initialScale > 4
  ) {
    throw new Error(
      'metadata.hilosTimeoutScale must be a finite number from 1 to 4',
    )
  }

  let baselineTimeoutMs = budget.timeout / initialScale
  let appliedScale = initialScale
  let lastObservedTimeoutMs = budget.timeout
  let timer: ReturnType<typeof setInterval> | undefined
  let closed = false

  const stop = () => {
    if (closed) return
    closed = true
    if (timer !== undefined) clock.clearInterval(timer)
    timer = undefined
    budget.dispose()
  }

  const sample = () => {
    // Queued callbacks belong to this attempt even after its listener stopped it.
    if (closed) return
    const currentTimeoutMs = budget.timeout
    if (currentTimeoutMs !== lastObservedTimeoutMs) {
      baselineTimeoutMs = currentTimeoutMs / appliedScale
      lastObservedTimeoutMs = currentTimeoutMs
    }
    if (currentTimeoutMs === 0) return

    const pressure = readPressure()
    const { factor, reason } = deriveTimeoutScale({
      override: process.env.HILOS_E2E_TIMEOUT_SCALE,
      ...pressure,
    })
    const reachedScale = Math.max(appliedScale, factor)
    if (reachedScale === appliedScale) return

    const timeoutMs = Math.ceil(baselineTimeoutMs * reachedScale)
    if (timeoutMs <= currentTimeoutMs) return

    if (budget.extend(timeoutMs) !== timeoutMs) return
    appliedScale = reachedScale
    lastObservedTimeoutMs = timeoutMs
    log(
      `hilos-timeout-extended: ${JSON.stringify({
        file: relative(process.cwd(), testInfo.file),
        line: testInfo.line,
        titlePath: testInfo.titlePath,
        project: testInfo.project.name,
        retry: testInfo.retry,
        repeatEachIndex: testInfo.repeatEachIndex,
        previousTimeoutMs: currentTimeoutMs,
        timeoutMs,
        factor: reachedScale,
        loadPerCpu: pressure.loadPerCpu,
        availableGib: pressure.availableGib,
        reason,
      })}`,
    )
  }

  budget.onFinish(stop)
  try {
    sample()
    if (!closed) {
      timer = clock.setInterval(sample, SAMPLE_INTERVAL_MS)
      timer.unref()
    }
  } catch (error) {
    stop()
    throw error
  }
  return stop
}

/**
 * Add a browserless automatic fixture while retaining the caller's fixtures.
 */
export function withAdaptiveTimeouts<
  TestArgs extends object,
  WorkerArgs extends object,
>(
  baseTest: TestType<TestArgs, WorkerArgs>,
  options: AdaptiveTimeoutOptions = {},
): TestType<TestArgs & AdaptiveTimeoutFixtures, WorkerArgs> {
  const fixtures: Fixtures<AdaptiveTimeoutFixtures> = {
    adaptiveTimeout: [
      // eslint-disable-next-line no-empty-pattern -- Playwright parses this pattern to discover the absence of fixture dependencies.
      async ({}, use, testInfo) => {
        const budget = createPlaywrightTimeoutAdapter(testInfo)
        const stop = startAdaptiveTimeoutController(
          testInfo,
          testInfo.config.metadata.hilosTimeoutScale,
          budget,
          options,
        )
        try {
          await use()
        } finally {
          stop()
        }
      },
      { scope: 'test', auto: true },
    ],
  }
  return baseTest.extend<AdaptiveTimeoutFixtures>(fixtures)
}
