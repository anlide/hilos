import { test, expect } from '@playwright/test'
import {
  armSocketDrop,
  dropSocket,
  ROWS,
  shownByTestId,
} from '../../../../../framework/frontend/e2e/index.js'

import { signUpAdmin } from '../helpers/adminGrant'
import { gotoPage, PAGE_READY } from '../helpers/page'
import { clearTableRefusal, refuseTableWindow } from '../helpers/tableRefusal'

// HIL-1131: a table whose window the server cannot build shows "List unavailable"
// in its body, and the rest of its page keeps working (HIL-943). A stand has no
// honest way to make a window fail, so the test-only lever test:table:refuse
// drops the build of one named table inside the build's own trap — the refusal
// takes the road a real failure takes, and each test walks one of its two roads:
// the page answer's `refusedWindows` section, and the table_window_refused frame
// that answers a changed window.
//
// The 400 ms the table keeps its old rows before the skeleton (HIL-943) is not
// measured here: on a starved stand the answer can take longer, and the threshold
// is pinned by the unit tests of the controller. Only the outcome is.

test.afterEach(clearTableRefusal)

test('a table refused on the way in shows it is unavailable while its neighbour keeps its rows, and the page re-sent after a reconnect brings them back', async ({
  page,
}) => {
  await armSocketDrop(page)
  let sockets = 0
  page.on('websocket', () => {
    sockets += 1
  })

  await signUpAdmin(page)
  // Before the page opens, so the refusal rides the page's own answer.
  await refuseTableWindow('hilosSecurityStepUp')
  await gotoPage(page, '/hilos/security/2fa', PAGE_READY)

  const operations = page.getByTestId('hilos-step-up-table')
  await expect(shownByTestId(operations, 'hilos-table-unavailable')).toBeVisible()
  await expect(
    shownByTestId(operations, 'hilos-table-unavailable-title'),
  ).toHaveText('List unavailable')
  await expect(shownByTestId(operations, /^hilos-step-up-row-/)).toHaveCount(0)

  // The neighbour came in the same answer with its rows, and the refusal is the
  // one tile on the page rather than an error over the whole of it.
  await expect(
    shownByTestId(page, 'hilos-2fa-value-auth.second_factor.required'),
  ).toBeVisible()
  await expect(shownByTestId(page, 'hilos-table-unavailable')).toHaveCount(1)
  await expect(page.getByTestId('page-error')).toHaveCount(0)

  // A reconnect re-sends the page, and its answer brings the window into the
  // very table that stands refused. The proof of the drop is the next socket.
  await clearTableRefusal()
  const socketsBeforeDrop = sockets
  await dropSocket(page)
  await expect.poll(() => sockets, { timeout: 15_000 }).toBeGreaterThan(
    socketsBeforeDrop,
  )
  await expect(page.getByTestId('conn-state')).toHaveText('connected', {
    timeout: 15_000,
  })

  await expect(
    shownByTestId(operations, 'hilos-step-up-row-change_name'),
  ).toBeVisible()
  await expect(shownByTestId(page, 'hilos-table-unavailable')).toHaveCount(0)
})

test('a window the server cannot build turns the rows into the unavailable tile, and the next window brings them back', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/settings', PAGE_READY)
  await expect(page.locator(ROWS).first()).toBeVisible()

  // With the rows on screen, so the refusal answers the changed window.
  await refuseTableWindow('settings')
  await page.getByTestId('hilos-table-sort-key').click()

  await expect(shownByTestId(page, 'hilos-table-unavailable')).toBeVisible()
  await expect(page.locator(ROWS)).toHaveCount(0)
  await expect(page.getByTestId('page-error')).toHaveCount(0)

  await clearTableRefusal()
  await page.getByTestId('hilos-table-sort-key').click()

  await expect(page.locator(ROWS).first()).toBeVisible()
  await expect(shownByTestId(page, 'hilos-table-unavailable')).toHaveCount(0)
})
