import { test, expect, type Page } from '@playwright/test'

import {
  PASSWORD,
  signUp,
  advanceTableToRow,
  controlSocketReconnect,
  countSentFrames,
  deliveryObject as object,
  dismissToasts,
  expectHiddenUserTimeZones,
  expectHilosUserBlockSettled,
  expectHilosUserOverview,
  expectHilosUserOverviewAccessibility,
  expectSessionRotation,
  expectTableRowOrder,
  expectUserActivityDisplay,
  expectUserActivityMoment,
  expectUserTimeZone,
  forwardSessionTimeZone,
  isRotateCookie,
  ROWS,
  tableRowKeyByText,
  userTableRows,
  watchClientDocument,
  watchDeliveryFrames,
  watchFirstRowTop,
  watchHeight,
  watchSocketFrames,
  type SocketFrame,
  clickSubmit,
  typeInto,
  gotoPage,
  PAGE_READY,
  grantAdminToSelf,
  sessionToken,
  signUpAdmin,
} from '../../../../../framework/frontend/e2e/index.js'
import { setAdmin } from '../../../../../framework/frontend/scripts/adminGrant.mjs'
import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'

// A fixture owns the second context's teardown, so closing it after a timeout
// cannot replace the test's original failure or prevent the afterEach mode reset.
const viewerTest = test.extend<{ viewerPage: Page }>({
  viewerPage: async (
    { browser, baseURL, ignoreHTTPSErrors, locale, timezoneId },
    use,
  ) => {
    const context = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors,
      locale,
      timezoneId,
    })
    await use(await context.newPage())
    await context.close()
  },
})

// Hilos users admin e2e: /hilos/users renders the framework table (the first
// real table in the new frontend) over the live socket, a registered user's row
// is present, search filters the client viewport, a row links to the user detail
// page, and a modal rename round-trips through the backend and re-renders with no
// document reload. Under session≠user a fresh context is anonymous, so each test
// registers a user to populate the table (the register itself creates the row).

/**
 * The count the footer shows reads `${first} – ${last} of ${total}`. The pattern
 * pins the range on screen and takes any total: the shared database grows as the
 * suite runs, so a total is asserted by bounds rather than by value. This table
 * filters in memory, so its count is exact and never carries the ceiling's `+`.
 *
 * @param first 1-based number of the first row on screen.
 * @param last 1-based number of the last row on screen.
 * @returns The pattern the count's text matches.
 */
function countOf(first: number, last: number): RegExp {
  return new RegExp(`^\\s*${first} – ${last} of \\d+\\s*$`)
}

/**
 * Read the size of the set off the footer's count.
 *
 * @param text The count's text content.
 * @returns The total after "of", or NaN when the text carries none.
 */
function totalOf(text: string | null): number {
  return Number(/ of (\d+)/.exec(text ?? '')?.[1] ?? Number.NaN)
}

test('lists users in the framework table and opens a detail page', async ({
  page,
}) => {
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await signUpAdmin(expect, page)
  await gotoPage(expect, page, '/hilos/users')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Users')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  const loadsAfterColdLoad = fullLoads

  // A user is registered, so at least its own row is present.
  const firstOpen = page.locator('[data-id^="hilos-users-open-"]').first()
  await expect(firstOpen).toBeVisible()

  // Row -> the user detail page, over the live socket (no document reload).
  await firstOpen.click()
  await expect(page).toHaveURL(/\/hilos\/user\/\d+$/)
  await expect(page.getByTestId('hilos-user-detail')).toBeVisible()
  await expect(page.getByTestId('hilos-page-head-title')).toBeVisible()
  expect(fullLoads).toBe(loadsAfterColdLoad)
})

test('user Overview has no empty project section and supports keyboard rename', async ({
  page,
}) => {
  const userId = await signUpAdmin(expect, page)
  const name = await page.getByTestId('nav-profile-name').getAttribute('title')
  expect(name).toBeTruthy()
  await gotoPage(expect, page, `/hilos/user/${userId}`)
  await expectHilosUserOverview(expect, page, userId, name!, 'BTC Tracker')
  // With no project slot, only the framework sections exist; no empty panel or
  // project heading is rendered beside them.
  await expect(page.getByRole('heading', { level: 2 })).toHaveText([
    'Profile',
    'Rights',
    'Access to the product',
    'Acting as another person',
  ])
  for (const [key, state] of [
    ['admin', 'Administrator'],
    ['block', 'Not blocked'],
    ['frozen', 'Not frozen'],
    ['deletion', 'No deletion is scheduled'],
  ]) {
    await expect(
      page.getByTestId(`hilos-user-${key}-state`).locator('p.fw-medium'),
    ).toHaveText(state!)
  }
  const connections = page.getByTestId('hilos-user-sessions')
  await expect(connections).toHaveText(/^[1-9]\d*$/)
  await expect(connections.locator('..')).toContainText('Open connections')
  await expectHilosUserOverviewAccessibility(expect, page)
})

