import type { Locator, Page } from '@playwright/test'
import { ROW_PREFIX, ROWS, TABLE } from '../../../../../framework/frontend/e2e/index.js'

// What a spec reads off a live viewport table (HilosViewportTable). Every handle
// is one the table's own registry names (docs/agents/frontend/table-subscription.md,
// "Stable selectors"). It lives with the demo, as chat's copy does: the rest of
// those helpers, which wait with `expect` — a value import the shared folder
// refuses (its index.ts) — join this file with the leaf that moves the table
// specs here (HIL-1223).

/**
 * The rows of the window as a locator.
 *
 * @param page The Playwright page showing the table.
 * @returns The locator matching every row of the window.
 */
function rows(page: Page): Locator {
  return page.getByTestId(TABLE).locator(ROWS)
}

/**
 * Read the row keys of the window, in the order the rows are drawn.
 *
 * @param page The Playwright page showing the table.
 * @returns The row keys, first row first.
 */
export async function tableRowKeys(page: Page): Promise<string[]> {
  const ids = await rows(page).evaluateAll((elements) =>
    elements.map((element) => element.getAttribute('data-id') ?? ''),
  )

  return ids.map((id) => id.slice(ROW_PREFIX.length))
}
