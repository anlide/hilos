import { test, expect } from '@playwright/test'

// Hilos settings admin e2e for the todo demo: activating the framework settings
// feature configure-only (a catalog + a thin page + a project BrowserContext)
// makes /hilos/settings render the framework settings table over the live socket.
// Catalog placeholder rows show without any DB override, search filters the client
// viewport, and a custom value is set on a catalog key from its own row
// (add-by-key) then reset — both round-trip through the backend and re-render with
// no document reload. The settings table is cataloged, so there is no free "add a
// setting" control (data-model.md, "Cataloged tables"); the only mutations live on
// the row. example_integer is left back on its catalog default at the end so the
// test is idempotent across runs on the shared database.

test('lists settings in the framework table and filters from the search box', async ({
  page,
}) => {
  await page.goto('/hilos/settings')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Settings')
  await expect(page.getByTestId('hilos-table')).toBeVisible()

  // A cataloged table has no free "add a setting" entry point.
  await expect(page.getByTestId('hilos-settings-add')).toHaveCount(0)

  // Catalog placeholder rows are present without any DB override.
  await expect(page.getByTestId('hilos-table-row-example_string')).toBeVisible()
  await expect(page.getByTestId('hilos-table-row-example_boolean')).toBeVisible()

  // A query no key matches empties the viewport; a key query narrows it; clearing
  // restores every row.
  const search = page.getByTestId('hilos-table-search')
  await search.fill('zzz-no-such-setting-zzz')
  await expect(page.getByTestId('hilos-table-row-example_string')).toHaveCount(0)
  await search.fill('example_boolean')
  await expect(page.getByTestId('hilos-table-row-example_boolean')).toBeVisible()
  await expect(page.getByTestId('hilos-table-row-example_string')).toHaveCount(0)
  await search.fill('')
  await expect(page.getByTestId('hilos-table-row-example_string')).toBeVisible()
})

test('sets a custom value on a catalog key from its row and resets it, live', async ({
  page,
}) => {
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await page.goto('/hilos/settings')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-table')).toBeVisible()
  const loadsAfterColdLoad = fullLoads

  const integerRow = page.getByTestId('hilos-table-row-example_integer')
  await expect(integerRow).toContainText('default')

  // Add-by-key: open the on-default row, switch on a custom value, and save it.
  await page.getByTestId('hilos-settings-edit-example_integer').click()
  await page.getByTestId('hilos-settings-edit-custom').check()
  await page.getByTestId('hilos-settings-edit-value').fill('42')
  await page.getByTestId('hilos-settings-edit-save').click()

  // The custom value returns over the live table and the edit dialog closes.
  await expect(integerRow).toContainText('42')
  await expect(integerRow).toContainText('custom')
  await expect(page.getByTestId('hilos-settings-edit-value')).toHaveCount(0)

  // Reset back to the catalog default via the edit dialog's custom toggle.
  await page.getByTestId('hilos-settings-edit-example_integer').click()
  await page.getByTestId('hilos-settings-edit-custom').uncheck()
  await page.getByTestId('hilos-settings-edit-save').click()
  await expect(integerRow).toContainText('default')
  await expect(integerRow).not.toContainText('custom')

  // All of it happened over the live socket — no document reload.
  expect(fullLoads).toBe(loadsAfterColdLoad)
})
