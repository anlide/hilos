import { expect, type Locator, type Page } from '@playwright/test'

// What a spec reads off a live viewport table (HilosViewportTable) and how it
// moves the table's window. Every handle is one the table's own registry names
// (docs/agents/frontend/table-subscription.md, "Stable selectors"), and every
// wait is on state that arrived over the socket rather than on a timeout.
//
// It lives with the demo rather than in framework/frontend/e2e/: each function
// here asserts with `expect`, a value import from @playwright/test that the
// shared folder refuses (its index.ts), and a helper that drives Playwright stays
// with the demo that runs it (HIL-954).

/** The root the table draws under; a page with one table keeps the default. */
const TABLE = 'hilos-viewport-table'

/** The footer's count: `${N} total` while it is exact, `${N}+ total` at the ceiling. */
const COUNT = 'hilos-table-count'

/** The props-drawn footer's page caption, `${page} / ${pageCount}`. */
const PAGE_CAPTION = 'hilos-table-page'

/** The control that turns to the next page, disabled exactly when there is none. */
const NEXT = 'hilos-table-next'

/** What every row of the window carries in `data-id`, followed by its row key. */
const ROW_PREFIX = 'hilos-table-row-'

/**
 * The rows of the window in the order they are drawn. The detail an expanded row
 * opens shares the prefix without being a row, and the card branch names its rows
 * differently, so reading the table's own body leaves exactly the rows.
 */
const ROWS = `tbody [data-id^="${ROW_PREFIX}"]:not([data-id^="${ROW_PREFIX}detail-"])`

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
 * Read the total the footer shows.
 *
 * Waits only for the footer to show a count at all; a spec that expects a
 * particular number waits for it with {@link expectTableTotal}.
 *
 * @param page The Playwright page showing the table.
 * @returns The leading number of the count.
 */
export async function tableTotal(page: Page): Promise<number> {
  const count = page.getByTestId(COUNT)
  await expect(count).toHaveText(/^\s*\d+\+? total\s*$/)

  return Number.parseInt(((await count.textContent()) ?? '').trim(), 10)
}

/**
 * Wait until the footer shows exactly this total.
 *
 * The count is the one thing every live frame about a new row moves — an
 * append, an announcement and a bare count alike — so waiting for it is waiting
 * for the frame. A spec asserting that something did NOT change waits for this
 * first, or its assertion passes only because the frame has not come yet.
 *
 * @param page The Playwright page showing the table.
 * @param total The exact total expected.
 */
export async function expectTableTotal(
  page: Page,
  total: number,
): Promise<void> {
  await expect(page.getByTestId(COUNT)).toHaveText(`${total} total`)
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

/**
 * Read how far below the top of the table's root its first row stands.
 *
 * A live message over the table takes room that was held before it arrived, so
 * this number is the same before a message comes and after it goes
 * (styling-rules.md, "The room a live message takes"): the room stands between
 * the root's top and the rows, and any change of its height changes the number.
 *
 * It is read against the root rather than against the screen or the document:
 * the shell scrolls its own main container (HilosLayout), a click scrolls its
 * target into view there, and a row that stood still would read as moved
 * (HIL-1032, a 22px drift after Show). Both edges are read in one pass, so no
 * scroll can fall between them.
 *
 * @param page The Playwright page showing the table.
 * @returns The distance from the root's top edge to the first row's, in CSS pixels.
 */
export async function tableFirstRowTop(page: Page): Promise<number> {
  return page
    .getByTestId(TABLE)
    .evaluate((root, selector) => {
      const row = root.querySelector(selector)
      if (row === null) {
        throw new Error('the table shows no row to measure')
      }

      return row.getBoundingClientRect().top - root.getBoundingClientRect().top
    }, ROWS)
}

/**
 * Wait until exactly one row of the window shows this text, and read its key.
 *
 * The one place a spec finds a row by what it shows, and a forced one: the key of
 * a row a test has just created is minted by the server, so its text is all the
 * test knows until the row is on screen. Everything after goes by the key.
 *
 * @param page The Playwright page showing the table.
 * @param text Text only the wanted row carries, such as a stamped name.
 * @returns The row key.
 */
export async function tableRowKeyByText(
  page: Page,
  text: string,
): Promise<string> {
  const row = rows(page).filter({ hasText: text })
  await expect(row).toHaveCount(1)

  return ((await row.getAttribute('data-id')) ?? '').slice(ROW_PREFIX.length)
}

/**
 * Read the page number the caption shows.
 *
 * @param page The Playwright page showing the table.
 * @returns The 1-based page number.
 */
async function captionPage(page: Page): Promise<number> {
  const caption = page.getByTestId(PAGE_CAPTION)
  await expect(caption).toHaveText(/^\s*\d+ \/ \d+\s*$/)

  return Number.parseInt(((await caption.textContent()) ?? '').trim(), 10)
}

/**
 * Turn pages until there is no next one, waiting for each window to arrive.
 *
 * The caption alone does not say a window arrived: the controller moves the page
 * number the moment Next is pressed, and the rows of the page before stay on
 * screen until the answer comes (TableViewportController.nextPage). A test that
 * trusted the number read the old rows, and one that pressed Next again paged
 * from a boundary the server had not answered yet. So after every press this
 * waits for the number AND for a first row that is not the one the page before
 * began with — two pages of one order share no row, so a different first row is
 * the new window and nothing else. A caption that is missing is a failure here,
 * not a reason to stop on the first page.
 *
 * @param page The Playwright page showing the table.
 * @returns The 1-based number of the page reached.
 */
export async function goToLastPage(page: Page): Promise<number> {
  const caption = page.getByTestId(PAGE_CAPTION)
  const next = page.getByTestId(NEXT)
  let reached = await captionPage(page)

  while (await next.isEnabled()) {
    const firstKeyBefore = (await tableRowKeys(page))[0]
    await next.click()
    reached += 1
    await expect(caption).toHaveText(new RegExp(`^\\s*${reached} / \\d+\\s*$`))
    await expect
      .poll(async () => {
        const keys = await tableRowKeys(page)

        return keys.length > 0 && keys[0] !== firstKeyBefore
      })
      .toBe(true)
  }

  return reached
}
