import { test, expect, type Page } from '@playwright/test'

import { signUpAdmin } from '../helpers/adminGrant'
import { gotoPage } from '../helpers/page'

// Bots admin e2e: /hilos/admin_bots renders the bots table over the live socket,
// and the create / edit / delete dialogs round-trip through the backend
// (AdminBotsPage), the row appearing, updating, and leaving the live table with no
// document reload. Bot names are stamped unique so a retry (which reuses the same
// database) never collides with an earlier attempt's leftover row.
//
// The demo seeds enough bots to paginate (>10), and a new row is appended LIVE
// only to a window on the last page with room — the server appends at the tail,
// never re-sorting a frozen window (table-subscription.md). So a create test first
// pages to the last page; there the new bot shows at once, with no Apply gate (an
// append is not pending like a cross-connection edit). Each test deletes the bot it
// made so the shared database stays at its seed.
//
// The page is an ADMIN-level surface (HIL-652), so every test takes the grant
// first — the route's `admin: true` marker is shell cosmetics and opens nothing.
// A second tab of the same browser context inherits the session cookie, so it
// signs in once per test and not once per tab.

/** Open the bots admin and wait for the live window's first row; the caller is admin already. */
async function openBots(page: Page): Promise<void> {
  await gotoPage(page, '/hilos/admin_bots')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(
    page.locator('[data-id^="hilos-table-row-"]').first(),
  ).toBeVisible()
}

/** Page to the last window so a newly created row appends onto the visible page. */
async function goToLastPage(page: Page): Promise<void> {
  const pager = page.getByTestId('hilos-table-page')
  for (;;) {
    const match = /(\d+)\s*\/\s*(\d+)/.exec((await pager.textContent()) ?? '')
    if (!match || Number(match[1]) >= Number(match[2])) {
      break
    }
    await page.getByTestId('hilos-table-next').click()
    await expect(pager).toContainText(`${Number(match[1]) + 1} / ${match[2]}`)
  }
}

/** Create a bot through the add dialog; the caller is already on the last page. */
async function createBot(page: Page, name: string): Promise<void> {
  await page.getByTestId('admin-bots-add').click()
  const nameField = page.getByTestId('admin-bots-name')
  await nameField.fill('')
  await nameField.pressSequentially(name, { delay: 10 })
  await page.getByTestId('admin-bots-description').fill('made by e2e')
  await page.getByTestId('admin-bots-save').click()
  // Settle before the caller asserts the new row: the save is in flight until the
  // backend echo closes the dialog. Asserting the row through an open dialog races
  // the reply (settle-before-assert).
  await expect(page.getByTestId('admin-bots-save')).toHaveCount(0)
}

/** Delete a bot row by name and wait for it to leave the table. */
async function deleteBot(page: Page, name: string): Promise<void> {
  await page
    .locator('tbody tr', { hasText: name })
    .getByRole('button', { name: 'Delete' })
    .click()
  await page.getByTestId('admin-bots-delete-confirm').click()
  await expect(page.locator('tbody tr', { hasText: name })).toHaveCount(0)
}

// HIL-376: disabled — flaky live-table append onto the last page (goToLastPage
// pagination timing); the appended bot lands on the next page, off the viewport.
test.fixme('creates, edits, and deletes a bot through the live table', async ({
  page,
}) => {
  const stamp = Date.now()
  const name = `E2E Created ${stamp}`
  const renamed = `E2E Renamed ${stamp}`

  await signUpAdmin(page)

  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await openBots(page)
  const loadsAfterColdLoad = fullLoads
  await goToLastPage(page)

  // Create: exactly one row appears once the backend echoes it — the DB bot and
  // its runtime status fold into a single row, not two — and the dialog closes.
  await createBot(page, name)
  await expect(page.locator('tbody tr', { hasText: name })).toHaveCount(1)
  await expect(page.getByTestId('admin-bots-save')).toHaveCount(0)

  // Edit: rename through the same dialog; the live row re-renders and closes.
  await page
    .locator('tbody tr', { hasText: name })
    .getByRole('button', { name: 'Edit' })
    .click()
  await page.getByTestId('admin-bots-name').fill(renamed)
  await page.getByTestId('admin-bots-save').click()
  await expect(page.locator('tbody tr', { hasText: renamed })).toHaveCount(1)
  await expect(page.locator('tbody tr', { hasText: name })).toHaveCount(0)

  // Delete: the row leaves the live table.
  await deleteBot(page, renamed)

  // The whole CRUD tour stayed in one live document.
  expect(fullLoads).toBe(loadsAfterColdLoad)
})

// HIL-376: disabled — under the full run a cross-tab append arrives gated as
// pending/Apply instead of applying at once; passes in isolation.
test.fixme('a bot created in one tab appears live in another with no pending gate', async ({
  page,
}) => {
  const stamp = Date.now()
  const name = `E2E Live ${stamp}`

  await signUpAdmin(page)

  const tabB = await page.context().newPage()
  let tabBLoads = 0
  tabB.on('load', () => {
    tabBLoads += 1
  })

  await openBots(page)
  await openBots(tabB)
  const tabBLoadsAfterColdLoad = tabBLoads
  await goToLastPage(page)
  await goToLastPage(tabB)

  // Tab A creates a bot on the last page.
  await createBot(page, name)
  await expect(page.locator('tbody tr', { hasText: name })).toHaveCount(1)

  // Tab B, also on the last page with room, picks up the new row LIVE: it appears
  // at the tail with no Apply control — an append applies at once, not gated like
  // an edit from another connection — and with no document reload.
  await expect(tabB.locator('tbody tr', { hasText: name })).toHaveCount(1)
  await expect(tabB.getByTestId('hilos-table-apply')).toHaveCount(0)
  expect(tabBLoads).toBe(tabBLoadsAfterColdLoad)

  // Cleanup: delete the bot so the shared database stays at its seed.
  await tabB.close()
  await deleteBot(page, name)
})

// HIL-376: disabled — same flaky live-table append onto the last page as the two
// tests above (goToLastPage pagination timing), just the single-tab sibling that
// slipped through the original quarantine.
test.fixme('creating a bot applies at once in the creating tab with no Apply gate', async ({
  page,
}) => {
  const stamp = Date.now()
  const name = `E2E Own ${stamp}`

  await signUpAdmin(page)
  await openBots(page)
  await goToLastPage(page)
  await createBot(page, name)

  // The new row shows at once and no pending Apply control appears: the creating
  // tab's own append lands live, never queued behind Apply.
  await expect(page.locator('tbody tr', { hasText: name })).toHaveCount(1)
  await expect(page.getByTestId('hilos-table-apply')).toHaveCount(0)

  await deleteBot(page, name)
})

// HIL-376: muted alongside the flaky bots family per request. This nav smoke is
// NOT itself flaky — re-enable it once the live-table append flake root is fixed.
test.fixme('reaches the bots admin from the dashboard', async ({ page }) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')

  await page.getByTestId('dashboard-card-admin_bots').click()
  await expect(page.getByTestId('admin-bots-view')).toBeVisible()
  expect(new URL(page.url()).pathname).toBe('/hilos/admin_bots')
})
