import type { Page } from '@playwright/test'

/** Register roots before dependents; each operation is awaited in LIFO order. */
export interface TestCleanup {
  defer(cleanup: () => unknown | Promise<unknown>): void
}

/**
 * Test-scoped teardown while the runner's page and browser are still alive.
 * Playwright keeps a failed or timed-out test's error when it releases use;
 * teardown errors are reported separately. A rejecting use is preserved too.
 */
export async function cleanupFixtures(
  // eslint-disable-next-line @typescript-eslint/no-unused-vars -- Playwright reads this dependency to keep the page alive until cleanup ends.
  { page: _page }: { page: Page },
  use: (cleanup: TestCleanup) => Promise<void>,
): Promise<void> {
  const pending: (() => unknown | Promise<unknown>)[] = []
  const errors: unknown[] = []
  let useFailed = false
  try {
    await use({
      defer(cleanup) {
        pending.push(cleanup)
      },
    })
  } catch (error) {
    useFailed = true
    errors.push(error)
  } finally {
    for (const cleanup of pending.reverse()) {
      try {
        await cleanup()
      } catch (error) {
        errors.push(error)
      }
    }
  }
  if (useFailed && errors.length === 1) throw errors[0]
  if (errors.length > 0)
    throw new AggregateError(
      errors,
      `Test fixture cleanup failed:\n${errors
        .map((error) =>
          error instanceof Error
            ? (error.stack ?? error.message)
            : String(error),
        )
        .join('\n\n')}`,
      { cause: errors[0] },
    )
}
