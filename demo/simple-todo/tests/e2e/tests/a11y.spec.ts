import { test, expect, type Page } from '@playwright/test'
import { gotoPage } from '../helpers/page'

// Hilos accessibility (a11y) e2e — the rarely-run a11y category (see
// docs/agents/testing.md "Selective testing"). Asserts the framework viewport
// table exposes a correct accessibility tree over the live socket: an accessible
// name from its visually-hidden caption, a labelled search box, and a sortable
// header that reports aria-sort and is operable from the keyboard. The todo
// (React) layer checks this on the users table; the file grows as the a11y arc
// lands its later steps (modals, app-shell, focus).

/** Open the users admin and wait for the live table. */
async function openUsers(page: Page): Promise<void> {
  await gotoPage(page, '/hilos/users')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
}

test('the viewport table has an accessible name and a labelled search', async ({
  page,
}) => {
  // TODO(HIL-553): parked 2026-08-08 — HIL-441 closed the framework admin
  // surface by default, and this demo has no identity seam or admin grant yet,
  // so this anonymous /hilos spec is denied a 401. Pre-existing test broken by
  // the access inversion, not by its own subject; attempt 1. Un-parking is
  // deleting these lines once HIL-553 lands the demo identity + grant.
  test.fixme()

  await openUsers(page)

  // The visually-hidden <caption> is the table's accessible name.
  await expect(page.getByRole('table', { name: 'Users' })).toBeVisible()

  // The search box is reachable by an accessible name, not just a placeholder.
  await expect(
    page.getByRole('searchbox', { name: 'Search users…' }),
  ).toBeVisible()
})

test('a sortable header reports aria-sort and sorts from the keyboard', async ({
  page,
}) => {
  // TODO(HIL-553): parked 2026-08-08 — HIL-441 closed the framework admin
  // surface by default, and this demo has no identity seam or admin grant yet,
  // so this anonymous /hilos spec is denied a 401. Pre-existing test broken by
  // the access inversion, not by its own subject; attempt 1. Un-parking is
  // deleting these lines once HIL-553 lands the demo identity + grant.
  test.fixme()

  await openUsers(page)

  const header = page.locator('th:has([data-id^="hilos-table-sort-"])').first()
  const sortButton = header.locator('[data-id^="hilos-table-sort-"]')

  // The sortable header announces a sort state for assistive tech.
  await expect(header).toHaveAttribute(
    'aria-sort',
    /^(none|ascending|descending)$/,
  )
  const before = await header.getAttribute('aria-sort')

  // Its sort control is operable from the keyboard: focusing it and pressing
  // Enter re-sorts the column and the header announces the new direction. The
  // table is server-windowed, so wait for the announced state to change rather
  // than snapshot it (the new descriptor round-trips to the backend).
  await sortButton.focus()
  await expect(sortButton).toBeFocused()
  await page.keyboard.press('Enter')
  await expect(header).not.toHaveAttribute('aria-sort', before ?? 'none')
  await expect(header).toHaveAttribute('aria-sort', /^(ascending|descending)$/)
})

test('the shell exposes a skip link and marks the active nav item', async ({
  page,
}) => {
  await gotoPage(page, '/hilos')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')

  // The skip link targets the main landmark.
  await expect(page.getByTestId('skip-to-content')).toHaveAttribute(
    'href',
    '#hilos-main-content',
  )
  await expect(page.locator('main#hilos-main-content')).toBeVisible()

  // The admin gear points at /hilos, the current page, so it is aria-current.
  await expect(page.getByTestId('nav-admin')).toHaveAttribute(
    'aria-current',
    'page',
  )
})

test('each page titles the tab and announces the page on navigation', async ({
  page,
}) => {
  // TODO(HIL-553): parked 2026-08-08 — HIL-441 closed the framework admin
  // surface by default, and this demo has no identity seam or admin grant yet,
  // so this anonymous /hilos spec is denied a 401. Pre-existing test broken by
  // the access inversion, not by its own subject; attempt 1. Un-parking is
  // deleting these lines once HIL-553 lands the demo identity + grant.
  test.fixme()

  await openUsers(page)

  // The users admin titles the browser tab (framework label + app name), and the
  // live region carries the same title for a screen-reader announcement.
  await expect(page).toHaveTitle('Users · Hilos Todo')
  await expect(page.getByTestId('page-title')).toHaveText('Users · Hilos Todo')

  // A no-refresh navigation (the brand → home) updates both the tab title and
  // the announcement.
  await page.getByTestId('nav-brand').click()
  await expect(page).toHaveTitle('Tasks · Hilos Todo')
  await expect(page.getByTestId('page-title')).toHaveText('Tasks · Hilos Todo')
})

test('the home page exposes a single top-level heading', async ({ page }) => {
  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Tasks')
})