test.describe('people time zones and numeric activity', () => {
  test.use({ locale: 'en-GB', timezoneId: 'Asia/Tokyo' })
  test.afterEach(() => setAdminViewMode(false))

  const chosenName = 'timezone-chosen-001'
  const chosenZone = 'Pacific/Honolulu'
  const ownZone = 'Europe/Kyiv'
  const browserZone = 'Asia/Tokyo'

  test('keeps the saved Profile zone while list and card follow the reader on the same sockets', async ({
    page,
  }) => {
    const listSocket = await forwardSessionTimeZone(page, ownZone)
    const listPageRequests = countSentFrames(page, '"type":"page_')
    const listTableRequests = countSentFrames(page, '"type":"table_')
    const card = await page.context().newPage()
    const cardSocket = await forwardSessionTimeZone(card, ownZone)
    const cardPageRequests = countSentFrames(card, '"type":"page_')
    const cardTableRequests = countSentFrames(card, '"type":"table_')
    try {
      const readerId = await signUpAdmin(expect, page)
      await gotoPage(expect, page, `/hilos/user/${readerId}`)
      await expectUserTimeZone(expect, page, null)
      expect(userTableRows(listSocket.frames).at(-1)?.user.timeZone).toBeNull()

      await gotoPage(expect, page, '/hilos/users')
      await typeInto(page.getByTestId('hilos-table-search'), chosenName)
      const chosenId = Number(await tableRowKeyByText(expect, page, chosenName))
      expect(chosenId).toBeGreaterThan(0)
      // The extra pagination fixtures reproduce the full-suite pager: page 3
      // is omitted at the first window. Clear search and await real rows before
      // advancing by identity, retaining an unfiltered multi-row proof.
      await page.getByTestId('hilos-table-search').fill('')
      await expect.poll(() => page.locator(ROWS).count()).toBeGreaterThan(1)
      await expect(page.getByTestId('hilos-table-page-1')).toHaveAttribute(
        'aria-current',
        'page',
      )
      await expect(page.getByTestId('hilos-table-page-3')).toHaveCount(0)
      await advanceTableToRow(expect, page, String(chosenId))
      const moment = expectUserActivityMoment(
        expect,
        listSocket.frames,
        'hilosUsers',
        chosenId,
      )
      const listOwn = await expectUserActivityDisplay(
        expect,
        page,
        chosenId,
        moment,
        ownZone,
        'list',
      )

      await gotoPage(expect, card, `/hilos/user/${chosenId}`)
      await expectUserTimeZone(expect, card, chosenZone)
      expect(userTableRows(cardSocket.frames).at(-1)?.user.timeZone).toBe(
        chosenZone,
      )
      expect(
        expectUserActivityMoment(
          expect,
          cardSocket.frames,
          'userDetail',
          chosenId,
        ),
      ).toBe(moment)
      await expectUserActivityDisplay(
        expect,
        card,
        chosenId,
        moment,
        ownZone,
        'card',
      )

      const listDocument = await watchClientDocument(page)
      const cardDocument = await watchClientDocument(card)
      try {
        const rowTop = await watchFirstRowTop(page)
        const headHeight = await watchHeight(
          card.getByTestId('hilos-page-head'),
        )
        const keys = await page
          .locator(ROWS)
          .evaluateAll((rows) =>
            rows.map((row) =>
              row.getAttribute('data-id')!.slice('hilos-table-row-'.length),
            ),
          )
        expect(keys.length).toBeGreaterThan(1)
        const requests = [
          listPageRequests(),
          listTableRequests(),
          cardPageRequests(),
          cardTableRequests(),
        ]
        expect(requests[0]).toBeGreaterThan(0)
        expect(requests[1]).toBeGreaterThan(0)
        expect(requests[2]).toBeGreaterThan(0)
        const sockets = [listSocket.sockets(), cardSocket.sockets()]
        for (const socket of [listSocket, cardSocket]) {
          expect(socket.sessions.at(-1)).toMatchObject({
            data: {
              entities: { currentUser: { id: readerId, timeZone: ownZone } },
            },
          })
          socket.replay(null)
        }

        const automatic = await expectUserActivityDisplay(
          expect,
          page,
          chosenId,
          moment,
          browserZone,
          'list',
        )
        expect(automatic).not.toBe(listOwn)
        await expectUserActivityDisplay(
          expect,
          card,
          chosenId,
          moment,
          browserZone,
          'card',
        )
        await expectUserTimeZone(expect, card, chosenZone)
        // All absence assertions follow the changed DOM: the restate has landed.
        expect([listSocket.sockets(), cardSocket.sockets()]).toEqual(sockets)
        expect([
          listPageRequests(),
          listTableRequests(),
          cardPageRequests(),
          cardTableRequests(),
        ]).toEqual(requests)
        await listDocument.unchanged()
        await cardDocument.unchanged()
        await expectTableRowOrder(expect, page, keys)
        await rowTop.unchanged()
        await headHeight.unchanged()
        for (const socket of [listSocket, cardSocket]) {
          expect(socket.sessions.at(-1)).toMatchObject({
            data: {
              entities: { currentUser: { id: readerId, timeZone: null } },
            },
          })
        }
        expect(
          expectUserActivityMoment(
            expect,
            listSocket.frames,
            'hilosUsers',
            chosenId,
          ),
        ).toBe(moment)
        expect(
          expectUserActivityMoment(
            expect,
            cardSocket.frames,
            'userDetail',
            chosenId,
          ),
        ).toBe(moment)
        await test.info().attach('people-reader-zone-wire', {
          body: JSON.stringify({
            list: listSocket.frames,
            card: cardSocket.frames,
            sessions: [listSocket.sessions, cardSocket.sessions],
          }),
          contentType: 'application/json',
        })
      } finally {
        await listDocument.dispose()
        await cardDocument.dispose()
      }
    } finally {
      listSocket.dispose()
      cardSocket.dispose()
      await card.close()
    }
  })

  for (const viewer of ['guest', 'person'] as const) {
    viewerTest(
      `a ${viewer} sees both saved and Automatic zones as Hidden without their values in server frames`,
      async ({ page, viewerPage }) => {
        const adminFrames: SocketFrame[] = []
        const adminWire = watchSocketFrames(page, (frame) =>
          adminFrames.push(frame),
        )
        const frames: SocketFrame[] = []
        const viewerWire = watchSocketFrames(viewerPage, (frame) =>
          frames.push(frame),
        )
        try {
          const automaticId = await signUpAdmin(expect, page)
          await gotoPage(expect, page, `/hilos/user/${automaticId}`)
          await expectUserTimeZone(expect, page, null)
          expect(userTableRows(adminFrames).at(-1)?.user.timeZone).toBeNull()
          await gotoPage(expect, page, '/hilos/users')
          await typeInto(page.getByTestId('hilos-table-search'), chosenName)
          const chosenId = Number(
            await tableRowKeyByText(expect, page, chosenName),
          )
          await gotoPage(expect, page, `/hilos/user/${chosenId}`)
          await expectUserTimeZone(expect, page, chosenZone)
          expect(userTableRows(adminFrames).at(-1)?.user.timeZone).toBe(
            chosenZone,
          )
          if (viewer === 'person') await signUp(expect, viewerPage)
          await setAdminViewMode(true)

          // Viewer names are hidden. Address real fixtures by the ids the admin
          // resolved, never by a search over a hidden name or a replacement row.
          for (const userId of [chosenId, automaticId]) {
            await gotoPage(expect, viewerPage, `/hilos/user/${userId}`)
            await expect(
              viewerPage.getByTestId('view-mode-banner'),
            ).toBeVisible()
            await expectUserTimeZone(expect, viewerPage, { _hidden: true })
            const moment = expectUserActivityMoment(
              expect,
              frames,
              'userDetail',
              userId,
            )
            await expectUserActivityDisplay(
              expect,
              viewerPage,
              userId,
              moment,
              browserZone,
              'card',
            )
          }
          await gotoPage(expect, viewerPage, '/hilos/users')
          await expect
            .poll(() => viewerPage.locator(ROWS).count())
            .toBeGreaterThan(1)
          await expect(
            viewerPage.getByTestId('hilos-table-page-3'),
          ).toHaveCount(0)
          await advanceTableToRow(expect, viewerPage, String(chosenId))
          expect(await viewerPage.locator(ROWS).count()).toBeGreaterThan(1)
          const moment = expectUserActivityMoment(
            expect,
            frames,
            'hilosUsers',
            chosenId,
          )
          await expectUserActivityDisplay(
            expect,
            viewerPage,
            chosenId,
            moment,
            browserZone,
            'list',
          )
          expectHiddenUserTimeZones(
            expect,
            frames,
            [chosenId, automaticId],
            chosenZone,
          )
          await test.info().attach(`people-${viewer}-raw-frames`, {
            body: JSON.stringify(frames),
            contentType: 'application/json',
          })
        } finally {
          adminWire.stop()
          viewerWire.stop()
        }
      },
    )
  }
})

