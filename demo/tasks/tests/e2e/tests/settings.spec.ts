import { test, expect } from '@playwright/test'

import {
  clearCustomSetting,
  setCustomSetting,
  shownByTestId,
  sidewaysOverflow,
} from '../../../../../framework/frontend/e2e/index.js'
import { grantAdminToSelf } from '../helpers/adminGrant'
import { gotoPage } from '../helpers/page'
import { typeInto } from '../helpers/session'

// Hilos settings admin e2e for the tasks demo: activating the framework settings
// feature configure-only (a catalog + a thin page + a project BrowserContext)
// makes /hilos/settings render the framework settings table over the live socket.
// The table is a declared one, so it stands in the document twice — rows for a wide
// screen, cards for a narrow one.
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
  await grantAdminToSelf(page)

  await gotoPage(page, '/hilos/settings')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Settings')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()

  // A cataloged table has no free "add a setting" entry point.
  await expect(page.getByTestId('hilos-settings-add')).toHaveCount(0)

  const search = page.getByTestId('hilos-table-search')

  // Catalog placeholder rows are present without any DB override. Isolate the
  // family first: adding another catalog key may move it off the first page.
  await typeInto(search, 'example_')
  await expect(page.getByTestId('hilos-table-row-example_string')).toBeVisible()
  await expect(
    page.getByTestId('hilos-table-row-example_boolean'),
  ).toBeVisible()

  // A query no key matches empties the viewport and the table says so, naming the
  // query and offering the reset that brings the rows back; a key query narrows it;
  // clearing restores every row.
  await typeInto(search, 'zzz-no-such-setting-zzz')
  await expect(page.getByTestId('hilos-table-row-example_string')).toHaveCount(
    0,
  )
  await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
  // The words stand in both branches of the table, so they are read off the copy
  // on screen; that they are gone is asserted of both.
  const noMatches = page.getByTestId('hilos-table-no-matches')
  await expect(shownByTestId(page, 'hilos-table-no-matches')).toBeVisible()
  await expect(
    shownByTestId(page, 'hilos-table-no-matches-terms'),
  ).toContainText('“zzz-no-such-setting-zzz”')
  await shownByTestId(page, 'hilos-table-no-matches-reset').click()
  await expect(
    page.locator('[data-id^="hilos-table-row-"]').first(),
  ).toBeVisible()
  await expect(noMatches).toHaveCount(0)
  await expect(search).toHaveValue('')
  await typeInto(search, 'example_boolean')
  await expect(
    page.getByTestId('hilos-table-row-example_boolean'),
  ).toBeVisible()
  await expect(page.getByTestId('hilos-table-row-example_string')).toHaveCount(
    0,
  )
  await search.fill('')
  await expect(
    page.locator('[data-id^="hilos-table-row-"]').first(),
  ).toBeVisible()
})

test('sets a custom value on a catalog key from its row and resets it, live', async ({
  page,
}) => {
  await grantAdminToSelf(page)

  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await gotoPage(page, '/hilos/settings')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  const loadsAfterColdLoad = fullLoads

  const integerRow = page.getByTestId('hilos-table-row-example_integer')
  await expect(integerRow).toContainText('default')

  // Add-by-key: open the on-default row, switch on a custom value, and save it.
  await setCustomSetting(page, 'example_integer', '42')

  // The custom value returns over the live table and the edit dialog closes.
  await expect(integerRow).toContainText('42')
  await expect(integerRow).toContainText('custom')
  await expect(page.getByTestId('hilos-settings-edit-value')).toHaveCount(0)

  // Reset back to the catalog default via the edit dialog's custom toggle.
  await clearCustomSetting(page, 'example_integer')
  await expect(integerRow).toContainText('default')
  await expect(integerRow).not.toContainText('custom')

  // All of it happened over the live socket — no document reload.
  expect(fullLoads).toBe(loadsAfterColdLoad)
})

test('a narrow window draws the settings as cards and never scrolls sideways', async ({
  page,
}) => {
  // HIL-815 acceptance, the React twin of the chat case (HIL-806). A
  // declared table is a table on a wide screen and a list of cards on a narrow
  // one; both are mounted, and Bootstrap's display utilities show exactly one of
  // them. What a phone must never get is the wide table squeezed into a sideways
  // scroll — the thing the cards exist to replace.
  //
  // The grant runs at the usual width: it is not what is under test. The page
  // itself is opened narrow, the way a phone opens it.
  await grantAdminToSelf(page)
  const desktop = page.viewportSize() ?? { width: 1280, height: 720 }
  await page.setViewportSize({ width: 375, height: desktop.height })
  await gotoPage(page, '/hilos/settings')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()

  // Isolate one catalog key before judging its responsive branches: catalog
  // additions may move the key to another page, but must not change its card.
  const key = 'example_string'
  await typeInto(page.getByTestId('hilos-table-search'), key)
  const cards = page.getByTestId('hilos-table-cards')
  const card = cards.getByTestId(`hilos-table-card-${key}`)
  const row = page.getByTestId(`hilos-table-row-${key}`)

  await expect(card).toBeVisible()
  await expect(row).toBeHidden()
  expect(await sidewaysOverflow(page)).toEqual([0, 0])

  // Back on a wide screen it is the table again, and the cards are gone from sight:
  // both branches were drawn from the same window, so the row is there to show.
  await page.setViewportSize(desktop)
  await expect(row).toBeVisible()
  await expect(cards).toBeHidden()
})
