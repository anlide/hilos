import type { Expect } from '@playwright/test'
import { reportDaemonNode } from '../scripts/daemonMoments.mjs'

export { reportDaemonNode }

/**
 * Bring this node's Daemon report to now until a check of what it delivered passes.
 *
 * For a cause that reaches the master by itself — a browser opened or closed, a
 * restart clicked on a page, a worker stopped or created — one report may leave
 * before the master knows of it, and the collector's fan-out follows the report.
 * So the command and the check repeat through `poll` of the given `expect`, in
 * that expect's budget — the project's `expect` timeout, or the one
 * `expect.configure({ timeout })` gave it — and the last failed check is what
 * comes out. A refused command is not repeated: it comes out at once. Where the
 * master already knows the cause — a page subscribed, a restart answered, a rule
 * fired — one {@link reportDaemonNode} and the ordinary wait for its frame are
 * enough.
 *
 * @param expect The calling spec's Playwright expect, or one it configured; its
 *   timeout bounds the repeat.
 * @param check Reads what has already been delivered — frames, a journal — and
 *   throws while it is not the expected state; it waits by itself no longer than
 *   the collector's fan-out window.
 * @param options Where to send the command.
 * @param options.host Explicit cluster host instead of COMMAND_HOST.
 */
export async function reportDaemonNodeUntil(
  expect: Expect,
  check: () => Promise<void> | void,
  { host }: { host?: string } = {},
): Promise<void> {
  // Not `toPass()`: it reads neither the project's `expect` timeout nor a
  // configured one, and would repeat until the test's own deadline.
  const refusals: unknown[] = []
  const failures: unknown[] = []
  try {
    await expect
      .poll(async () => {
        try {
          await reportDaemonNode({ host })
        } catch (error) {
          refusals.push(error)
          return true
        }
        try {
          await check()
          return true
        } catch (error) {
          failures.push(error)
          return false
        }
      })
      .toBe(true)
  } catch (error) {
    throw failures.length > 0 ? failures.at(-1) : error
  }
  if (refusals.length > 0) throw refusals[0]
}

/**
 * Wrap a predicate a consumer's own waiter polls so that, while it is false, one
 * node report is always in flight.
 *
 * This is {@link reportDaemonNodeUntil} for a helper that is handed a waiter in
 * place of the spec's `expect`. The predicate is asked first, so a state already
 * delivered sends nothing; a refused report is thrown by the next poll that still
 * finds the predicate false.
 *
 * @param predicate Reads what has already been delivered; it does not wait by itself.
 * @param options Where to send the command.
 * @param options.host Explicit cluster host instead of COMMAND_HOST.
 * @returns The predicate to hand to the waiter.
 */
export function reportDaemonNodeWhileFalse(
  predicate: () => boolean,
  { host }: { host?: string } = {},
): () => boolean {
  let reporting = false
  const refusals: unknown[] = []
  return () => {
    if (predicate()) return true
    if (refusals.length > 0) throw refusals[0]
    if (!reporting) {
      reporting = true
      void reportDaemonNode({ host }).then(
        () => {
          reporting = false
        },
        (error: unknown) => {
          refusals.push(error)
          reporting = false
        },
      )
    }
    return false
  }
}
