import { expect, test } from '@playwright/test'

import {
  createTestUser,
  grantAdminToSelf,
  CHANGE_LOG_BLOCK_ACTION,
  CHANGE_LOG_FEED_PATH,
  CHANGE_LOG_TABLES_PATH,
  chooseChangeLogFeedTable,
  dismissToasts,
  expectChangeLogDashboardCard,
  expectChangeLogFeedRow,
  expectChangeLogOverview,
  expectChangeLogFeed,
  expectChangeLogReceiptOpen,
  expectChangeLogValues,
  expectRegistrationFeedRow,
  expectRegistrationHistory,
  expectRegistrationReceiptFacts,
  expectTrackedTableRecord,
  shownByTestId,
  clickSubmit,
  typeInto,
  gotoPage,
  signUpAdmin,
} from '../../../../../framework/frontend/e2e/index.js'

test('opens Change Log from the admin dashboard with its overview and feed', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, '/hilos')

  await expectChangeLogDashboardCard(expect, page)
  await page.getByTestId('dashboard-card-hilos_change_log').click()
  await expectChangeLogOverview(expect, page)
  await gotoPage(expect, page, CHANGE_LOG_FEED_PATH)
  await expectChangeLogFeed(expect, page)
})

// FIXME(F-022, 2026-10-23): receipt modal does not return focus · чужой · попытка 3 · HIL-1641
test(
  'opens a block receipt with old and new values and returns keyboard focus',
  { tag: '@quarantine:F-022' },
  async ({ browser, page }) => {
    const { baseURL, ignoreHTTPSErrors } = test.info().project.use
    const personContext = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors,
    })
    const personPage = await personContext.newPage()
    try {
      const adminId = await grantAdminToSelf(expect, page)
      const person = await createTestUser(expect, personPage)
      await gotoPage(expect, page, `/hilos/user/${person.userId}`)
      await clickSubmit(expect, page.getByTestId('hilos-user-block-open'))
      await expect(page.getByTestId('modal')).toBeVisible()
      await clickSubmit(
        expect,
        page.getByTestId('hilos-user-lifecycle-confirm'),
      )
      await expect(page.getByTestId('modal')).toBeHidden()
      await dismissToasts(page)

      await gotoPage(expect, page, CHANGE_LOG_FEED_PATH)
      await chooseChangeLogFeedTable(page, 'hilos_user')
      await typeInto(page.getByTestId('hilos-table-search'), `#${adminId}`)
      const { rowKey, receiptId } = await expectChangeLogFeedRow(
        expect,
        page,
        `hilos_user #${person.userId} · updated`,
        CHANGE_LOG_BLOCK_ACTION,
      )

      const opener = shownByTestId(page, `hilos-change-log-open-${rowKey}`)
      await expect(opener).toHaveAccessibleName(`View receipt #${receiptId}`)
      await opener.focus()
      await page.keyboard.press('Enter')
      await expectChangeLogReceiptOpen(expect, page, receiptId)
      const modal = page.getByTestId('modal')
      await expect(modal).toBeFocused()
      const detail = page.getByTestId('hilos-change-log-detail')
      await expect(detail).toContainText(`hilos_user #${person.userId}`)
      await expect(detail).toContainText(CHANGE_LOG_BLOCK_ACTION)
      await expectChangeLogValues(expect, page, 'block', '0', '1')
      await page.keyboard.press('Escape')
      await expect(modal).toHaveCount(0)
      await expect(opener).toBeFocused()
    } finally {
      await personContext.close()
    }
  },
)

test('its registration receipt shows person and identity creation as facts without personal values', async ({
  page,
}) => {
  const userId = await signUpAdmin(expect, page)
  // The zone the browser names at sign-up rides the registration's receipt, so
  // the feed sums the person's entries without a key: the history names it.
  await gotoPage(
    expect,
    page,
    `${CHANGE_LOG_TABLES_PATH}/hilos_user/history/${userId}`,
  )
  await expectTrackedTableRecord(expect, page, 'hilos_user', String(userId))
  const receiptId = await expectRegistrationHistory(expect, page)
  await gotoPage(expect, page, CHANGE_LOG_FEED_PATH)
  const rowKey = await expectRegistrationFeedRow(expect, page, receiptId)
  await shownByTestId(page, `hilos-change-log-open-${rowKey}`).click()
  await expectChangeLogReceiptOpen(expect, page, receiptId)
  await expectRegistrationReceiptFacts(expect, page, userId)
  await page.getByTestId('hilos-change-log-detail-close').click()
  await expect(page.getByTestId('modal')).toHaveCount(0)
})
