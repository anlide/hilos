import { test, expect } from '@playwright/test'
import {
  ROWS,
  shownByTestId,
  TABLE,
} from '../../../../../framework/frontend/e2e/index.js'

import { grantAdminToSelf } from '../helpers/adminGrant'
import { gotoPage } from '../helpers/page'
import { clearTableLag, setTableLag } from '../helpers/tableLag'

// HIL-1020: the two states a slow table answer produces, made visible with the
// test-only table lag. On a stand every window arrives in tens of milliseconds, so
// the row skeleton (HIL-808) never gets its 400 ms and the counts beside the
// filter options (HIL-240) arrive together with the window. The lag holds each
// answer on the server until it is taken off, so neither test races a threshold:
// the state is looked at while held, and the release is what ends it.
//
// The fast case — a quick window draws no skeleton — is deliberately not here: it
// would depend on the live delay of the stand, and the threshold itself is pinned
// by the unit tests of the controller and of the view.
//
// The specs ride the framework's admin tables this demo carries, not a table of
// its own: the window changes on the settings list by a sort click, since the
// catalog fits one page and there is nowhere to page to, and the filter counts
// are read on the backup list, the one here whose filter declares them.

/** A lag no test waits out: whatever it releases, the test released by taking it off. */
const HELD_FOR_GOOD_MS = 60_000

test.afterEach(clearTableLag)

test('a held window change draws the row skeleton, and the window it was waiting for takes it down', async ({
  page,
}) => {
  await grantAdminToSelf(page)
  await gotoPage(page, '/hilos/settings')
  await expect(page.getByTestId(TABLE)).toBeVisible()

  // The count is read, not written down: the catalog grows with every leaf that
  // lands a setting of this demo. Two rows are the least a new order can show.
  const rows = page.locator(ROWS)
  await expect(rows.nth(1)).toBeVisible()
  const rowCount = await rows.count()
  const rowKeys = async (): Promise<string> =>
    JSON.stringify(
      await rows.evaluateAll((els) =>
        els.map((el) => el.getAttribute('data-id')),
      ),
    )
  const firstKeys = await rowKeys()

  await setTableLag({ windowMs: HELD_FOR_GOOD_MS })
  // The list opens by key ascending (HilosSettingsTable::defaultSort), and the
  // first click on that column turns it descending (TableViewportController
  // setSort), so the held window is another order of the same rows.
  await page.getByTestId('hilos-table-sort-key').click()

  // The table draws the skeleton in both its branches; the one on screen is the
  // one the screen width chose.
  await expect(shownByTestId(page, 'hilos-table-loading')).toBeVisible()

  await clearTableLag()

  await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
  await expect(rows).toHaveCount(rowCount)
  await expect.poll(rowKeys).not.toBe(firstKeys)
})

test('facet counts held back leave the options bare while the table stands, and arrive once released', async ({
  page,
}) => {
  // Before the page opens: the first counts follow the page's own answer, and
  // those are the ones held.
  await setTableLag({ facetsMs: HELD_FOR_GOOD_MS })

  await grantAdminToSelf(page)
  await gotoPage(page, '/hilos/backup')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()

  const scopeToggle = page
    .locator('[data-id="hilos-table-filter-scope"]')
    .locator('[data-id="hilos-dropdown-toggle"]')
  await scopeToggle.click()
  await expect(scopeToggle).toHaveAttribute('aria-expanded', 'true')

  // The window is here and the options are drawn, without a number beside any.
  await expect(
    page.locator('[data-id^="hilos-table-facet-scope-"]'),
  ).toHaveCount(0)

  await clearTableLag()

  await expect(page.getByTestId('hilos-table-facet-scope-any')).toBeVisible()
})
