import { test, expect } from '@playwright/test'

// Bots admin e2e: /hilos/admin_bots renders the bots table over the live socket,
// and the create / edit / delete dialogs round-trip through the backend
// (AdminBotsPage), the row appearing, updating, and leaving the live table with no
// document reload. Bot names are stamped unique so a retry (which reuses the same
// database) never collides with an earlier attempt's leftover row.

test('creates, edits, and deletes a bot through the live table', async ({
  page,
}) => {
  const stamp = Date.now()
  const name = `E2E Created ${stamp}`
  const renamed = `E2E Renamed ${stamp}`

  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await page.goto('/hilos/admin_bots')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('admin-bots-view')).toBeVisible()
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  const loadsAfterColdLoad = fullLoads

  // Create: exactly one row appears once the backend echoes it — the DB bot and
  // its runtime status fold into a single row, not two — and the dialog closes.
  await page.getByTestId('admin-bots-add').click()
  await page.getByTestId('admin-bots-name').fill(name)
  await page.getByTestId('admin-bots-description').fill('made by e2e')
  await page.getByTestId('admin-bots-save').click()
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
  await page
    .locator('tbody tr', { hasText: renamed })
    .getByRole('button', { name: 'Delete' })
    .click()
  await page.getByTestId('admin-bots-delete-confirm').click()
  await expect(page.locator('tbody tr', { hasText: renamed })).toHaveCount(0)

  // The whole CRUD tour stayed in one live document.
  expect(fullLoads).toBe(loadsAfterColdLoad)
})

test('reaches the bots admin from the dashboard', async ({ page }) => {
  await page.goto('/hilos')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')

  await page.getByTestId('dashboard-card-admin_bots').click()
  await expect(page.getByTestId('admin-bots-view')).toBeVisible()
  expect(new URL(page.url()).pathname).toBe('/hilos/admin_bots')
})
