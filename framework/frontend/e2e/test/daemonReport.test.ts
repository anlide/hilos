import type { Expect } from '@playwright/test'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { reportDaemonNode } from '../../scripts/daemonMoments.mjs'
import {
  reportDaemonNodeUntil,
  reportDaemonNodeWhileFalse,
} from '../daemonReport.js'

vi.mock('../../scripts/daemonMoments.mjs', () => ({
  reportDaemonNode: vi.fn(),
}))

const REPORT = { nodeId: 'standalone', reportedAt: 1 }

/** Let every settled report reach its handlers. */
const flush = () => new Promise<void>((resolve) => setImmediate(resolve))

/** Attempts until the test's own deadline, past any expect's budget. */
const TEST_DEADLINE = 50

/**
 * Stand in for Playwright 1.60's expect, its time counted in attempts so this
 * unit test spends none: `poll` reads again while the matcher fails, within the
 * budget of the expect it was taken from, and then fails with the matcher's own
 * message; `configure({ timeout })` gives a new expect that budget. A reader
 * that throws comes out at once. `toPass()` without a timeout of its own reads
 * no expect's budget and repeats until the test's deadline, as the real one does.
 */
function budgetedExpect(budget: number) {
  const runs: number[] = []
  async function repeat(attempts: number, attempt: () => Promise<boolean>) {
    for (let run = 1; run <= attempts; run++) {
      runs.push(run)
      if (await attempt()) return true
    }
    return false
  }
  function withBudget(attempts: number): Expect {
    const check = (callback: () => Promise<void>) => ({
      async toPass(options: { timeout?: number } = {}) {
        let failure: unknown
        const passed = await repeat(
          options.timeout ?? TEST_DEADLINE,
          async () => {
            try {
              await callback()
              return true
            } catch (error) {
              failure = error
              return false
            }
          },
        )
        if (!passed) throw failure
      },
    })
    return Object.assign(check, {
      poll: (read: () => Promise<unknown>) => ({
        async toBe(wanted: unknown) {
          let received: unknown
          const passed = await repeat(attempts, async () => {
            received = await read()
            return received === wanted
          })
          if (!passed)
            throw new Error(
              `Expected ${String(wanted)}, received ${String(received)}`,
            )
        },
      }),
      configure: ({ timeout }: { timeout: number }) => withBudget(timeout),
    }) as unknown as Expect
  }
  return { check: withBudget(budget), runs }
}

beforeEach(() => {
  vi.mocked(reportDaemonNode).mockResolvedValue(REPORT)
})

afterEach(() => {
  vi.resetAllMocks()
})

describe('reportDaemonNodeUntil', () => {
  it('repeats the report and then the check until the check passes', async () => {
    const order: string[] = []
    vi.mocked(reportDaemonNode).mockImplementation(async () => {
      order.push('report')
      return REPORT
    })
    let delivered = 0
    const { check, runs } = budgetedExpect(5)

    await reportDaemonNodeUntil(
      check,
      () => {
        order.push('check')
        delivered++
        if (delivered < 3) throw new Error(`Only ${delivered} delivered`)
      },
      { host: 'node-b' },
    )

    expect(order).toEqual([
      'report',
      'check',
      'report',
      'check',
      'report',
      'check',
    ])
    expect(runs).toEqual([1, 2, 3])
    expect(reportDaemonNode).toHaveBeenCalledWith({ host: 'node-b' })
  })

  it('lets the last failed check out when the budget ends', async () => {
    let delivered = 0
    const { check } = budgetedExpect(3)

    await expect(
      reportDaemonNodeUntil(check, () => {
        delivered++
        throw new Error(`Only ${delivered} delivered`)
      }),
    ).rejects.toThrow('Only 3 delivered')
    expect(reportDaemonNode).toHaveBeenCalledTimes(3)
  })

  it('keeps to the budget a configured expect carries', async () => {
    let delivered = 0
    const { check, runs } = budgetedExpect(5)

    await expect(
      reportDaemonNodeUntil(check.configure({ timeout: 2 }), () => {
        delivered++
        throw new Error(`Only ${delivered} delivered`)
      }),
    ).rejects.toThrow('Only 2 delivered')
    expect(runs).toEqual([1, 2])
    expect(reportDaemonNode).toHaveBeenCalledTimes(2)
  })

  it('lets a refused report out at once, without the check or a repeat', async () => {
    const refusal = new Error(
      'test:daemon:node:report failed: The Daemon node agent is not running on this node.',
    )
    vi.mocked(reportDaemonNode).mockRejectedValue(refusal)
    const read = vi.fn()
    const { check, runs } = budgetedExpect(5)

    await expect(reportDaemonNodeUntil(check, read)).rejects.toBe(refusal)
    expect(read).not.toHaveBeenCalled()
    expect(runs).toEqual([1])
  })

  it('lets a refused report out rather than the check that failed before it', async () => {
    const refusal = new Error('No Daemon node report during protected mode.')
    vi.mocked(reportDaemonNode)
      .mockResolvedValueOnce(REPORT)
      .mockRejectedValueOnce(refusal)
    const { check, runs } = budgetedExpect(5)

    await expect(
      reportDaemonNodeUntil(check, () => {
        throw new Error('Nothing delivered')
      }),
    ).rejects.toBe(refusal)
    expect(runs).toEqual([1, 2])
  })
})

describe('reportDaemonNodeWhileFalse', () => {
  it('sends nothing when the state is already delivered', () => {
    const predicate = reportDaemonNodeWhileFalse(() => true)

    expect(predicate()).toBe(true)
    expect(reportDaemonNode).not.toHaveBeenCalled()
  })

  it('keeps one report in flight while the state is not delivered', async () => {
    let answer!: (report: typeof REPORT) => void
    vi.mocked(reportDaemonNode).mockImplementation(
      () =>
        new Promise((resolve) => {
          answer = resolve
        }),
    )
    let delivered = false
    const predicate = reportDaemonNodeWhileFalse(() => delivered, {
      host: 'node-b',
    })

    expect(predicate()).toBe(false)
    expect(predicate()).toBe(false)
    expect(reportDaemonNode).toHaveBeenCalledOnce()
    expect(reportDaemonNode).toHaveBeenCalledWith({ host: 'node-b' })
    answer(REPORT)
    await flush()
    expect(predicate()).toBe(false)
    expect(reportDaemonNode).toHaveBeenCalledTimes(2)
    delivered = true
    expect(predicate()).toBe(true)
    expect(reportDaemonNode).toHaveBeenCalledTimes(2)
  })

  it('throws a refused report from the next poll that finds nothing delivered', async () => {
    const refusal = new Error('No Daemon node report during protected mode.')
    vi.mocked(reportDaemonNode).mockRejectedValue(refusal)
    const predicate = reportDaemonNodeWhileFalse(() => false)

    expect(predicate()).toBe(false)
    await flush()
    expect(() => predicate()).toThrow(refusal)
    expect(reportDaemonNode).toHaveBeenCalledOnce()
  })

  it('answers a state delivered despite a refused report', async () => {
    vi.mocked(reportDaemonNode).mockRejectedValue(new Error('Refused.'))
    let delivered = false
    const predicate = reportDaemonNodeWhileFalse(() => delivered)

    expect(predicate()).toBe(false)
    await flush()
    delivered = true
    expect(predicate()).toBe(true)
  })
})
