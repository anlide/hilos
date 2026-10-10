import { test, expect } from '@playwright/test'
import {
  armSocketDrop,
  clearCustomSetting,
  dropSocket,
  ROWS,
  setCustomSetting,
  shownByTestId,
} from '../../../../../framework/frontend/e2e/index.js'

import { grantAdminToSelf } from '../helpers/adminGrant'
import { gotoPage, PAGE_READY } from '../helpers/page'
import { typeInto } from '../helpers/session'
import { clearTableRefusal, refuseTableWindow } from '../helpers/tableRefusal'

// HIL-1131: a table whose window the server cannot build shows "List unavailable"
// in its body, and the rest of its page keeps working (HIL-943). A stand has no
// honest way to make a window fail, so the test-only lever test:table:refuse
// drops the build of one named table inside the build's own trap — the refusal
// takes the road a real failure takes, and the first two tests walk its two roads:
// the page answer's `refusedWindows` section, and the table_window_refused frame
// that answers a changed window.
//
// The third test walks the road back. Rows return when the table's own source
// changes, and that change is made from a second tab. Lifting the lever sends
// nothing by itself. The second tab opens while the lever still holds: a new
// tab of the same session re-sends this page, and that resend has to be another
// refusal rather than the window. example_string is also written by
// settings.spec.ts; the binance suite runs in one worker
// (helpers/tableRefusal.ts), so the two files do not race on the key.
//
// The 400 ms the table keeps its old rows before the skeleton (HIL-943) is not
// measured here: on a starved stand the answer can take longer, and the threshold
// is pinned by the unit tests of the controller. Only the outcome is.
//
// The tests ride the settings list, a page with one table. The operations side
// has no page carrying two, so "one table refused, its neighbour open" is not
// walked here; that mixed answer is pinned by the core's unit test
// (framework/frontend/core/test/subscription/bindTableViewport.test.ts).

test.afterEach(clearTableRefusal)

test('a table refused on the way in shows it is unavailable while its page stands, and the page re-sent after a reconnect brings its rows back', async ({
  page,
}) => {
  await armSocketDrop(page)
  let sockets = 0
  page.on('websocket', () => {
    sockets += 1
  })

  await grantAdminToSelf(page)
  // Before the page opens, so the refusal rides the page's own answer.
  await refuseTableWindow('settings')
  await gotoPage(page, '/hilos/settings', PAGE_READY)

  // The refusal is a tile in the table's body rather than an error over the
  // whole page.
  await expect(shownByTestId(page, 'hilos-table-unavailable')).toBeVisible()
  await expect(shownByTestId(page, 'hilos-table-unavailable-title')).toHaveText(
    'List unavailable',
  )
  await expect(page.locator(ROWS)).toHaveCount(0)
  await expect(page.getByTestId('page-error')).toHaveCount(0)

  // A reconnect re-sends the page, and its answer brings the window into the
  // very table that stands refused. The proof of the drop is the next socket.
  await clearTableRefusal()
  const socketsBeforeDrop = sockets
  await dropSocket(page)
  await expect
    .poll(() => sockets, { timeout: 15_000 })
    .toBeGreaterThan(socketsBeforeDrop)
  await expect(page.getByTestId('conn-state')).toHaveText('connected', {
    timeout: 15_000,
  })

  await expect(page.locator(ROWS).first()).toBeVisible()
  await expect(shownByTestId(page, 'hilos-table-unavailable')).toHaveCount(0)
})

test('a window the server cannot build turns the rows into the unavailable tile, and the next window brings them back', async ({
  page,
}) => {
  await grantAdminToSelf(page)
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

test('a refused table gets its rows back on the first change to its own data, with nothing pressed and no reload', async ({
  page,
}) => {
  let sockets = 0
  let settingsAnswers = 0
  page.on('websocket', (socket) => {
    sockets += 1
    socket.on('framereceived', (frame) => {
      const payload = String(frame.payload)
      if (
        payload.includes('"type":"page_response"') &&
        payload.includes('"page":"hilos_settings"')
      ) {
        settingsAnswers += 1
      }
    })
  })
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await grantAdminToSelf(page)
  await refuseTableWindow('settings')
  await gotoPage(page, '/hilos/settings', PAGE_READY)

  await expect(shownByTestId(page, 'hilos-table-unavailable')).toBeVisible()
  await expect(page.locator(ROWS)).toHaveCount(0)
  const socketsAfterOpen = sockets
  const loadsAfterOpen = fullLoads
  const answersAfterOpen = settingsAnswers

  // A second tab of this session re-sends the page. It opens while the lever
  // still holds, so that resend is another refusal. Lifting the lever, and the
  // other tab's search, send nothing; the tile stays until the source is written.
  const tabB = await page.context().newPage()
  try {
    await gotoPage(tabB, '/hilos/settings')
    await expect.poll(() => settingsAnswers).toBeGreaterThan(answersAfterOpen)
    await expect(shownByTestId(page, 'hilos-table-unavailable')).toBeVisible()

    await clearTableRefusal()
    await typeInto(tabB.getByTestId('hilos-table-search'), 'example_string')
    await expect(
      tabB.getByTestId('hilos-table-row-example_string'),
    ).toBeVisible()

    // Instant: a retry would wait out a window that must not arrive.
    expect(
      await shownByTestId(page, 'hilos-table-unavailable').isVisible(),
    ).toBe(true)

    // The catalog default is empty, so a custom string is a real source write.
    await setCustomSetting(tabB, 'example_string', 'owed-back')

    await expect(page.locator(ROWS).first()).toBeVisible()
    await expect(shownByTestId(page, 'hilos-table-unavailable')).toHaveCount(0)
    expect(sockets).toBe(socketsAfterOpen)
    expect(fullLoads).toBe(loadsAfterOpen)
  } finally {
    await clearCustomSetting(tabB, 'example_string')
    await tabB.close()
  }
})
