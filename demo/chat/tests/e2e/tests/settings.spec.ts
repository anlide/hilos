import { test, expect, type Page } from '@playwright/test'

import { signUpAdmin } from '../helpers/adminGrant'
import { gotoPage } from '../helpers/page'

// Hilos settings admin e2e (server-windowed table): /hilos/settings renders the
// framework HilosViewportTable over the live socket. The window comes from the
// backend — search, sort, and paging change the viewport descriptor and the
// server replies a window — so a key is isolated with the search box before it
// is asserted on (the chat catalog spans four pages of ten). Live edits from
// another connection hang as pending (a tinted row + an Apply control), while the
// tab that made the edit applies its own change at once. Each editing test uses a
// distinct catalog key and resets it to the catalog default, so the suite stays
// idempotent and parallel-safe on the shared database.

/** Open the settings page and wait for the live table. */
async function openSettings(page: Page): Promise<void> {
  await gotoPage(page, '/hilos/settings')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
}

/** Narrow the server window to a single key so assertions ignore pagination. */
async function isolate(page: Page, key: string): Promise<void> {
  await page.getByTestId('hilos-table-search').fill(key)
  await expect(page.getByTestId(`hilos-table-row-${key}`)).toBeVisible()
}

test('lists settings in the server window and filters from the search box', async ({
  page,
}) => {
  await signUpAdmin(page)
  await openSettings(page)
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Settings')

  // A cataloged table has no free "add a setting" entry point.
  await expect(page.getByTestId('hilos-settings-add')).toHaveCount(0)

  // A key query narrows the window to its row; a query nothing matches empties
  // it (the loaded "no rows" state, distinct from the initial loading spinner);
  // clearing restores the window.
  const search = page.getByTestId('hilos-table-search')
  await search.fill('chat_bot_language')
  await expect(page.getByTestId('hilos-table-row-chat_bot_language')).toBeVisible()
  await expect(page.locator('[data-id^="hilos-table-row-"]')).toHaveCount(1)

  await search.fill('zzz-no-such-setting-zzz')
  await expect(page.locator('[data-id^="hilos-table-row-"]')).toHaveCount(0)
  await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)

  await search.fill('')
  await expect(
    page.locator('[data-id^="hilos-table-row-"]').first(),
  ).toBeVisible()
})

test('paginates the server window', async ({ page }) => {
  await signUpAdmin(page)
  await openSettings(page)

  // The catalog spans five pages of ten; the first page holds the chat_* keys,
  // the example_* keys sort onto the second next to the logs.* rotation and
  // retention keys, and the notifications.* keys (the email, push and sms
  // delivery channels plus the delivery-log retention setting) trail onto the
  // third, fourth and fifth.
  await expect(page.getByTestId('hilos-table-page')).toContainText('1 / 5')
  await expect(
    page.getByTestId('hilos-table-row-chat_attachment_max_file_bytes'),
  ).toBeVisible()
  await expect(page.getByTestId('hilos-table-row-example_string')).toHaveCount(0)

  await page.getByTestId('hilos-table-next').click()
  await expect(page.getByTestId('hilos-table-page')).toContainText('2 / 5')
  await expect(page.getByTestId('hilos-table-row-example_string')).toBeVisible()
  await expect(
    page.getByTestId('hilos-table-row-chat_attachment_max_file_bytes'),
  ).toHaveCount(0)

  await page.getByTestId('hilos-table-prev').click()
  await expect(page.getByTestId('hilos-table-page')).toContainText('1 / 5')
})

test('a third click on a sorted header returns the table to its initial order', async ({
  page,
}) => {
  await signUpAdmin(page)
  await openSettings(page)

  // The catalog opens sorted by Key ascending: that is the order the third
  // click has to hand back.
  const rows = page.locator('[data-id^="hilos-table-row-"]')
  const order = (): Promise<(string | null)[]> =>
    rows.evaluateAll((found) => found.map((row) => row.getAttribute('data-id')))
  await expect(rows.first()).toBeVisible()
  const initialOrder = await order()
  const keyHeader = page.locator('th:has([data-id="hilos-table-sort-key"])')
  const valueHeader = page.locator('th:has([data-id="hilos-table-sort-value"])')
  const sortByValue = page.getByTestId('hilos-table-sort-value')

  // aria-sort follows the click without waiting for anything, so the rows
  // themselves are what says the server window has landed: sorting by Value
  // rearranges the page.
  await sortByValue.click()
  await expect(valueHeader).toHaveAttribute('aria-sort', 'ascending')
  await expect.poll(order).not.toEqual(initialOrder)

  await sortByValue.click()
  await expect(valueHeader).toHaveAttribute('aria-sort', 'descending')

  // The third click reports no sort on Value, hands Key its opening direction
  // back, and the window arrives in the order the page opened with.
  await sortByValue.click()
  await expect(valueHeader).toHaveAttribute('aria-sort', 'none')
  await expect(keyHeader).toHaveAttribute('aria-sort', 'ascending')
  await expect.poll(order).toEqual(initialOrder)
})

