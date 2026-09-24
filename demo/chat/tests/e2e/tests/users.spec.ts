import { test, expect, type Page } from '@playwright/test'

import { signUpAdmin } from '../helpers/adminGrant'
import { gotoPage } from '../helpers/page'
import { clickSubmit, typeInto } from '../helpers/session'
import { goToLastPage } from '../helpers/table'

// Hilos users admin e2e: /hilos/users renders the framework table (the first
// real table in the new frontend) over the live socket, a registered user's row
// is present, search filters the client viewport, a row links to the user detail
// page, and a modal rename round-trips through the backend and re-renders with no
// document reload. Under session≠user a fresh context is anonymous, so each test
// registers a user to populate the table (the register itself creates the row).

/**
 * The count the footer shows reads `${first} – ${last} of ${total}`. The pattern
 * pins the range on screen and takes any total: the shared database grows as the
 * suite runs, so a total is asserted by bounds rather than by value. This table
 * filters in memory, so its count is exact and never carries the ceiling's `+`.
 *
 * @param first 1-based number of the first row on screen.
 * @param last 1-based number of the last row on screen.
 * @returns The pattern the count's text matches.
 */
function countOf(first: number, last: number): RegExp {
  return new RegExp(`^\\s*${first} – ${last} of \\d+\\s*$`)
}

/**
 * Read the size of the set off the footer's count.
 *
 * @param text The count's text content.
 * @returns The total after "of", or NaN when the text carries none.
 */
function totalOf(text: string | null): number {
  return Number(/ of (\d+)/.exec(text ?? '')?.[1] ?? Number.NaN)
}

