import { test, expect, type Page } from '@playwright/test'

import { dismissToasts } from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant'
import { gotoPage } from '../helpers/page'

// Moderation admin e2e: /hilos/app/moderator renders the prompt-pieces table
// over the live socket, and the create / edit / delete dialogs round-trip through
// the backend (AdminModeratorPage), the row appearing, updating, and leaving the
// live table with no document reload. Prompt text is stamped unique so a retry
// (which reuses the same database) never collides with a leftover row.
//
// The page is an ADMIN-level surface (HIL-652), so every test takes the grant
// first — the route's `admin: true` marker is shell cosmetics and opens nothing.
// A second tab of the same browser context inherits the session cookie, so it
// signs in once per test and not once per tab.

/** Open the moderation admin and wait for the live table; the caller is admin already. */
async function openModerator(page: Page): Promise<void> {
  await gotoPage(page, '/hilos/app/moderator')
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

  await signUpAdmin(page)

  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await gotoPage(page, '/hilos/app/moderator')
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
  // The save raised a notice over the bottom-right corner, where this table's
  // own controls are. What it says is the toast specs' business; here it is only
  // in the way, so it goes before the next row control is aimed at.
  await dismissToasts(page)

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
  await dismissToasts(page)

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
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/app/moderator')
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
  expect(new URL(page.url()).pathname).toBe('/hilos/app/moderator')
})

test('an edit in one tab lands at once in another, raising no Apply', async ({
  page,
}) => {
  const stamp = Date.now()
  const text = `E2E pending ${stamp}`
  const edited = `E2E pending edited ${stamp}`

  // Tab A creates a piece, then tab B opens and finds it in the cold snapshot.
  await signUpAdmin(page)
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

  // Tab B receives the edit from the other connection over the source-fanout table
  // and shows it at once: the window is ordered by id, so the edit moved nothing,
  // and the gate holds position and membership rather than the fields of a record
  // (HIL-793). A piece is an entity reference, so the cell text tracks the edit
  // reactively; what is new is that no Apply control stands behind it and the row
  // is not tinted. This is the source-fanout row-updated delta path settings
  // cannot cover.
  await expect(tabB.locator('tbody tr', { hasText: edited })).toHaveCount(1)
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
