import { test, expect } from '@playwright/test'

// Hilos users admin e2e: /hilos/users renders the framework table (the first
// real table in the new frontend) over the live socket, the client's own
// self-registered row is present, search filters the client viewport, a row
// links to the user detail page, and a modal rename round-trips through the
// backend and re-renders with no document reload.

test('lists users in the framework table and opens a detail page', async ({
  page,
}) => {
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await page.goto('/hilos/users')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Users')
  await expect(page.getByTestId('hilos-table')).toBeVisible()
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
  await page.goto('/hilos/users')
  await expect(page.getByTestId('hilos-table')).toBeVisible()
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
  await page.goto('/hilos/users')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-table')).toBeVisible()

  // The connected client self-registers as the newest user (highest id), so its
  // own row sorts last under the id-ascending default; open that detail.
  const selfOpen = page.locator('[data-id^="hilos-users-open-"]').last()
  await expect(selfOpen).toBeVisible()
  await selfOpen.click()
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
