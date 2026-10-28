import type { Expect, Locator, Page } from '@playwright/test'

// A table stands in the document twice: as a table for a wide screen and
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

/** The footer's count: `${start} – ${end} of ${total}` (or `${total}+` at the ceiling). */
const COUNT = 'hilos-table-count'

/** The control that turns to the next page, disabled exactly when there is none. */
const NEXT = 'hilos-table-next'

/** The control that turns one page back, disabled exactly when there is none. */
const PREV = 'hilos-table-prev'

/**
 * Read the total the footer shows.
 *
 * Waits only for the footer to show a count at all; a spec that expects a
 * particular number waits for it with {@link expectTableTotal}.
 *
 * @param expect The spec's expect.
 * @param page The Playwright page showing the table.
 * @returns The leading number of the count.
 */
export async function tableTotal(expect: Expect, page: Page): Promise<number> {
  const count = page.getByTestId(COUNT)
  await expect(count).toHaveText(/^\s*\d+ – \d+ of \d+\+?\s*$/)
  const text = (await count.textContent()) ?? ''
  const match = text.match(/of\s+(\d+)/)

  return match ? Number.parseInt(match[1], 10) : 0
}

/**
 * Wait until the footer shows exactly this total.
 *
 * The count is the one thing every live frame about a new row moves — an
 * append, an announcement and a bare count alike — so waiting for it is waiting
 * for the frame. A spec asserting that something did NOT change waits for this
 * first, or its assertion passes only because the frame has not come yet.
 *
 * @param expect The spec's expect.
 * @param page The Playwright page showing the table.
 * @param total The exact total expected.
 */
export async function expectTableTotal(
  expect: Expect,
  page: Page,
  total: number,
): Promise<void> {
  await expect(page.getByTestId(COUNT)).toHaveText(
    new RegExp(`of ${total}\\s*$`),
  )
}

/**
 * Read the row keys of the window, in the order the rows are drawn.
 *
 * @param page The Playwright page showing the table.
 * @param tableId The declared data-id of this table root.
 * @returns The row keys, first row first.
 */
export async function tableRowKeys(
  page: Page,
  tableId = TABLE,
): Promise<string[]> {
  const ids = await page
    .getByTestId(tableId)
    .locator(ROWS)
    .evaluateAll((elements) =>
      elements.map((element) => element.getAttribute('data-id') ?? ''),
    )

  return ids.map((id) => id.slice(ROW_PREFIX.length))
}

/**
 * The copies of an element that are on screen, for an element a table
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

/** The real reading control of one visible record, independent of its projection. */
export function tableTextToggle(page: Page, rowKey: string): Locator {
  return shownByTestId(
    page
      .getByTestId(`hilos-table-row-${rowKey}`)
      .or(page.getByTestId(`hilos-table-card-${rowKey}`)),
    'hilos-table-text-toggle',
  )
}

/** Check native semantics and every mounted control's unique, live text target. */
export async function expectTableTextControl(
  expect: Expect,
  page: Page,
  rowKey: string,
  expanded: boolean,
  label = 'Description',
): Promise<void> {
  const toggle = tableTextToggle(page, rowKey)
  await expect(toggle).toBeVisible()
  await expect(toggle).toHaveAttribute('type', 'button')
  await expect(toggle).toHaveText(expanded ? 'Collapse' : 'Show in full')
  await expect(toggle).toHaveAccessibleName(
    `${expanded ? 'Collapse' : 'Show in full'}: ${label}`,
  )
  await expect(toggle).toHaveAttribute('aria-expanded', String(expanded))
  const targets = await page
    .getByTestId('hilos-table-text-toggle')
    .evaluateAll((buttons) =>
      buttons.map((button) => {
        const id = button.getAttribute('aria-controls')
        const nodes = [...document.querySelectorAll('[id]')].filter(
          (node) => node.id === id,
        )
        return {
          id,
          count: nodes.length,
          text: nodes[0]?.classList.contains('hilos-table-text'),
          hidden: !!nodes[0]?.closest('[aria-hidden="true"], [inert]'),
          native: button instanceof HTMLButtonElement,
        }
      }),
    )
  expect(new Set(targets.map(({ id }) => id)).size).toBe(targets.length)
  for (const target of targets) {
    expect(target.id).toBeTruthy()
    expect(target.count).toBe(1)
    expect(target.text).toBe(true)
    expect(target.hidden).toBe(false)
    expect(target.native).toBe(true)
  }
}

