import { test, expect, type Browser, type Page } from '@playwright/test'
import { grantAdminToSelf, setAdmin } from '../helpers/adminGrant'
import { gotoPage, PAGE_REFUSED } from '../helpers/page'

// Hilos users admin e2e: /hilos/users renders the framework users table over the
// live socket, the client's own granted row is present, search filters the client
// viewport, a row links to the user detail page, and a modal rename round-trips
// through the backend and re-renders with no document reload.

/**
 * Open a second visitor in a context of its own, so the users table holds a row that is
 * not the admin's own.
 *
 * Identity here is the session cookie, so two pages of one context are one browser and one
 * account. A context made off the browser fixture inherits none of the project's `use`
 * options - including the tolerance for the self-signed certificate the test nginx serves -
 * so they are passed on by hand.
 *
 * @param browser The browser the test runs in.
 * @returns A page belonging to a fresh visitor.
 */
async function openSecondVisitor(browser: Browser): Promise<Page> {
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  const context = await browser.newContext({ baseURL, ignoreHTTPSErrors })

  return context.newPage()
}

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
  // The visitor here is anonymous — since HIL-610 the handshake leaves the session
  // without a user at all — and so holds no grant either. The access gate refuses
  // the subscription outright rather than rendering an empty table, and the shell
  // offers no way in: no gear to click.
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
  // Give this browser an account and take its id from the grant reply (HIL-610:
  // a visitor has no user row, and the page publishes no id for one), then open
  // its detail directly. Robust against how many users the shared DB has
  // accumulated: the self row need not be on the first viewport page of
  // /hilos/users (which shows only the first 10 by ascending id).
  const userId = await grantAdminToSelf(page)

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

test('a rename in one tab lands at once in another, raising no Apply', async ({
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

  // Tab B receives the rename from the other connection and shows it at once: the
  // window is ordered by id, so a rename moves nothing, and what the gate holds is
  // the position and the membership of the rows rather than the fields of a record
  // (HIL-793). The user is an entity reference, so the name cell tracks the rename
  // reactively; what is new is that no Apply control is raised behind it, and no
  // press is needed to make the screen true. First two-window test on the React layer.
  await expect(tabB.locator('tbody tr', { hasText: newName })).toHaveCount(1)
  await expect(tabB.getByTestId('hilos-table-apply')).toHaveCount(0)
  await expect(tabB.locator('tbody tr', { hasText: newName })).not.toHaveClass(
    /table-warning/,
  )
  await tabB.close()
})

test('opens the refused users admin the moment the grant lands', async ({
  page,
}) => {
  // HIL-621 acceptance, the promotion half. The visitor is sitting on the
  // refusal - not on a page they navigated to after being granted - and the grant
  // has to reach that open page on its own. Before this, a granted visitor kept
  // reading the 403 until they reloaded.
  const userId = await grantAdminToSelf(page)
  await setAdmin(userId, false)
  await gotoPage(page, '/hilos/users', PAGE_REFUSED)
  await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)

  await setAdmin(userId, true)

  // No navigation between the grant and these assertions: the server re-decides
  // the subscription this tab already holds and answers it with the page.
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(
    page.locator('[data-id^="hilos-users-open-"]').first(),
  ).toBeVisible()
  await expect(page.getByTestId('page-error')).toHaveCount(0)
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

  // The page standing open is refused where it stands - no navigation, no
  // reload - and the refusal must not call this visitor a guest: the session is
  // the same signed-in one, only the flag went (HIL-776). The word alone is
  // asserted here; the sentence is pinned by the SDK unit.
  const refusal = page.getByTestId('page-error')
  await expect(refusal).toBeVisible()
  await expect(refusal).toHaveAttribute('data-error-code', '403')
  await expect(refusal).not.toContainText(/guest/i)

  // And the page itself is shut again, not merely unlinked: the access gate
  // refuses the subscription on the next visit.
  await gotoPage(page, '/hilos/users', PAGE_REFUSED)
  await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
})

// HIL-824: the takeover button is drawn by the SDK page, not by this project. The backend
// route is one shared framework path for all three demos and is covered where it lives
// (demo/chat), so what is worth a case here is the only thing that differs — the markup —
// and a visible button is what proves it reached this framework's view.
test('draws the framework takeover button on a row that is not your own', async ({
  page,
  browser,
}) => {
  // A second visitor, so the table holds a row other than the admin's own: the control is
  // offered on every row but yours, since taking yourself over is refused server-side.
  const other = await openSecondVisitor(browser)
  await grantAdminToSelf(other)

  await grantAdminToSelf(page)
  await openUsers(page)

  await expect(
    page.locator('[data-id^="hilos-users-impersonate-"]').first(),
  ).toBeVisible()
})