test('lists users in the framework table and opens a detail page', async ({
  page,
}) => {
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await signUpAdmin(page)
  await gotoPage(page, '/hilos/users')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Users')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  const loadsAfterColdLoad = fullLoads

  // A user is registered, so at least its own row is present.
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
  await signUpAdmin(page)
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
  await signUpAdmin(page)
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

// HIL-1050: the rename modal merges against the live row. Two tabs of one
// context on the card of the user this test registers itself: a pristine open
// modal follows the other tab's rename and says so, a typed one conflicts and
// locks Save, and Take theirs adopts the other tab's name.
test('an open rename follows the other tab, then conflicts and takes theirs', async ({
  page,
}) => {
  const userId = await signUpAdmin(page)
  const tabB = await page.context().newPage()
  await gotoPage(page, `/hilos/user/${userId}`)
  await gotoPage(tabB, `/hilos/user/${userId}`)
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(tabB.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-user-detail')).toBeVisible()
  await expect(tabB.getByTestId('hilos-user-detail')).toBeVisible()

  // Tab A opens the modal and does not type; tab B renames.
  await page.getByTestId('hilos-user-edit').click()
  await expect(page.getByTestId('hilos-user-name-input')).toBeVisible()
  await renameUser(tabB, 'E2E Elsewhere One')

  // A pristine modal follows the live row and says so on its message line.
  await expect(page.getByTestId('hilos-user-name-input')).toHaveValue(
    'E2E Elsewhere One',
  )
  await expect(page.getByTestId('hilos-user-edit-notice')).toContainText(
    'Updated just now',
  )
  await expect(page.getByTestId('conflict-badge')).toHaveCount(0)
  await expect(page.getByTestId('hilos-user-save')).toBeDisabled()

  // Tab A types; tab B renames again: a conflict, Save locked, no Merge.
  await typeInto(page.getByTestId('hilos-user-name-input'), 'E2E Mine')
  await renameUser(tabB, 'E2E Elsewhere Two')
  await expect(page.getByTestId('conflict-badge')).toBeVisible()
  await expect(page.getByTestId('hilos-user-edit-notice')).toContainText(
    'Changed elsewhere to "E2E Elsewhere Two"',
  )
  await expect(page.getByTestId('conflict-merge')).toHaveCount(0)
  await expect(page.getByTestId('hilos-user-save')).toBeDisabled()

  // Take theirs puts the other tab's name into the input; nothing is left to
  // save, so the modal closes without a question.
  await page.getByTestId('conflict-accept-theirs').click()
  await expect(page.getByTestId('hilos-user-name-input')).toHaveValue(
    'E2E Elsewhere Two',
  )
  await expect(page.getByTestId('conflict-badge')).toHaveCount(0)
  await expect(page.getByTestId('hilos-user-edit-notice')).toContainText(
    'Updated just now',
  )
  await expect(page.getByTestId('hilos-user-save')).toBeDisabled()
  await page.getByTestId('modal-close').click()
  await expect(page.getByTestId('hilos-user-name-input')).toHaveCount(0)
  await expect(page.getByTestId('hilos-user-name')).toHaveText(
    'E2E Elsewhere Two',
  )
  await tabB.close()
})

// HIL-1051: the chat admin's rename modal over the users table merges against
// the live row the same way. Tab A holds the modal open on the row of the user
// this test registers itself; tab B renames that user from the card. A pristine
// modal follows and says so, a typed one conflicts and locks Save, and Keep mine
// sends the draft over the other tab's name. The table orders by id, so the
// rename moves the row nowhere and the modal keeps its live row.
test('an open admin rename follows the other tab, then conflicts and keeps mine', async ({
  page,
}) => {
  const userId = await signUpAdmin(page)
  const tabB = await page.context().newPage()
  await gotoPage(page, '/hilos/app/users')
  await gotoPage(tabB, `/hilos/user/${userId}`)
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(tabB.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(tabB.getByTestId('hilos-user-detail')).toBeVisible()

  // The user registered a moment ago has the highest id, so its row is on the
  // last page of the window.
  await goToLastPage(page)
  await page.getByTestId(`admin-users-edit-${userId}`).click()
  await expect(page.getByTestId('admin-users-name')).toBeVisible()
  await expect(page.getByTestId('admin-users-save')).toBeDisabled()
  await renameUser(tabB, 'E2E Elsewhere One')

  // A pristine modal follows the live row and says so on its message line.
  await expect(page.getByTestId('admin-users-name')).toHaveValue(
    'E2E Elsewhere One',
  )
  await expect(page.getByTestId('admin-users-edit-notice')).toContainText(
    'Updated just now',
  )
  await expect(page.getByTestId('conflict-badge')).toHaveCount(0)
  await expect(page.getByTestId('admin-users-save')).toBeDisabled()

  // Tab A types; tab B renames again: a conflict, Save locked, no Merge.
  await typeInto(page.getByTestId('admin-users-name'), 'E2E Mine')
  await renameUser(tabB, 'E2E Elsewhere Two')
  await expect(page.getByTestId('conflict-badge')).toBeVisible()
  await expect(page.getByTestId('admin-users-edit-notice')).toContainText(
    'Changed elsewhere to "E2E Elsewhere Two"',
  )
  await expect(page.getByTestId('conflict-merge')).toHaveCount(0)
  await expect(page.getByTestId('admin-users-save')).toBeDisabled()

  // Keep mine ends the conflict with the draft in place: Save opens, sends it,
  // and the card in tab B shows the name tab A kept.
  await page.getByTestId('conflict-accept-mine').click()
  await expect(page.getByTestId('conflict-badge')).toHaveCount(0)
  await clickSubmit(page.getByTestId('admin-users-save'))
  await expect(page.getByTestId('admin-users-name')).toHaveCount(0)
  await expect(tabB.getByTestId('hilos-user-name')).toHaveText('E2E Mine')
  await tabB.close()
})

/**
 * Rename the user on the card through its modal, and settle when the modal
 * closes on the committed name.
 *
 * @param tab The tab on the user's card.
 * @param name The new display name.
 */
async function renameUser(tab: Page, name: string): Promise<void> {
  await tab.getByTestId('hilos-user-edit').click()
  await typeInto(tab.getByTestId('hilos-user-name-input'), name)
  await clickSubmit(tab.getByTestId('hilos-user-save'))
  await expect(tab.getByTestId('hilos-user-name-input')).toHaveCount(0)
  await expect(tab.getByTestId('hilos-user-name')).toHaveText(name)
}

test('shows the connected user as online with a live session', async ({
  page,
}) => {
  // Register the client and take its own id, then open its detail directly.
  // Robust against how many users the shared DB has accumulated: the self row
  // need not be on the first viewport page of /hilos/users.
  const userId = await signUpAdmin(page)

  await gotoPage(page, `/hilos/user/${userId}`)
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-user-detail')).toBeVisible()

  // Regression: a connected user must render online with at least one live
  // session. The detail table's computed presence fields were never populated
  // (the project browser context returned null), so the live user rendered as
  // offline with 0 sessions.
  await expect(page.getByTestId('hilos-user-sessions')).toHaveText(/[1-9]/)
  await expect(page.locator('[data-id="hilos-user-detail"] .badge')).toHaveText(
    'online',
  )
})

// HIL-327: the /hilos/users viewport table on volume. `test:user:seed` seeds 25
// deterministic `seed-###` users on stand bring-up, so window paging and server search
// run against more than one page — the case a single registered user can never reach.
test('windows, paginates, and searches the seeded users', async ({ page }) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/users')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()

  // The window holds exactly one page of rows regardless of how large the table is.
  const rows = page.locator('[data-id^="hilos-table-row-"]')
  await expect(rows).toHaveCount(10)

  // The count reflects the whole selection (>= 25 seeded + this test's own user), so it
  // is asserted by shape and lower bound, not an exact number on the shared database.
  const count = page.getByTestId('hilos-table-count')
  await expect(count).toHaveText(countOf(1, 10))
  const total = totalOf(await count.textContent())
  expect(total).toBeGreaterThanOrEqual(26)

  // An exact count draws the page numbers, and the page on screen is the one that
  // says so.
  const pageOne = page.getByTestId('hilos-table-page-1')
  const pageTwo = page.getByTestId('hilos-table-page-2')
  await expect(pageOne).toHaveAttribute('aria-current', 'page')

  // Next advances the window to a different set of rows; prev restores it. The range
  // and the number move the moment the control is pressed, so the rows are what says
  // the window arrived.
  const rowKeys = async () =>
    rows.evaluateAll((els) => els.map((el) => el.getAttribute('data-id')))
  const firstKeys = JSON.stringify(await rowKeys())
  await page.getByTestId('hilos-table-next').click()
  await expect(pageTwo).toHaveAttribute('aria-current', 'page')
  await expect(count).toHaveText(countOf(11, 20))
  await expect
    .poll(async () => JSON.stringify(await rowKeys()))
    .not.toBe(firstKeys)
  const secondKeys = JSON.stringify(await rowKeys())
  await page.getByTestId('hilos-table-prev').click()
  await expect(pageOne).toHaveAttribute('aria-current', 'page')
  await expect(count).toHaveText(countOf(1, 10))
  await expect
    .poll(async () => JSON.stringify(await rowKeys()))
    .not.toBe(secondKeys)

  // Server search filters the whole selection, not just the loaded window: the shared
  // `seed-` prefix matches the 25 deterministically seeded users, so the filtered total
  // is well over one page yet strictly below the unfiltered selection.
  //
  // It is asserted by shape, narrowing, and lower bound — never an exact number. On a
  // freshly brought-up shared stand the viewport count can settle one short as the burst
  // of window responses from typing lands over the socket (a stable "24 total" on some
  // runs, "25 total" on others), and the display can briefly stay at the unfiltered count
  // before the filter settles. Polling for a value below the unfiltered total waits out
  // that transient and proves the server actually narrowed the selection; the lower bound
  // then proves search reached the whole seeded fixture across pages. The exact-count
  // assertion was dropped by owner decision (HIL-327, 2026-08-02) as it raced 24/25.
  const search = page.getByTestId('hilos-table-search')
  await search.fill('')
  await search.pressSequentially('seed-', { delay: 10 })
  await expect(count).toHaveText(countOf(1, 10))
  await expect
    .poll(async () => totalOf(await count.textContent()), {
      timeout: 15000,
    })
    .toBeLessThan(total)
  const seededTotal = totalOf(await count.textContent())
  expect(seededTotal).toBeGreaterThanOrEqual(20)
  await expect(rows).toHaveCount(10)
})

// HIL-824: the takeover is a framework row action on the Hilos users page. Its name is
// closed by that page's ADMIN level and the sessions library performs the write, so what
// proves the whole two-hop route in one assertion is the banner: the shell draws it from
// the rebound session's own handshake, which cannot arrive unless the write landed.
//
// The refusal branch is not driven from here on purpose. Once the ADMIN level closes the
// page, the guards the library still runs are out of a browser's reach — a non-admin never
// gets the table, self-impersonation offers no button, and a nested takeover has no admin
// page left to press from. What a refusal looks like on the wire is pinned in
// demo/chat/tests/Integration/ImpersonationTest.php instead.
test('takes a user over from the users table and shows the shell banner', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/users')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()

  // Every row but the admin's own carries the control; the seeded users fill the rest.
  const impersonate = page
    .locator('[data-id^="hilos-users-impersonate-"]')
    .first()
  await expect(impersonate).toBeVisible()
  await impersonate.click()

  // A mutation is confirmed in a modal, and the confirm is what dispatches.
  const confirm = page.getByTestId('hilos-users-impersonate-confirm')
  await expect(confirm).toBeVisible()
  await confirm.click()

  // The takeover arrives as the rebound session, not as an ack the view acted on.
  await expect(page.getByTestId('impersonation-banner')).toBeVisible()
  await expect(page.getByTestId('impersonation-stop')).toBeVisible()
})
