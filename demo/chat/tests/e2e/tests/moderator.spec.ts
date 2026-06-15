import { test, expect } from '@playwright/test'

// Moderation admin e2e: /hilos/admin_moderator renders the prompt-pieces table
// over the live socket, and the create / edit / delete dialogs round-trip through
// the backend (AdminModeratorPage), the row appearing, updating, and leaving the
// live table with no document reload. Prompt text is stamped unique so a retry
// (which reuses the same database) never collides with a leftover row.

test('creates, edits, and deletes a prompt piece through the live table', async ({
  page,
}) => {
  const stamp = Date.now()
  const text = `E2E piece ${stamp}`
  const edited = `E2E edited ${stamp}`

  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await page.goto('/hilos/admin_moderator')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('admin-moderator-view')).toBeVisible()
  await expect(page.getByTestId('hilos-table')).toBeVisible()
  const loadsAfterColdLoad = fullLoads

  // Create: exactly one row appears once the backend echoes it, dialog closes.
  await page.getByTestId('admin-moderator-add').click()
  await page.getByTestId('admin-moderator-section').selectOption('name_rule')
  await page.getByTestId('admin-moderator-prompt').fill(text)
  await page.getByTestId('admin-moderator-save').click()
  await expect(page.locator('tbody tr', { hasText: text })).toHaveCount(1)
  await expect(page.getByTestId('admin-moderator-save')).toHaveCount(0)

  // Edit: change the prompt; the live row re-renders and the dialog closes.
  await page
    .locator('tbody tr', { hasText: text })
    .getByRole('button', { name: 'Edit' })
    .click()
  await page.getByTestId('admin-moderator-prompt').fill(edited)
  await page.getByTestId('admin-moderator-save').click()
  await expect(page.locator('tbody tr', { hasText: edited })).toHaveCount(1)
  await expect(page.locator('tbody tr', { hasText: text })).toHaveCount(0)

  // Delete: the row leaves the live table.
  await page
    .locator('tbody tr', { hasText: edited })
    .getByRole('button', { name: 'Delete' })
    .click()
  await page.getByTestId('admin-moderator-delete-confirm').click()
  await expect(page.locator('tbody tr', { hasText: edited })).toHaveCount(0)

  // The whole CRUD tour stayed in one live document.
  expect(fullLoads).toBe(loadsAfterColdLoad)
})

test('reaches the moderation admin from the dashboard', async ({ page }) => {
  await page.goto('/hilos')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')

  await page.getByTestId('dashboard-card-admin_moderator').click()
  await expect(page.getByTestId('admin-moderator-view')).toBeVisible()
  expect(new URL(page.url()).pathname).toBe('/hilos/admin_moderator')
})