test('filters the users table from the search box', async ({ page }) => {
  await signUpAdmin(expect, page)
  await gotoPage(expect, page, '/hilos/users')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(
    page.locator('[data-id^="hilos-users-open-"]').first(),
  ).toBeVisible()

  // A query no name matches empties the viewport; clearing it restores rows.
  const search = page.getByTestId('hilos-table-search')
  await typeInto(search, 'zzz-no-such-user-zzz')
  await expect(page.locator('[data-id^="hilos-users-open-"]')).toHaveCount(0)
  await search.fill('')
  await expect(
    page.locator('[data-id^="hilos-users-open-"]').first(),
  ).toBeVisible()
})

test('renames a user from the detail page and re-renders live', async ({
  page,
}) => {
  await signUpAdmin(expect, page)
  await gotoPage(expect, page, '/hilos/users')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await page.locator('[data-id^="hilos-users-open-"]').first().click()
  await expect(page.getByTestId('hilos-user-detail')).toBeVisible()

  const newName = 'E2E Renamed User'
  await page.getByTestId('hilos-user-edit').click()
  await typeInto(page.getByTestId('hilos-user-name-input'), newName)
  await clickSubmit(expect, page.getByTestId('hilos-user-save'))

  // The committed name returns over the live table and the edit form closes.
  await expect(page.getByTestId('hilos-user-name-input')).toHaveCount(0)
  await expect(page.getByTestId('hilos-page-head-title')).toHaveText(newName)
})

