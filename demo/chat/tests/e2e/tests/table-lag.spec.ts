import { test, expect } from '@playwright/test'
import { shownByTestId } from '../../../../../framework/frontend/e2e/index.js'

import { signUpAdmin } from '../helpers/adminGrant'
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

/** A lag no test waits out: whatever it releases, the test released by taking it off. */
const HELD_FOR_GOOD_MS = 60_000

test.afterEach(clearTableLag)

test('a held window change draws the row skeleton, and the window it was waiting for takes it down', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/users')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()

  // The seeded users (test:user:seed on stand bring-up) fill more than one page.
  const rows = page.locator('[data-id^="hilos-table-row-"]')
  await expect(rows).toHaveCount(10)
  const rowKeys = async (): Promise<string> =>
    JSON.stringify(
      await rows.evaluateAll((els) => els.map((el) => el.getAttribute('data-id'))),
    )
  const firstKeys = await rowKeys()

  await setTableLag({ windowMs: HELD_FOR_GOOD_MS })
  await page.getByTestId('hilos-table-next').click()

  // The table draws the skeleton in both its branches; the one on screen is the
  // one the screen width chose.
  await expect(shownByTestId(page, 'hilos-table-loading')).toBeVisible()

  await clearTableLag()

  await expect(page.getByTestId('hilos-table-loading')).toHaveCount(0)
  await expect(page.getByTestId('hilos-table-count')).toHaveText(
    /^\s*11 – 20 of \d+\s*$/,
  )
  await expect(rows).toHaveCount(10)
  await expect.poll(rowKeys).not.toBe(firstKeys)
})

test('facet counts held back leave the options bare while the table stands, and arrive once released', async ({
  page,
}) => {
  // Before the page opens: the first counts follow the page's own answer, and
  // those are the ones held.
  await setTableLag({ facetsMs: HELD_FOR_GOOD_MS })

  await signUpAdmin(page)
  await gotoPage(page, '/hilos/backup')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()

  const scopeToggle = page
    .locator('[data-id="hilos-table-filter-scope"]')
    .locator('[data-id="hilos-dropdown-toggle"]')
  await scopeToggle.click()
  await expect(scopeToggle).toHaveAttribute('aria-expanded', 'true')

  // The window is here and the options are drawn, without a number beside any.
  await expect(page.locator('[data-id^="hilos-table-facet-scope-"]')).toHaveCount(0)

  await clearTableLag()

  await expect(page.getByTestId('hilos-table-facet-scope-any')).toBeVisible()
})
