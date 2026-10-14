import { test, expect, type Page } from '@playwright/test'
import { grantAdminToSelf } from '../helpers/adminGrant'
import { gotoPage } from '../helpers/page'
import { openSignIn } from '../helpers/session'

// Hilos accessibility (a11y) e2e — the rarely-run a11y category (see
// docs/agents/testing.md "Selective testing"). Asserts the framework viewport
// table exposes a correct accessibility tree over the live socket: an accessible
// name from the page heading it stands under, a labeled search box, and a sortable
// header that reports aria-sort and is operable from the keyboard. The polls
// (Angular) layer checks this on the users table; the file grows as the a11y arc
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

  // No admin entry for a plain visitor: since HIL-611 a visitor carries no account
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
  await expect(page).toHaveTitle('Users · Hilos Polls')
  await expect(page.getByTestId('page-title')).toHaveText('Users · Hilos Polls')

  // A no-refresh navigation (the brand → home) updates both the tab title and
  // the announcement.
  await page.getByTestId('nav-brand').click()
  await expect(page).toHaveTitle('Polls · Hilos Polls')
  await expect(page.getByTestId('page-title')).toHaveText('Polls · Hilos Polls')
})

test('the home page exposes a single top-level heading', async ({ page }) => {
  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Polls')
})

test('the sign-in card holds room for a refusal before there is one', async ({
  page,
}) => {
  // The room is taken by an invisible twin of the row, so the refusal has
  // somewhere to land without moving the form (HIL-647). Angular has no
  // component world of its own yet (HIL-848), so this is where its copy of the
  // component is proved.
  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await openSignIn(page)

  await expect(page.getByTestId('auth-error-slot')).toBeAttached()
  await expect(page.getByTestId('auth-error-idle')).toHaveAttribute(
    'aria-hidden',
    'true',
  )
  await expect(page.getByTestId('auth-error')).toHaveCount(0)
})

test('an admin screen holds the refusal region before there is a refusal', async ({
  page,
}) => {
  // The same shape one layer up: the plate of a tracked action stands in a
  // permanent live region, and the room under it is held by an invisible twin
  // (HIL-887). A region inserted together with its own text announces nothing,
  // which is why it has to be there first. Angular has no component world of
  // its own yet (HIL-848), so this is where its copy of the component is
  // proved.
  await grantAdminToSelf(page)

  await gotoPage(page, '/hilos/logs/settings')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(
    page.getByTestId('hilos-setting-preset-settings-link'),
  ).toBeVisible()

  const slot = page.getByTestId('hilos-action-error-slot')
  await expect(slot).toBeAttached()
  await expect(slot).toHaveAttribute('role', 'alert')
  await expect(slot).toHaveAttribute('aria-live', 'assertive')
  await expect(page.getByTestId('hilos-action-error-idle')).toHaveAttribute(
    'aria-hidden',
    'true',
  )
  await expect(page.getByTestId('hilos-action-error')).toHaveCount(0)
})

test('Daemon tables, placement and restart confirmation are named and keyboard operable', async ({
  page,
}) => {
  await grantAdminToSelf(page)
  await gotoPage(page, '/hilos/daemon')
  await expect(page.getByTestId('hilos-daemon-inspector')).toBeVisible()
  await expect(page.locator('h1')).toHaveCount(1)
  const tab = page.getByTestId('hilos-daemon-placement-tab')
  await tab.focus()
  await tab.press('Enter')
  await expect(tab).toHaveAttribute('aria-pressed', 'true')
  await expect(page.getByRole('table', { name: 'Daemon' })).toBeVisible()
  const cell = page
    .getByTestId('hilos-daemon-placement-cell-hilos_daemon_node-standalone')
    .filter({ visible: true })
  await cell.focus()
  await cell.press('Enter')
  await expect(cell).toHaveAttribute('aria-expanded', 'true')
  const collapse = page
    .getByTestId('hilos-daemon-placement-collapse')
    .filter({ visible: true })
  await collapse.focus()
  await collapse.press('Space')
  await expect(cell).toHaveAttribute('aria-expanded', 'false')
  await gotoPage(page, '/hilos/daemon/standalone/workers')
  await expect(page.getByRole('table', { name: 'Workers' })).toBeVisible()
  await gotoPage(page, '/hilos/daemon/standalone/agents')
  await expect(page.getByRole('table', { name: 'Agents' })).toBeVisible()
  const restart = page
    .getByTestId('hilos-daemon-agent-restart-hilos_daemon_node')
    .filter({ visible: true })
  await restart.focus()
  await restart.press('Enter')
  const dialog = page.getByTestId('modal')
  await expect(dialog).toHaveAccessibleName('Restart agent')
  await expect(dialog).toBeFocused()
  await page.keyboard.press('Enter')
  await expect(dialog).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(dialog).toHaveCount(0)
  await expect(restart).toBeFocused()
})

test('Daemon Cron, HTTP, environment and comparison retain named headings and keyboard controls', async ({
  page,
}) => {
  // The four live subscriptions include a catalog search and filter round trip.
  test.slow()
  await grantAdminToSelf(page)
  await gotoPage(page, '/hilos/daemon/standalone/cron')
  await expect(page.getByRole('table', { name: 'Cron' })).toBeVisible()
  await expect(page.locator('h1')).toHaveCount(1)
  await expect(page.getByTestId(/^hilos-table-sort-/)).toHaveCount(0)
  await gotoPage(page, '/hilos/daemon/standalone/http')
  await expect(page.getByRole('table', { name: 'HTTP server' })).toBeVisible()
  await expect(page.locator('h1')).toHaveCount(1)
  await gotoPage(page, '/hilos/daemon/standalone/env')
  await expect(page.locator('h1')).toHaveCount(1)
  await expect(page.getByRole('table', { name: 'Catalog keys' })).toBeVisible()
  await expect(
    page.getByRole('table', { name: 'Reading ladder' }),
  ).toBeVisible()
  await expect(
    page.getByRole('table', { name: 'Orphans in .env' }),
  ).toBeVisible()
  const attention = page.getByTestId('hilos-table-filter-attention')
  await expect(attention).toHaveAccessibleName('Needs attention only')
  await attention.focus()
  await attention.press('Space')
  await expect(attention).toBeChecked()
  await attention.press('Space')
  await expect(attention).not.toBeChecked()
  const search = page.getByTestId('hilos-table-search')
  await expect(search).toHaveAccessibleName('Key or value')
  await search.focus()
  await search.fill('')
  await search.pressSequentially('DB_PASSWORD', { delay: 10 })
  await expect(
    page.getByTestId('hilos-table-row-DB_PASSWORD').filter({ visible: true }),
  ).toBeVisible()
  const crumb = page.getByTestId('hilos-breadcrumb-hilos_daemon')
  await crumb.focus()
  await crumb.press('Enter')
  await expect(page.getByTestId('hilos-daemon-inspector')).toBeVisible()
  const mismatch = page.getByTestId(
    'hilos-admin-child-hilos_daemon_env_mismatch',
  )
  await mismatch.focus()
  await mismatch.press('Enter')
  await expect(
    page.getByTestId('hilos-daemon-env-mismatch-standalone'),
  ).toBeVisible()
  await expect(page.locator('h1')).toHaveCount(1)
  await expect(page.getByRole('table')).toHaveCount(0)
})
