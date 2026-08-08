import { test, expect } from '@playwright/test'

import { signUpAdmin } from '../helpers/adminGrant'

// Hilos users admin e2e: /hilos/users renders the framework table (the first
// real table in the new frontend) over the live socket, a registered user's row
// is present, search filters the client viewport, a row links to the user detail
// page, and a modal rename round-trips through the backend and re-renders with no
// document reload. Under session≠user a fresh context is anonymous, so each test
// registers a user to populate the table (the register itself creates the row).

test('lists users in the framework table and opens a detail page', async ({
  page,
}) => {
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await signUpAdmin(page)
  await page.goto('/hilos/users')
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
  await page.goto('/hilos/users')
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
  await page.goto('/hilos/users')
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

test('shows the connected user as online with a live session', async ({
  page,
}) => {
  // Register the client and take its own id, then open its detail directly.
  // Robust against how many users the shared DB has accumulated: the self row
  // need not be on the first viewport page of /hilos/users.
  const userId = await signUpAdmin(page)

  await page.goto(`/hilos/user/${userId}`)
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

// HIL-327: the /hilos/users viewport table on volume. `test:user:seed` seeds 25
// deterministic `seed-###` users on stand bring-up, so window paging and server search
// run against more than one page — the case a single registered user can never reach.
test('windows, paginates, and searches the seeded users', async ({ page }) => {
  await signUpAdmin(page)
  await page.goto('/hilos/users')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()

  // The window holds exactly one page of rows regardless of how large the table is.
  const rows = page.locator('[data-id^="hilos-table-row-"]')
  await expect(rows).toHaveCount(10)

  // The count reflects the whole selection (>= 25 seeded + this test's own user), so it
  // is asserted by shape and lower bound, not an exact number on the shared database.
  const count = page.getByTestId('hilos-table-count')
  await expect(count).toHaveText(/^\s*\d+ total\s*$/)
  const total = Number((await count.textContent())?.replace(/\D/g, ''))
  expect(total).toBeGreaterThanOrEqual(26)

  // The page indicator starts at page 1 of a multi-page set.
  const pageIndicator = page.getByTestId('hilos-table-page')
  await expect(pageIndicator).toHaveText(/^\s*1 \/ \d+\s*$/)

  // Next advances the window to a different set of rows; prev restores it.
  const rowKeys = async () =>
    rows.evaluateAll((els) => els.map((el) => el.getAttribute('data-id')))
  const firstKeys = JSON.stringify(await rowKeys())
  await page.getByTestId('hilos-table-next').click()
  await expect(pageIndicator).toHaveText(/^\s*2 \/ \d+\s*$/)
  await expect.poll(async () => JSON.stringify(await rowKeys())).not.toBe(firstKeys)
  await page.getByTestId('hilos-table-prev').click()
  await expect(pageIndicator).toHaveText(/^\s*1 \/ \d+\s*$/)

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
  await expect(count).toHaveText(/^\s*\d+ total\s*$/)
  await expect
    .poll(async () => Number((await count.textContent())?.replace(/\D/g, '')), {
      timeout: 15000,
    })
    .toBeLessThan(total)
  const seededTotal = Number((await count.textContent())?.replace(/\D/g, ''))
  expect(seededTotal).toBeGreaterThanOrEqual(20)
  await expect(rows).toHaveCount(10)
})