/**
 * Settle a table after a row the page itself removed on the server: the
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

/** Ask for the current window through its announced-rows control. */
export async function showAnnouncedRows(
  expect: Expect,
  page: Page,
): Promise<void> {
  const show = page.getByTestId('hilos-table-announce-show')
  await expect(show).toBeVisible()
  await expect(show).toBeEnabled()
  await show.click()
  await expect(show).toBeHidden()
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

/**
 * Read the page number the footer shows.
 *
 * @param expect The spec's expect.
 * @param page The Playwright page showing the table.
 * @returns The 1-based page number.
 */
async function captionPage(expect: Expect, page: Page): Promise<number> {
  const activePage = page.locator(
    '[data-id^="hilos-table-page-"][aria-current="page"]',
  )
  const next = page.getByTestId(NEXT)

  // Wait until either a numbered page button, next control, or a table row
  // arrives so we do not read an unmounted or settling footer.
  await expect
    .poll(async () => {
      if ((await activePage.count()) > 0) {
        return true
      }
      if ((await next.count()) > 0) {
        return true
      }

      return (await tableRowKeys(page)).length > 0
    })
    .toBe(true)

  if ((await activePage.count()) > 0) {
    return Number.parseInt(((await activePage.textContent()) ?? '').trim(), 10)
  }

  return 1
}

/**
 * Turn one page back, waiting for the window to arrive rather than for the number.
 *
 * The same discipline {@link goToLastPage} keeps for Next, and for the same reason:
 * the number moves on the press while the rows of the window before stay on screen
 * until the answer comes. So this waits for a first row that is not the one this
 * window began with — two windows of one order that share a first row are one window.
 *
 * The page number is deliberately not waited on here. Where a window is taken from
 * a place rather than from a page boundary, two neighbouring windows can sit on one
 * page number (HIL-1093): the window at rows 2-11 of a page of ten and the window at
 * rows 1-10 both read as page one, and waiting for the number to change would wait
 * for something that never happens.
 *
 * @param expect The spec's expect.
 * @param page The Playwright page showing the table.
 */
export async function pageBackOnce(expect: Expect, page: Page): Promise<void> {
  const firstKeyBefore = (await tableRowKeys(page))[0]
  await page.getByTestId(PREV).click()
  await expect
    .poll(async () => {
      const keys = await tableRowKeys(page)

      return keys.length > 0 && keys[0] !== firstKeyBefore
    })
    .toBe(true)
}

/**
 * Turn pages until there is no next one, waiting for each window to arrive.
 *
 * The page number alone does not say a window arrived: the controller moves the
 * page number the moment Next is pressed, and the rows of the page before stay on
 * screen until the answer comes (TableViewportController.nextPage). A test that
 * trusted the number read the old rows, and one that pressed Next again paged
 * from a boundary the server had not answered yet. So after every press this
 * waits for the number AND for a first row that is not the one the page before
 * began with — two pages of one order share no row, so a different first row is
 * the new window and nothing else.
 *
 * @param expect The spec's expect.
 * @param page The Playwright page showing the table.
 * @returns The 1-based number of the page reached.
 */
export async function goToLastPage(
  expect: Expect,
  page: Page,
): Promise<number> {
  const next = page.getByTestId(NEXT)
  let reached = await captionPage(expect, page)

  if ((await next.count()) === 0) {
    return reached
  }

  while (await next.isEnabled()) {
    const firstKeyBefore = (await tableRowKeys(page))[0]
    await next.click()
    reached += 1
    const active = page.locator(
      `[data-id="hilos-table-page-${reached}"][aria-current="page"]`,
    )
    if ((await active.count()) > 0) {
      await expect(active).toHaveText(String(reached))
    }
    await expect
      .poll(async () => {
        const keys = await tableRowKeys(page)

        return keys.length > 0 && keys[0] !== firstKeyBefore
      })
      .toBe(true)
  }

  return reached
}

/**
 * Advance real server windows until a known row is visible. Start from a settled
 * window before the wanted row; no page number or server window size is assumed.
 *
 * @param expect The spec's expect.
 * @param page The page showing the table.
 * @param rowKey The key previously resolved from the real UI or wire.
 */
export async function advanceTableToRow(
  expect: Expect,
  page: Page,
  rowKey: string,
): Promise<void> {
  const table = page.getByTestId(TABLE)
  const row = table.getByTestId(`${ROW_PREFIX}${rowKey}`)
  const loading = table.getByTestId('hilos-table-loading')
  const keys = () =>
    table
      .locator(ROWS)
      .evaluateAll((rows) => rows.map((row) => row.getAttribute('data-id')))
  await expect(table).toBeVisible()
  await expect(loading).toHaveCount(0)
  await expect.poll(async () => (await keys()).length).toBeGreaterThan(0)
  while (!(await row.isVisible())) {
    const before = await keys()
    const next = table.getByTestId('hilos-table-next')
    await expect(next, `another window can contain row ${rowKey}`).toBeEnabled()
    await next.click()
    // The footer moves immediately; the changed, non-loading rows prove that
    // the server's next window has actually arrived before another step.
    await expect
      .poll(async () => {
        const after = await keys()
        return (
          after.length > 0 &&
          JSON.stringify(after) !== JSON.stringify(before) &&
          (await loading.count()) === 0
        )
      })
      .toBe(true)
  }
  await expect(row).toBeVisible()
}

/**
 * Check permitted movement by stable record identities in the visible tree.
 *
 * @param expect The spec's expect.
 * @param page The page containing the settled table.
 * @param keys The record order the action is permitted to produce.
 */
export async function expectTableRowOrder(
  expect: Expect,
  page: Page,
  keys: readonly string[],
): Promise<void> {
  const actual = await page
    .getByTestId(TABLE)
    .locator(`${ROWS}, [data-id^="hilos-table-card-"]`)
    .filter({ visible: true })
    .evaluateAll((rows) =>
      rows.map((row) =>
        row.getAttribute('data-id')?.replace(/^hilos-table-(row|card)-/, ''),
      ),
    )
  expect(actual).toEqual(keys)
}

/**
 * Capture the entire table, including slots below a shell's scrolling viewport.
 * Only the screenshot lifts ancestor clipping; the table keeps its measured
 * inline size so removing a scrollbar cannot rewrap the evidence.
 * Call after stopping geometry observations.
 *
 * @param expect The spec's expect.
 * @param page The page containing the settled table.
 * @param tableId The stable table root selected by the spec.
 * @returns The complete table's PNG.
 */
export async function screenshotTable(
  expect: Expect,
  page: Page,
  tableId = TABLE,
) {
  const root = page.getByTestId(tableId)
  await expect(root).toBeVisible()
  const width = await root.evaluate(
    (element) => element.getBoundingClientRect().width,
  )
  expect(width, 'the complete table has a visible inline size').toBeGreaterThan(
    0,
  )
  return root.screenshot({
    style: `:has([data-id="${tableId}"]) { overflow: visible !important; }
      [data-id="${tableId}"] { box-sizing: border-box !important;
        inline-size: ${width}px !important; min-inline-size: ${width}px !important;
        max-inline-size: ${width}px !important; }`,
  })
}

/**
 * Open a test-only HTML entry, which has no product page subscription to await.
 *
 * @param expect The spec's expect.
 * @param page The browser page.
 * @param path The built fixture HTML address.
 * @param readyId The fixture's declared readiness marker.
 */
export async function openTableFixture(
  expect: Expect,
  page: Page,
  path: string,
  readyId: string,
): Promise<void> {
  await page.goto(path)
  await expect(page.getByTestId(readyId)).toBeVisible()
}

/**
 * Check both mounted projections after a departure; hidden values count too.
 *
 * @param expect The spec's expect.
 * @param page The page containing the table.
 * @param rowKey The departed record's key.
 * @param reason The only remaining visible sentence.
 * @param valueIds All page-owned value/action IDs that must be retired.
 */
export async function expectDataFreeDeparture(
  expect: Expect,
  page: Page,
  rowKey: string,
  reason: string,
  valueIds: readonly string[],
): Promise<void> {
  for (const id of valueIds) await expect(page.getByTestId(id)).toHaveCount(0)
  for (const id of [
    `hilos-table-select-${rowKey}`,
    `hilos-table-expand-${rowKey}`,
    `hilos-table-row-detail-${rowKey}`,
    `hilos-table-progress-row-${rowKey}`,
    `hilos-table-stale-row-${rowKey}`,
    `hilos-table-pending-remove-${rowKey}`,
  ]) {
    await expect(page.getByTestId(id)).toHaveCount(0)
  }
  for (const prefix of ['hilos-table-row-', 'hilos-table-card-']) {
    const record = page.getByTestId(`${prefix}${rowKey}`)
    await expect(record.getByTestId('hilos-table-placeholder')).toHaveText(
      reason,
    )
    await expect(record.locator('button, input, [tabindex], [id]')).toHaveCount(
      0,
    )
    if (prefix === 'hilos-table-row-') {
      const span = await record.evaluate((row) => ({
        actual: row.querySelector('td')?.colSpan,
        columns: row.closest('table')?.querySelectorAll('thead th').length,
      }))
      expect(
        span.actual,
        'placeholder spans every content/state/selection track',
      ).toBe(span.columns)
    }
  }
}

/**
 * Empty sizing holders reserve room without borrowing a control or its name.
 *
 * @param expect The spec's expect.
 * @param page The page containing the table.
 */
export async function expectInertTableHolders(
  expect: Expect,
  page: Page,
): Promise<void> {
  const holders = page.locator(
    '.hilos-table-marks.invisible, .hilos-table-room, th .bi-snow.invisible',
  )
  expect(await holders.count()).toBeGreaterThan(0)
  for (const holder of await holders.all()) {
    await expect(holder).toHaveAttribute('aria-hidden', 'true')
    await expect(holder).toHaveAttribute('inert', '')
    await expect(
      holder.locator('[id], [data-id], input, button, a, [tabindex]'),
    ).toHaveCount(0)
  }
}

/**
 * Refuse card labels, page badges or controls that escape the card's width.
 * Vertical scrolling is allowed; truncating these facts horizontally is not.
 *
 * @param expect The spec's expect.
 * @param page The page containing the table.
 * @param tableId The stable table root selected by the spec.
 */
export async function expectTableCardContentFits(
  expect: Expect,
  page: Page,
  tableId = TABLE,
): Promise<void> {
  const overflows = await page.getByTestId(tableId).evaluate((root) => {
    const failures: unknown[] = []
    for (const card of root.querySelectorAll('.card[data-id]')) {
      if (!card.checkVisibility()) continue
      const outer = card.getBoundingClientRect()
      for (const element of card.querySelectorAll('dt, .badge, button')) {
        if (!element.checkVisibility({ visibilityProperty: true })) continue
        const box = element.getBoundingClientRect()
        if (box.left < outer.left - 1 || box.right > outer.right + 1) {
          failures.push({
            text: element.textContent,
            left: box.left,
            right: box.right,
            cardLeft: outer.left,
            cardRight: outer.right,
          })
        }
      }
    }
    return failures
  })
  expect(
    overflows,
    'labels, page badges and controls remain inside each card',
  ).toEqual([])
}

/**
 * A declared partial work track covers precisely its content column and the
 * work row still accounts for every framework/content/selection column.
 *
 * @param expect The spec's expect.
 * @param page The page containing the visible wide table.
 * @param rowKey The working row's key.
 * @param contentId A value inside the column declaring progress coverage.
 */
export async function expectTableProgressCoverage(
  expect: Expect,
  page: Page,
  rowKey: string,
  contentId: string,
): Promise<void> {
  const value = await shownByTestId(page, contentId).elementHandle()
  if (!value) throw new Error(`Missing column value ${contentId}`)
  try {
    const bounds = await shownByTestId(
      page,
      `hilos-table-progress-row-${rowKey}`,
    ).evaluate((row, value) => {
      const cell = value.closest('td')
      const progress = row.querySelector('[role="progressbar"]')?.closest('td')
      if (!cell || !progress) throw new Error('Missing wide progress coverage')
      const content = cell.getBoundingClientRect()
      const work = progress.getBoundingClientRect()
      return {
        content: { left: content.left, right: content.right },
        work: { left: work.left, right: work.right },
        spans: [...row.querySelectorAll('td')].reduce(
          (sum, cell) => sum + cell.colSpan,
          0,
        ),
        columns: row.closest('table')?.querySelectorAll('thead th').length,
      }
    }, value)
    expect(bounds.spans).toBe(bounds.columns)
    expect(
      Math.abs(bounds.content.left - bounds.work.left),
    ).toBeLessThanOrEqual(1)
    expect(
      Math.abs(bounds.content.right - bounds.work.right),
    ).toBeLessThanOrEqual(1)
  } finally {
    await value.dispose()
  }
}