// HIL-1050: the rename modal merges against the live row. Two tabs of one
// context on the card of the user this test registers itself: a pristine open
// modal follows the other tab's rename and says so, a typed one conflicts and
// locks Save, and Take theirs adopts the other tab's name.
test('an open rename follows the other tab, then conflicts and takes theirs', async ({
  page,
}) => {
  const userId = await signUpAdmin(expect, page)
  const tabB = await page.context().newPage()
  await gotoPage(expect, page, `/hilos/user/${userId}`)
  await gotoPage(expect, tabB, `/hilos/user/${userId}`)
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(tabB.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-user-detail')).toBeVisible()
  await expect(tabB.getByTestId('hilos-user-detail')).toBeVisible()

  // Tab A opens the modal and does not type; tab B renames.
  await page.getByTestId('hilos-user-edit').click()
  await expect(page.getByTestId('hilos-user-name-input')).toBeVisible()
  await renameUser(tabB, 'E2E Elsewhere One')

  // A pristine modal follows the live row and says so on its message line.
  await expect(page.getByTestId('hilos-user-name-input')).toHaveValue(
    'E2E Elsewhere One',
  )
  await expect(page.getByTestId('hilos-user-edit-notice')).toContainText(
    'Updated just now',
  )
  await expect(page.getByTestId('conflict-badge')).toHaveCount(0)
  await expect(page.getByTestId('hilos-user-save')).toBeDisabled()

  // Tab A types; tab B renames again: a conflict, Save locked, no Merge.
  await typeInto(page.getByTestId('hilos-user-name-input'), 'E2E Mine')
  await renameUser(tabB, 'E2E Elsewhere Two')
  await expect(page.getByTestId('conflict-badge')).toBeVisible()
  await expect(page.getByTestId('hilos-user-edit-notice')).toContainText(
    'Changed elsewhere to "E2E Elsewhere Two"',
  )
  await expect(page.getByTestId('conflict-merge')).toHaveCount(0)
  await expect(page.getByTestId('hilos-user-save')).toBeDisabled()

  // Take theirs puts the other tab's name into the input; nothing is left to
  // save, so the modal closes without a question.
  await page.getByTestId('conflict-accept-theirs').click()
  await expect(page.getByTestId('hilos-user-name-input')).toHaveValue(
    'E2E Elsewhere Two',
  )
  await expect(page.getByTestId('conflict-badge')).toHaveCount(0)
  await expect(page.getByTestId('hilos-user-edit-notice')).toContainText(
    'Updated just now',
  )
  await expect(page.getByTestId('hilos-user-save')).toBeDisabled()
  await page.getByTestId('modal-close').click()
  await expect(page.getByTestId('hilos-user-name-input')).toHaveCount(0)
  await expect(page.getByTestId('hilos-page-head-title')).toHaveText(
    'E2E Elsewhere Two',
  )
  await tabB.close()
})

/**
 * Rename the user on the card through its modal, and settle when the modal
 * closes on the committed name.
 *
 * @param tab The tab on the user's card.
 * @param name The new display name.
 */
async function renameUser(tab: Page, name: string): Promise<void> {
  await tab.getByTestId('hilos-user-edit').click()
  await typeInto(tab.getByTestId('hilos-user-name-input'), name)
  await clickSubmit(expect, tab.getByTestId('hilos-user-save'))
  await expect(tab.getByTestId('hilos-user-name-input')).toHaveCount(0)
  await expect(tab.getByTestId('hilos-page-head-title')).toHaveText(name)
}

test('shows the connected user as online with a live session', async ({
  page,
}) => {
  // Register the client and take its own id, then open its detail directly.
  // Robust against how many users the shared DB has accumulated: the self row
  // need not be on the first viewport page of /hilos/users.
  const userId = await signUpAdmin(expect, page)

  await gotoPage(expect, page, `/hilos/user/${userId}`)
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-user-detail')).toBeVisible()

  // Regression: a connected user must render online with at least one live
  // session. The detail table's computed presence fields were never populated
  // (the project browser context returned null), so the live user rendered as
  // offline with 0 sessions. The presence badge stands over an invisible twin
  // holding the room of its widest word (HIL-1566); the visible one is read.
  await expect(page.getByTestId('hilos-user-sessions')).toHaveText(/[1-9]/)
  await expect(
    page
      .getByTestId('hilos-page-head-block')
      .locator('.badge')
      .filter({ visible: true }),
  ).toHaveText('online')
})

// HIL-327: the /hilos/users viewport table on volume. `test:user:seed` seeds 25
// deterministic `seed-###` users on stand bring-up, so window paging and server search
// run against more than one page — the case a single registered user can never reach.
test('windows, paginates, and searches the seeded users', async ({ page }) => {
  await signUpAdmin(expect, page)
  await gotoPage(expect, page, '/hilos/users')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()

  // The window holds exactly one page of rows regardless of how large the table is.
  const rows = page.locator('[data-id^="hilos-table-row-"]')
  await expect(rows).toHaveCount(10)

  // The count reflects the whole selection (>= 25 seeded + this test's own user), so it
  // is asserted by shape and lower bound, not an exact number on the shared database.
  const count = page.getByTestId('hilos-table-count')
  await expect(count).toHaveText(countOf(1, 10))
  const total = totalOf(await count.textContent())
  expect(total).toBeGreaterThanOrEqual(26)

  // An exact count draws the page numbers, and the page on screen is the one that
  // says so.
  const pageOne = page.getByTestId('hilos-table-page-1')
  const pageTwo = page.getByTestId('hilos-table-page-2')
  await expect(pageOne).toHaveAttribute('aria-current', 'page')

  // Next advances the window to a different set of rows; prev restores it. The range
  // and the number move the moment the control is pressed, so the rows are what says
  // the window arrived.
  const rowKeys = async () =>
    rows.evaluateAll((els) => els.map((el) => el.getAttribute('data-id')))
  const firstKeys = JSON.stringify(await rowKeys())
  await page.getByTestId('hilos-table-next').click()
  await expect(pageTwo).toHaveAttribute('aria-current', 'page')
  await expect(count).toHaveText(countOf(11, 20))
  await expect
    .poll(async () => JSON.stringify(await rowKeys()))
    .not.toBe(firstKeys)
  const secondKeys = JSON.stringify(await rowKeys())
  await page.getByTestId('hilos-table-prev').click()
  await expect(pageOne).toHaveAttribute('aria-current', 'page')
  await expect(count).toHaveText(countOf(1, 10))
  await expect
    .poll(async () => JSON.stringify(await rowKeys()))
    .not.toBe(secondKeys)

  // Server search filters the whole selection, not just the loaded window: the shared
  // `seed-` prefix matches the 25 deterministically seeded users, so the filtered total
  // is well over one page yet strictly below the unfiltered selection.
  //
  // It is asserted by shape, narrowing, and lower bound — never an exact number. On a
  // freshly brought-up shared stand the viewport count can settle one short as the burst
  // of window responses from typing lands over the socket (a stable "24 total" on some
  // runs, "25 total" on others), and the display can briefly stay at the unfiltered count
  // before the filter settles. Polling for a value below the unfiltered total waits out
  // that transient and proves the server actually narrowed the selection; the lower bound
  // then proves search reached the whole seeded fixture across pages. The exact-count
  // assertion was dropped by owner decision (HIL-327, 2026-08-02) as it raced 24/25.
  const search = page.getByTestId('hilos-table-search')
  await search.fill('')
  await search.pressSequentially('seed-', { delay: 10 })
  await expect(count).toHaveText(countOf(1, 10))
  await expect
    .poll(async () => totalOf(await count.textContent()), {
      timeout: 15000,
    })
    .toBeLessThan(total)
  const seededTotal = totalOf(await count.textContent())
  expect(seededTotal).toBeGreaterThanOrEqual(20)
  await expect(rows).toHaveCount(10)
})

// HIL-824: the takeover is a framework action on the person's card (on the users list
// until HIL-1170). Its name is closed by that page's ADMIN level and the sessions library
// performs the write, so what proves the whole two-hop route in one assertion is the
// banner: the framework shell draws it from the rebound session's own handshake, which
// cannot arrive unless the write landed (HIL-1064 - the strip is the SDK's, the demo
// mounts nothing for it).
//
// Stop is the way back, and it is a tracked action: its answer rides the identity the
// server restores, so the strip leaving and the admin gear coming back are the proof
// that the session is the administrator's again.
//
// The refusal branch is not driven from here on purpose. Once the ADMIN level closes the
// page, the guards the library still runs are out of a browser's reach — a non-admin never
// gets the card, the own card offers the button switched off, and a nested takeover has no
// admin page left to press from. What a refusal looks like on the wire is pinned in
// demo/chat/tests/Integration/ImpersonationTest.php instead.
test('takes a person over from their card and shows the shell banner', async ({
  browser,
  page,
}) => {
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  const personContext = await browser.newContext({ baseURL, ignoreHTTPSErrors })
  const personPage = await personContext.newPage()
  try {
    await signUpAdmin(expect, page)
    const person = await signUp(expect, personPage)
    await gotoPage(expect, page, `/hilos/user/${person.userId}`)
    await expect(page.getByTestId('conn-state')).toHaveText('connected')

    // The impersonation step is declared off (step-up.md), so the window opens at once.
    const impersonate = page.getByTestId('hilos-user-impersonate-open')
    await expect(impersonate).toBeVisible()
    await impersonate.click()

    // The takeover rotates the session token, and the client drops and reopens its socket
    // for it once the takeover is answered. The proof the reopen happened is the next
    // socket, not a glimpse of the reconnecting label a fast reopen passes through unseen.
    let sockets = 0
    page.on('websocket', () => {
      sockets += 1
    })

    // A mutation is confirmed in a modal, and the confirm is what dispatches.
    const confirm = page.getByTestId('hilos-user-impersonate-confirm')
    await expect(confirm).toBeVisible()
    await confirm.click()

    // The takeover arrives as the rebound session, not as an ack the view acted on.
    await expect(page.getByTestId('impersonation-banner')).toBeVisible()

    // Stop pressed inside the rotation's reopen is refused as not connected - honestly,
    // and beside the point here - so it waits for the new socket to be up.
    await expect.poll(() => sockets).toBeGreaterThan(0)
    await expect(page.getByTestId('conn-state')).toHaveText('connected')
    const stop = page.getByTestId('impersonation-stop')
    await stop.scrollIntoViewIfNeeded()
    await expect(stop).toBeVisible()
    await expect(stop).toBeEnabled()
    await stop.focus()
    await stop.click()

    // The way back arrives the same way: the restored identity takes the strip away and
    // brings the admin gear back.
    await expect(page.getByTestId('impersonation-banner')).toBeHidden()
    await expect(page.getByTestId('nav-admin')).toBeVisible()
  } finally {
    await personContext.close()
  }
})

// HIL-945: the card reads one verdict of the person's standing — the badge in its
// header names the standing shown, and the freeze has its own row beside the
// block. A blocked person can still be taken over (the handshake asks about the
// person at the keyboard, and that is the administrator), and the takeover strip
// turns red for them. Blocked outranks a scheduled deletion: the badge says
// "Deletion scheduled" only once the block is lifted. A freeze cannot be reached
// from a browser here — no catalog carries a second substantial revision — so the
// frozen cases live in framework/tests/Integration/AccountStandingIntegrationTest.php.
test("shows a person's standing on the card and in the takeover strip", async ({
  browser,
  page,
}) => {
  const reconnect = await controlSocketReconnect(page)
  const log = watchDeliveryFrames(page)
  const rotationEvidence: Record<string, unknown> = {}
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  const personContext = await browser.newContext({ baseURL, ignoreHTTPSErrors })
  const personPage = await personContext.newPage()
  try {
    const adminId = await signUpAdmin(expect, page)
    const person = await signUp(expect, personPage)
    await gotoPage(expect, page, `/hilos/user/${person.userId}`)
    const badge = page.getByTestId('user-standing-badge')
    await expect(page.getByTestId('hilos-user-frozen-state')).toContainText(
      'Not frozen',
    )
    await expect(badge).toHaveCount(0)

    await clickSubmit(expect, page.getByTestId('hilos-user-block-open'))
    await clickSubmit(expect, page.getByTestId('hilos-user-lifecycle-confirm'))
    await expect(page.getByTestId('modal')).toBeHidden()
    await expect(badge).toHaveText('Blocked')
    await expect(page.getByTestId('hilos-user-frozen-state')).toContainText(
      'Not frozen',
    )

    // The takeover strip and the ring by the header avatar take the color of
    // the person taken over. This demo has no profile link: the avatar stands
    // in the shell's name slot. A blocked person may be taken over by default
    // (HIL-1170), so the card's button stays live.
    await dismissToasts(page)
    await clickSubmit(expect, page.getByTestId('hilos-user-impersonate-open'))
    let sockets = 0
    page.on('websocket', () => {
      sockets += 1
    })
    const strip = page.getByTestId('impersonation-banner')
    await expectSessionRotation(expect, page, async () => {
      await clickSubmit(
        expect,
        page.getByTestId('hilos-user-impersonate-confirm'),
      )
      await expect(strip).toHaveClass(/\balert-danger\b/)
      await expect(
        page.getByTestId('nav-profile-name').getByTestId('avatar-mark'),
      ).toHaveClass(/\btext-danger-emphasis\b/)
    })
    await expect.poll(() => sockets).toBeGreaterThan(0)
    await expect(page.getByTestId('conn-state')).toHaveText('connected')
    const stopSocket = log.sockets()
    const cookieBeforeStop = await sessionToken(page)
    await expectSessionRotation(expect, page, async () => {
      await reconnect.hold()
      await clickSubmit(expect, page.getByTestId('impersonation-stop'))
      await expect(strip).toBeHidden()
      await expect(page.getByTestId('nav-admin')).toBeVisible()
      // Reproduce the former navigation boundary: the old socket already
      // names the administrator, but its replacement has not exchanged the
      // cookie. Neither the hidden strip nor the admin entry proves rotation.
      await expect
        .poll(async () =>
          (await page.context().cookies()).some(({ name }) =>
            isRotateCookie(name),
          ),
        )
        .toBe(true)
      const oldSocketFrames = log.packets
        .filter(({ socket }) => socket === stopSocket)
        .flatMap(({ frames }) => frames)
      const identity = oldSocketFrames
        .slice()
        .reverse()
        .find(({ wire }) => object(wire).type === 'handshake_response')!
      const entities = object(object(identity.wire).data).entities
      const held = {
        restoredUserId: object(object(entities).currentUser).id,
        impersonatedBy: object(entities).impersonatedBy,
        credentialUnchanged: (await sessionToken(page)) === cookieBeforeStop,
        replacementWelcomed: log.packets.some(
          (packet) =>
            packet.socket > stopSocket &&
            packet.frames.some(({ wire }) => object(wire).type === 'handshake'),
        ),
      }
      rotationEvidence.held = held
      expect(held).toEqual({
        restoredUserId: adminId,
        impersonatedBy: null,
        credentialUnchanged: true,
        replacementWelcomed: false,
      })
      await reconnect.release()
    })
    await expect
      .poll(async () => (await sessionToken(page)) !== cookieBeforeStop)
      .toBe(true)
    rotationEvidence.credentialRotated = true
    await expect(page.getByTestId('conn-state')).toHaveText('connected')

    await gotoPage(expect, page, `/hilos/user/${person.userId}`, PAGE_READY)
    await expect(page.getByTestId('nav-admin')).toBeVisible()
    await clickSubmit(expect, page.getByTestId('hilos-user-deletion-open'))
    // Scheduling someone else's deletion is the administrator's to confirm (HIL-1275).
    await typeInto(page.getByTestId('step-up-password'), PASSWORD)
    await clickSubmit(
      expect,
      page.getByTestId('hilos-user-lifecycle-step-up-confirm'),
    )
    await clickSubmit(expect, page.getByTestId('hilos-user-lifecycle-confirm'))
    await expect(page.getByTestId('modal')).toBeHidden()
    await expect(page.getByTestId('hilos-user-deletion-open')).toHaveText(
      'Cancel deletion',
    )
    await expect(badge).toHaveText('Blocked')

    await dismissToasts(page)
    await clickSubmit(expect, page.getByTestId('hilos-user-block-open'))
    await clickSubmit(expect, page.getByTestId('hilos-user-lifecycle-confirm'))
    await expect(page.getByTestId('modal')).toBeHidden()
    await expect(badge).toHaveText('Deletion scheduled')
  } finally {
    log.stop()
    await test.info().attach('standing-takeover-rotation', {
      body: JSON.stringify({ ...rotationEvidence, packets: log.packets }),
      contentType: 'application/json',
    })
    await reconnect.dispose()
    await personContext.close()
  }
})

test('closes an idle block confirmation when another tab changes the block', async ({
  page,
  browser,
}) => {
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  const personContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors,
  })
  const personPage = await personContext.newPage()
  const pageB = await page.context().newPage()

  try {
    await grantAdminToSelf(expect, page)
    const targetId = await grantAdminToSelf(expect, personPage)
    await setAdmin(targetId, false)

    await gotoPage(expect, page, `/hilos/user/${targetId}`)
    await gotoPage(expect, pageB, `/hilos/user/${targetId}`)

    await clickSubmit(expect, page.getByTestId('hilos-user-block-open'))
    await expect(page.getByTestId('modal')).toBeVisible()
    await clickSubmit(expect, pageB.getByTestId('hilos-user-block-open'))
    await expect(pageB.getByTestId('modal')).toBeVisible()

    await clickSubmit(expect, pageB.getByTestId('hilos-user-lifecycle-confirm'))
    await expectHilosUserBlockSettled(expect, pageB, true)
    await expectHilosUserBlockSettled(expect, page, true)

    await dismissToasts(pageB)

    await clickSubmit(expect, page.getByTestId('hilos-user-block-open'))
    await expect(page.getByTestId('modal')).toBeVisible()
    await clickSubmit(expect, pageB.getByTestId('hilos-user-block-open'))
    await expect(pageB.getByTestId('modal')).toBeVisible()

    await clickSubmit(expect, pageB.getByTestId('hilos-user-lifecycle-confirm'))
    await expectHilosUserBlockSettled(expect, pageB, false)
    await expectHilosUserBlockSettled(expect, page, false)
  } finally {
    await pageB.close()
    await personContext.close()
  }
})

