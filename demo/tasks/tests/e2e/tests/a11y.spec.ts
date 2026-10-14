import { test, expect, type Page } from '@playwright/test'
import { grantAdminToSelf } from '../helpers/adminGrant'
import { gotoPage } from '../helpers/page'

// Hilos accessibility (a11y) e2e — the rarely-run a11y category (see
// docs/agents/testing.md "Selective testing"). Asserts the framework viewport
// table exposes a correct accessibility tree over the live socket: an accessible
// name from the page heading it stands under, a labeled search box, and a sortable
// header that reports aria-sort and is operable from the keyboard. The tasks
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
  await grantAdminToSelf(page)

  await openUsers(page)

  // The page heading is the table's accessible name: a declared table carries no
  // title of its own, and points at the heading that already names it.
  await expect(page.getByRole('table', { name: 'Users' })).toBeVisible()

  // The search box is reachable by an accessible name, not just a placeholder.
  await expect(
    page.getByRole('searchbox', { name: 'Search users…' }),
  ).toBeVisible()
})

test('a sortable header reports aria-sort and sorts from the keyboard', async ({
  page,
}) => {
  await grantAdminToSelf(page)

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

  // No admin entry for a plain visitor: since HIL-610 a visitor carries no account
  // at all, so the shell draws no way into a surface the page access gate would
  // refuse anyway.
  await expect(page.getByTestId('nav-admin')).toHaveCount(0)

  // Granted, the same shell grows the entry — and marks it as the active nav
  // item, which is the half of this test's subject the gear had to arrive for:
  // aria-current is how assistive tech reads "you are here" off a nav.
  await grantAdminToSelf(page)
  await gotoPage(page, '/hilos')
  await expect(page.getByTestId('nav-admin')).toHaveAttribute(
    'aria-current',
    'page',
  )
})

test('each page titles the tab and announces the page on navigation', async ({
  page,
}) => {
  await grantAdminToSelf(page)

  await openUsers(page)

  // The users admin titles the browser tab (framework label + app name), and the
  // live region carries the same title for a screen-reader announcement.
  await expect(page).toHaveTitle('Users · Hilos Tasks')
  await expect(page.getByTestId('page-title')).toHaveText('Users · Hilos Tasks')

  // A no-refresh navigation (the brand → home) updates both the tab title and
  // the announcement.
  await page.getByTestId('nav-brand').click()
  await expect(page).toHaveTitle('Tasks · Hilos Tasks')
  await expect(page.getByTestId('page-title')).toHaveText('Tasks · Hilos Tasks')
})

test('the home page exposes a single top-level heading', async ({ page }) => {
  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Tasks')
})

test('environment names both windows and its reading ladder, with a keyboard attention filter', async ({
  page,
}) => {
  await grantAdminToSelf(page)
  await gotoPage(page, '/hilos/daemon/standalone/env')
  await expect(page.getByTestId('hilos-admin-page')).toHaveAttribute(
    'data-page',
    'hilos_daemon_env',
  )
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(
    'Environment',
  )
  await expect(
    page.getByRole('table', { name: 'Catalog keys', exact: true }),
  ).toBeVisible()
  await expect(
    page.getByRole('table', { name: 'Orphans in .env', exact: true }),
  ).toBeVisible()
  await expect(
    page.getByRole('table', { name: 'Reading ladder', exact: true }),
  ).toBeVisible()
  const search = page.getByTestId('hilos-table-search')
  await expect(search).toHaveAccessibleName('Key or value')
  await search.fill('')
  await search.pressSequentially('APP_ENV', { delay: 10 })
  await expect(page.getByTestId('hilos-table-row-APP_ENV')).toBeVisible()
  const attention = page.getByTestId('hilos-table-filter-attention')
  await expect(attention).toHaveAccessibleName('Needs attention only')
  await attention.focus()
  await expect(attention).toBeFocused()
  await attention.press('Space')
  await expect(attention).toBeChecked()
  await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
  await expect(
    page
      .getByTestId('hilos-daemon-env-keys-empty-attention')
      .filter({ visible: true }),
  ).toHaveText('No key needs attention.')
})