test('a tab applies its own edit at once, with no pending gate', async ({
  page,
}) => {
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await signUpAdmin(page)
  await openSettings(page)
  const loadsAfterColdLoad = fullLoads
  await isolate(page, 'example_integer')
  const row = page.getByTestId('hilos-table-row-example_integer')
  await expect(row).toContainText('default')

  // Add-by-key: open the on-default row, switch on a custom value, and save it.
  await page.getByTestId('hilos-settings-edit-example_integer').click()
  await page.getByTestId('hilos-settings-edit-custom').check()
  await page.getByTestId('hilos-settings-edit-value').fill('42')
  await page.getByTestId('hilos-settings-edit-save').click()

  // The editing tab picks up its own change at once: the row updates and no
  // pending Apply control ever appears.
  await expect(row).toContainText('42')
  await expect(row).toContainText('custom')
  await expect(page.getByTestId('hilos-settings-edit-value')).toHaveCount(0)
  await expect(page.getByTestId('hilos-table-apply')).toHaveCount(0)

  // Reset back to the catalog default.
  await page.getByTestId('hilos-settings-edit-example_integer').click()
  await page.getByTestId('hilos-settings-edit-custom').uncheck()
  await page.getByTestId('hilos-settings-edit-save').click()
  await expect(row).toContainText('default')
  await expect(row).not.toContainText('custom')

  // All of it happened over the live socket — no document reload.
  expect(fullLoads).toBe(loadsAfterColdLoad)
})

test('an edit in one tab hangs as pending in another until applied', async ({
  page,
}) => {
  await signUpAdmin(page)
  const tabB = await page.context().newPage()
  await openSettings(page)
  await openSettings(tabB)
  await isolate(page, 'chat_bot_language')
  await isolate(tabB, 'chat_bot_language')

  const rowA = page.getByTestId('hilos-table-row-chat_bot_language')
  const rowB = tabB.getByTestId('hilos-table-row-chat_bot_language')

  // Tab A sets a custom value and applies its own change at once (no Apply).
  await page.getByTestId('hilos-settings-edit-chat_bot_language').click()
  await page.getByTestId('hilos-settings-edit-custom').check()
  await page.getByTestId('hilos-settings-edit-value').fill('xx-test')
  await page.getByTestId('hilos-settings-edit-save').click()
  await expect(rowA).toContainText('xx-test')
  await expect(page.getByTestId('hilos-table-apply')).toHaveCount(0)

  // Tab B receives the change as pending: a tinted row and an Apply control,
  // while the row still shows the old value until applied.
  await expect(tabB.getByTestId('hilos-table-apply')).toBeVisible()
  await expect(rowB).toHaveClass(/table-warning/)
  await expect(rowB).not.toContainText('xx-test')

  // Applying resolves the pending change in place.
  await tabB.getByTestId('hilos-table-apply').click()
  await expect(rowB).toContainText('xx-test')
  await expect(tabB.getByTestId('hilos-table-apply')).toHaveCount(0)
  await expect(rowB).not.toHaveClass(/table-warning/)

  // Reset the key back to its catalog default.
  await page.getByTestId('hilos-settings-edit-chat_bot_language').click()
  await page.getByTestId('hilos-settings-edit-custom').uncheck()
  await page.getByTestId('hilos-settings-edit-save').click()
  await expect(rowA).not.toContainText('xx-test')
  await tabB.close()
})

test('opening the edit dialog applies the pending change first', async ({
  page,
}) => {
  await signUpAdmin(page)
  const tabB = await page.context().newPage()
  await openSettings(page)
  await openSettings(tabB)
  await isolate(page, 'example_string')
  await isolate(tabB, 'example_string')

  // Tab A sets a custom value; tab B sees it as pending.
  await page.getByTestId('hilos-settings-edit-example_string').click()
  await page.getByTestId('hilos-settings-edit-custom').check()
  await page.getByTestId('hilos-settings-edit-value').fill('hello-modal')
  await page.getByTestId('hilos-settings-edit-save').click()
  await expect(tabB.getByTestId('hilos-table-apply')).toBeVisible()

  // Opening tab B's edit dialog flushes the pending change first, so the dialog
  // edits the latest committed value and the pending Apply control is gone.
  await tabB.getByTestId('hilos-settings-edit-example_string').click()
  await expect(tabB.getByTestId('hilos-settings-edit-value')).toHaveValue(
    'hello-modal',
  )
  await expect(tabB.getByTestId('hilos-table-apply')).toHaveCount(0)

  // Reset the key back to its catalog default.
  await page.getByTestId('hilos-settings-edit-example_string').click()
  await page.getByTestId('hilos-settings-edit-custom').uncheck()
  await page.getByTestId('hilos-settings-edit-save').click()
  await tabB.close()
})

test('deletes an orphan setting through the confirm modal', async ({ page }) => {
  // An uncataloged (orphan) row seeded before the app came up by the composer
  // `test:e2e-seed-orphan` step (cli `test:orphan:create e2e_orphan_delete ...`);
  // keep this key in sync with that step. The full e2e run always db-resets, so
  // this test owns the row and needs no cleanup.
  const orphanKey = 'e2e_orphan_delete'

  await signUpAdmin(page)
  await openSettings(page)
  await isolate(page, orphanKey)

  // The delete affordance is orphan-only: a catalog key never exposes it, this
  // uncataloged row does.
  const deleteButton = page.getByTestId(`hilos-settings-delete-${orphanKey}`)
  await expect(deleteButton).toBeVisible()

  // Confirm-modal delete removes the DB row. The initiating tab applies its own
  // change at once (no pending Apply gate); a removed row collapses in place to a
  // "Removed" placeholder rather than pulling the layout up, and its delete
  // affordance is gone with the row slot.
  await deleteButton.click()
  await page.getByTestId('hilos-settings-delete-confirm').click()
  const row = page.getByTestId(`hilos-table-row-${orphanKey}`)
  await expect(row.getByTestId('hilos-table-placeholder')).toBeVisible()
  await expect(page.getByTestId('hilos-table-apply')).toHaveCount(0)
  await expect(page.getByTestId(`hilos-settings-delete-${orphanKey}`)).toHaveCount(0)
})