// The framework people section under admin view mode (HIL-1263): a viewer — a guest
// included — opens every window of the person card directly, without the confirmation
// step, and every mutation control stands disabled by the mode. The takeover is one of
// the card's windows since HIL-1170; the users list carries none. The merge window is
// not this demo's: binance-btc-tracker wires no account merge (backend/Hilos.php:112);
// the viewer case of that window is chat's account-merge.spec.ts.
test.describe('the people section in the admin view mode', () => {
  test.afterEach(() => setAdminViewMode(false))

  test("a guest opens the available windows of a person's card and has nothing to send from them", async ({
    browser,
    page,
  }) => {
    const { baseURL, ignoreHTTPSErrors } = test.info().project.use
    const userAContext = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors,
    })
    const userBContext = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors,
    })
    const userAPage = await userAContext.newPage()
    const userBPage = await userBContext.newPage()

    try {
      const { userId: userAId } = await signUp(expect, userAPage)
      const { userId: userBId } = await signUp(expect, userBPage)

      await setAdminViewMode(true)

      // This demo has no impersonation catalog keys, so the viewer sees no takeover section.
      await gotoPage(expect, page, `/hilos/user/${userBId}`)
      await expect(page.getByTestId('hilos-user-impersonate-open')).toHaveCount(
        0,
      )

      // 1. User card: lifecycle modals (admin, block, deletion)
      await gotoPage(expect, page, `/hilos/user/${userAId}`)
      for (const key of ['admin', 'block', 'deletion'] as const) {
        await clickSubmit(expect, page.getByTestId(`hilos-user-${key}-open`))
        await expect(
          page.getByTestId('hilos-user-lifecycle-step-up'),
        ).toHaveCount(0)
        const lifecycleConfirm = page.getByTestId(
          'hilos-user-lifecycle-confirm',
        )
        await expect(lifecycleConfirm).toBeDisabled()
        await expect(lifecycleConfirm).toHaveAttribute(
          'aria-describedby',
          /(^| )hilos-view-mode-strip-text( |$)/,
        )
        await clickSubmit(
          expect,
          page.getByTestId('hilos-user-lifecycle-cancel'),
        )
      }

      // 2. User card: rename modal
      await clickSubmit(expect, page.getByTestId('hilos-user-edit'))
      await expect(page.getByTestId('hilos-user-save')).toBeDisabled()
      await clickSubmit(expect, page.getByTestId('hilos-user-cancel'))
    } finally {
      await userAContext.close()
      await userBContext.close()
    }
  })
})
