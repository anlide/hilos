import { test, expect, type Page } from '@playwright/test'
import { grantAdminToSelf, setAdmin } from '../helpers/adminGrant'
import { gotoPage, PAGE_REFUSED } from '../helpers/page'

// Hilos users admin e2e: /hilos/users renders the framework users table over the
// live socket, the client's own self-registered row is present, search filters
// the client viewport, a row links to the user detail page, and a modal rename
// round-trips through the backend and re-renders with no document reload.

/** Open the users admin and wait for the live table's first row. */
async function openUsers(page: Page): Promise<void> {
  await gotoPage(page, '/hilos/users')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(
    page.locator('[data-id^="hilos-users-open-"]').first(),
  ).toBeVisible()
}

test('refuses the users admin to a visitor without the grant', async ({
  page,
}) => {
  // The visitor here is not anonymous — the handshake maps the session cookie to
  // a durable user row — it is a known user holding no grant. The access gate
  // refuses the subscription outright rather than rendering an empty table, and
  // the shell offers no way in: no gear to click.
  await gotoPage(page, '/hilos/users', PAGE_REFUSED)
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('nav-admin')).toHaveCount(0)
  await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
})

test('lists users in the framework table and opens a detail page', async ({
  page,
}) => {
  await grantAdminToSelf(page)

  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await gotoPage(page, '/hilos/users')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Users')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  const loadsAfterColdLoad = fullLoads

  // The connected client self-registers, so at least its own row is present.
  const firstOpen = page.locator('[data-id^="hilos-users-open-"]').first()
  await expect(firstOpen).toBeVisible()

  // Row -> the user detail page, over the live socket (no document reload).
  await firstOpen.click()
  await expect(page).toHaveURL(/\/hilos\/user\/\d+$/)
  await expect(page.getByTestId('hilos-user-detail')).toBeVisible()
  await expect(page.getByTestId('hilos-user-name')).toBeVisible()
  expect(fullLoads).toBe(loadsAfterColdLoad)
})

test('filters the users table from the search box', async ({ page }) => {
  await grantAdminToSelf(page)

  await gotoPage(page, '/hilos/users')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(
    page.locator('[data-id^="hilos-users-open-"]').first(),
  ).toBeVisible()

  // A query no name matches empties the viewport; clearing it restores rows.
  const search = page.getByTestId('hilos-table-search')
  await search.fill('zzz-no-such-user-zzz')
  await expect(page.locator('[data-id^="hilos-users-open-"]')).toHaveCount(0)
  await search.fill('')
  await expect(
    page.locator('[data-id^="hilos-users-open-"]').first(),
  ).toBeVisible()
})

test('renames a user from the detail page and re-renders live', async ({
  page,
}) => {
  await grantAdminToSelf(page)

  await gotoPage(page, '/hilos/users')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await page.locator('[data-id^="hilos-users-open-"]').first().click()
  await expect(page.getByTestId('hilos-user-detail')).toBeVisible()

  const newName = 'E2E Renamed User'
  await page.getByTestId('hilos-user-edit').click()
  await page.getByTestId('hilos-user-name-input').fill(newName)
  await page.getByTestId('hilos-user-save').click()

  // The committed name returns over the live table and the edit form closes.
  await expect(page.getByTestId('hilos-user-name')).toHaveText(newName)
  await expect(page.getByTestId('hilos-user-name-input')).toHaveCount(0)
})

// FLAKY under the full multi-demo e2e battery: the daemon degrades under
// concurrent load and the detail table's presence fields under-populate (the
// live user renders offline with 0 sessions), same family as the bots "No
// suitable regular worker" flake. Passes in isolation; disabled pending
// HIL-376 (daemon degradation).
test.fixme('shows the connected user as online with a live session', async ({
  page,
}) => {
  // Read the client's own id (auto-minted on the handshake), then open its detail
  // directly. Robust against how many users the shared DB has accumulated: the
  // self row need not be on the first viewport page of /hilos/users (which shows
  // only the first 10 by ascending id).
  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('self-user')).not.toBeEmpty()
  const userId = await page.getByTestId('self-user-id').textContent()

  await gotoPage(page, `/hilos/user/${userId}`)
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-user-detail')).toBeVisible()

  // Regression: a connected user must render online with at least one live
  // session. The detail table's computed presence fields were never populated
  // (the project browser context returned null), so the live user rendered as
  // offline with 0 sessions.
  await expect(page.getByTestId('hilos-user-sessions')).toHaveText(/[1-9]/)
  await expect(
    page.locator('[data-id="hilos-user-detail"] .badge'),
  ).toHaveText('online')
})

test('a rename in one tab hangs as pending in another until applied', async ({
  page,
}) => {
  await grantAdminToSelf(page)

  const newName = `E2E Pending Rename ${Date.now()}`

  // Both tabs watch the users list; tab A then renames a user from its detail page.
  const tabB = await page.context().newPage()
  await openUsers(page)
  await openUsers(tabB)

  // Tab A opens the first user and renames it; its own detail applies at once.
  await page.locator('[data-id^="hilos-users-open-"]').first().click()
  await expect(page.getByTestId('hilos-user-detail')).toBeVisible()
  await page.getByTestId('hilos-user-edit').click()
  await page.getByTestId('hilos-user-name-input').fill(newName)
  await page.getByTestId('hilos-user-save').click()
  await expect(page.getByTestId('hilos-user-name')).toHaveText(newName)

  // Tab B receives the rename from the other connection as a pending update: an
  // Apply control and a tinted row. The user is an entity reference, so the name
  // cell tracks the rename reactively; the pending gate still holds (the row
  // keeps its place) until tab B applies. First two-window test on the React layer.
  await expect(tabB.getByTestId('hilos-table-apply')).toBeVisible()
  await expect(tabB.locator('tbody tr', { hasText: newName })).toHaveClass(
    /table-warning/,
  )

  // Applying clears the pending gate in place.
  await tabB.getByTestId('hilos-table-apply').click()
  await expect(tabB.getByTestId('hilos-table-apply')).toHaveCount(0)
  await expect(tabB.locator('tbody tr', { hasText: newName })).not.toHaveClass(
    /table-warning/,
  )
  await tabB.close()
})

test('a revoked admin loses the gear and the door', async ({ page }) => {
  // Keeps the framework admin:grant / admin:revoke route walked end to end now
  // that grantAdminToSelf drives admin:create instead (HIL-609): these two demos
  // are the only e2e it has, since chat flips the flag through its own project
  // command. Only the revoke is sent, and that covers the route rather than half
  // of it — both wire names land on one handler and differ in nothing but the
  // boolean in the payload, which the framework unit test pins separately.
  const userId = await grantAdminToSelf(page)
  await gotoPage(page, '/hilos/users')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()

  await setAdmin(userId, false)

  // The revoke re-sends the handshake response to this user's live connections,
  // so the entry goes away without a reload — the same path the grant appeared
  // by, run backwards.
  await expect(page.getByTestId('nav-admin')).toHaveCount(0)

  // And the page itself is shut again, not merely unlinked: the access gate
  // refuses the subscription on the next visit.
  await gotoPage(page, '/hilos/users', PAGE_REFUSED)
  await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
})
