import { test, expect, type Page } from '@playwright/test'

// Hilos accessibility (a11y) e2e — the rarely-run a11y category (see
// docs/agents/testing.md "Selective testing"). Asserts the framework viewport
// table exposes a correct accessibility tree over the live socket: an accessible
// name from its visually-hidden caption, a labelled search box, and a sortable
// header that reports aria-sort and is operable from the keyboard. The chat (Vue)
// layer checks this on the settings table; the file grows as the a11y arc lands
// its later steps (modals, app-shell, focus).

/** Open the settings page and wait for the live table. */
async function openSettings(page: Page): Promise<void> {
  await page.goto('/hilos/settings')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
}

test('the viewport table has an accessible name and a labelled search', async ({
  page,
}) => {
  await openSettings(page)

  // The visually-hidden <caption> is the table's accessible name.
  await expect(page.getByRole('table', { name: 'Settings' })).toBeVisible()

  // The search box is reachable by an accessible name, not just a placeholder.
  await expect(
    page.getByRole('searchbox', { name: 'Search settings…' }),
  ).toBeVisible()
})

test('a sortable header reports aria-sort and sorts from the keyboard', async ({
  page,
}) => {
  await openSettings(page)

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
  await page.goto('/hilos')
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
  await openSettings(page)

  // The settings page titles the browser tab (framework label + app name), and
  // the live region carries the same title for a screen-reader announcement.
  await expect(page).toHaveTitle('Settings · Hilos Chat')
  await expect(page.getByTestId('page-title')).toHaveText(
    'Settings · Hilos Chat',
  )

  // A no-refresh navigation (the brand → home) updates both the tab title and
  // the announcement.
  await page.getByTestId('nav-brand').click()
  await expect(page).toHaveTitle('Conversations · Hilos Chat')
  await expect(page.getByTestId('page-title')).toHaveText(
    'Conversations · Hilos Chat',
  )
})
