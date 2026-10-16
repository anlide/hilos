import type { Expect, Locator, Page } from '@playwright/test'

// A declared table stands in the document twice: as a table for a wide screen and
// as a list of cards for a narrow one, both mounted at once and one of them hidden
// by Bootstrap's display utilities (docs/agents/frontend/table-subscription.md,
// "The card a row projects to"). So every `data-id` a page writes into a cell is in
// the document twice, and a strict lookup of one — a click, a read of an attribute,
// a web-first assertion — refuses to choose between the two copies.
//
// The copy a person can press is the one on screen, whatever the width, so that is
// the copy a spec aims at. A view that draws no cards holds one copy, which is also
// the one on screen, so the same lookup reads the same in every demo.
//
// What this is NOT for: saying a control is gone. The copy on screen can be absent
// while the hidden one still stands, so `toHaveCount(0)` is asserted on the plain
// `getByTestId`, where it means both copies.

/** The root the table draws under; a page with one table keeps the default. */
export const TABLE = 'hilos-viewport-table'

/** What every row of the window carries in `data-id`, followed by its row key. */
export const ROW_PREFIX = 'hilos-table-row-'

/**
 * The rows of the window in the order they are drawn. The detail an expanded row
 * opens shares the prefix without being a row, and the card branch names its rows
 * differently, so reading the table's own body leaves exactly the rows.
 */
export const ROWS = `tbody [data-id^="${ROW_PREFIX}"]:not([data-id^="${ROW_PREFIX}detail-"])`

/**
 * The copies of an element that are on screen, for an element a declared table
 * draws in both its branches.
 *
 * @param scope The page, or a locator the element stands inside.
 * @param testId The element's `data-id`, or a pattern of them.
 * @returns A locator matching only the copies currently shown.
 */
export function shownByTestId(
  scope: Page | Locator,
  testId: string | RegExp,
): Locator {
  return scope.getByTestId(testId).filter({ visible: true })
}

/**
 * How far the page reaches sideways, in pixels: once for the shell's own scrolling
 * container, where an admin page lives (HilosLayout), and once for the document
 * around it. A declared table turns into cards on a narrow screen precisely so a
 * phone never gets the wide table in a sideways scroll, so `[0, 0]` is what a narrow
 * window over such a table must read.
 *
 * @param page The page to measure.
 * @returns The overflow of the shell's container and of the document, in that order.
 */
export function sidewaysOverflow(page: Page): Promise<number[]> {
  return page.evaluate(() => {
    const main = document.getElementById('hilos-main-content')
    if (main === null) {
      throw new Error('the shell drew no main container to measure')
    }

    return [main, document.documentElement].map(
      (element) => element.scrollWidth - element.clientWidth,
    )
  })
}

/**
 * Settle a declared table after a row the page itself removed on the server: the
 * ordinary removal gate of the table may leave this window owing an Apply, or may
 * take the row out at once, so the step waits for either and presses Apply where
 * it is offered. At the end the removed row's control is gone from both branches
 * of the table — the plain lookup that `toHaveCount(0)` reads here.
 *
 * @param expect The spec's expect.
 * @param page The page carrying the table.
 * @param controlTestId The `data-id` of a control only the removed row carried.
 */
export async function settleRemovedRow(
  expect: Expect,
  page: Page,
  controlTestId: string,
): Promise<void> {
  const apply = page.getByTestId('hilos-table-apply')
  const control = page.getByTestId(controlTestId)
  await expect
    .poll(
      async () => (await apply.count()) > 0 || (await control.count()) === 0,
    )
    .toBe(true)
  if (await apply.isVisible()) {
    await expect(apply).toBeVisible()
    await expect(apply).toBeEnabled()
    await apply.click()
  }
  await expect(control).toHaveCount(0)
}

/**
 * Wait until exactly one row of the window shows this text, and read its key.
 *
 * The one place a spec finds a row by what it shows, and a forced one: the key of
 * a row a test has just created is minted by the server, so its text is all the
 * test knows until the row is on screen. Everything after goes by the key.
 *
 * @param expect The spec's expect.
 * @param page The Playwright page showing the table.
 * @param text Text only the wanted row carries, such as a stamped name.
 * @returns The row key.
 */
export async function tableRowKeyByText(
  expect: Expect,
  page: Page,
  text: string,
): Promise<string> {
  const row = page.getByTestId(TABLE).locator(ROWS).filter({ hasText: text })
  await expect(row).toHaveCount(1)

  return ((await row.getAttribute('data-id')) ?? '').slice(ROW_PREFIX.length)
}
