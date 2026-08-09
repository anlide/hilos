import { test, expect, type Page } from '@playwright/test'

import { signUpAdmin } from '../helpers/adminGrant'
import { gotoPage } from '../helpers/page'

// Moderation admin e2e: /hilos/admin_moderator renders the prompt-pieces table
// over the live socket, and the create / edit / delete dialogs round-trip through
// the backend (AdminModeratorPage), the row appearing, updating, and leaving the
// live table with no document reload. Prompt text is stamped unique so a retry
// (which reuses the same database) never collides with a leftover row.

/** Open the moderation admin and wait for the live table. */
async function openModerator(page: Page): Promise<void> {
  await gotoPage(page, '/hilos/admin_moderator')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('admin-moderator-view')).toBeVisible()
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
}

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

  await gotoPage(page, '/hilos/admin_moderator')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('admin-moderator-view')).toBeVisible()
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
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
  // The authoritative-backend dialog closes on the action's ::success reply.
  await expect(page.getByTestId('admin-moderator-save')).toHaveCount(0)

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

test('saving an edit with no changes still closes the dialog', async ({
  page,
}) => {
  await gotoPage(page, '/hilos/admin_moderator')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()

  // Open a seed row and save without changing anything. The row never re-emits
  // (a no-op write produces no DB sync), so the old state-driven close hung
  // here forever; the action's ::success reply still arrives and closes it.
  await page
    .locator('tbody tr')
    .first()
    .getByRole('button', { name: 'Edit' })
    .click()
  await page.getByTestId('admin-moderator-save').click()
  await expect(page.getByTestId('admin-moderator-save')).toHaveCount(0)
})

test('reaches the moderation admin from the dashboard', async ({ page }) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')

  await page.getByTestId('dashboard-card-admin_moderator').click()
  await expect(page.getByTestId('admin-moderator-view')).toBeVisible()
  expect(new URL(page.url()).pathname).toBe('/hilos/admin_moderator')
})

test('an edit in one tab hangs as pending in another until applied', async ({
  page,
}) => {
  const stamp = Date.now()
  const text = `E2E pending ${stamp}`
  const edited = `E2E pending edited ${stamp}`

  // Tab A creates a piece, then tab B opens and finds it in the cold snapshot.
  await openModerator(page)
  await page.getByTestId('admin-moderator-add').click()
  await page.getByTestId('admin-moderator-section').selectOption('name_rule')
  await page.getByTestId('admin-moderator-prompt').fill(text)
  await page.getByTestId('admin-moderator-save').click()
  await expect(page.locator('tbody tr', { hasText: text })).toHaveCount(1)

  const tabB = await page.context().newPage()
  await openModerator(tabB)
  await expect(tabB.locator('tbody tr', { hasText: text })).toHaveCount(1)

  // Tab A edits the piece and applies its own change at once (no Apply gate).
  await page
    .locator('tbody tr', { hasText: text })
    .getByRole('button', { name: 'Edit' })
    .click()
  await page.getByTestId('admin-moderator-prompt').fill(edited)
  await page.getByTestId('admin-moderator-save').click()
  await expect(page.locator('tbody tr', { hasText: edited })).toHaveCount(1)
  await expect(page.getByTestId('hilos-table-apply')).toHaveCount(0)

  // Tab B receives the edit from the other connection as a pending update over
  // the source-fanout table: an Apply control appears and the row is tinted. A
  // piece is an entity reference, so the cell text tracks the edit reactively
  // (unlike settings, whose inline value holds old until applied) — but the
  // pending gate still holds: the row keeps its place and waits for an explicit
  // Apply. This is the source-fanout row-updated delta path settings can't cover.
  await expect(tabB.getByTestId('hilos-table-apply')).toBeVisible()
  await expect(tabB.locator('tbody tr', { hasText: edited })).toHaveClass(
    /table-warning/,
  )

  // Applying clears the pending gate in place, with no document reload.
  await tabB.getByTestId('hilos-table-apply').click()
  await expect(tabB.getByTestId('hilos-table-apply')).toHaveCount(0)
  await expect(tabB.locator('tbody tr', { hasText: edited })).not.toHaveClass(
    /table-warning/,
  )

  // Cleanup: delete the piece so the suite stays idempotent on the shared DB.
  await tabB
    .locator('tbody tr', { hasText: edited })
    .getByRole('button', { name: 'Delete' })
    .click()
  await tabB.getByTestId('admin-moderator-delete-confirm').click()
  await expect(tabB.locator('tbody tr', { hasText: edited })).toHaveCount(0)
  await tabB.close()
})
