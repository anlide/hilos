import { expect, test } from '@playwright/test'

import {
  CHANGE_LOG_BLOCK_ACTION,
  CHANGE_LOG_PATH,
  CHANGE_LOG_TABLES_PATH,
  chooseChangeLogFeedTable,
  dismissToasts,
  expectChangeLogDashboardCard,
  expectChangeLogFeedRow,
  expectChangeLogOverview,
  expectChangeLogReceiptOpen,
  expectChangeLogValues,
  expectRegistrationFeedRow,
  expectRegistrationHistory,
  expectRegistrationReceiptFacts,
  expectTrackedTableRecord,
  shownByTestId,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { gotoPage } from '../helpers/page.js'
import { clickSubmit, signUp, typeInto } from '../helpers/session.js'

test('opens Change Log from the admin dashboard with its overview and feed', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos')

  await expectChangeLogDashboardCard(expect, page)
  await page.getByTestId('dashboard-card-hilos_change_log').click()
  await expectChangeLogOverview(expect, page)
})

// FIXME(F-017, 2026-10-22): после Escape не возвращается фокус на кнопку чека · чужой · попытка 2 · HIL-1601
test.fixme('opens a block receipt with old and new values and returns keyboard focus', async ({
  browser,
  page,
}) => {
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use
  const personContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors,
  })
  const personPage = await personContext.newPage()
  try {
    const adminId = await signUpAdmin(page)
    const person = await signUp(personPage)
    await gotoPage(page, `/hilos/user/${person.userId}`)
    await clickSubmit(page.getByTestId('hilos-user-block-open'))
    await expect(page.getByTestId('modal')).toBeVisible()
    await clickSubmit(page.getByTestId('hilos-user-lifecycle-confirm'))
    await expect(page.getByTestId('modal')).toBeHidden()
    await dismissToasts(page)

    await gotoPage(page, CHANGE_LOG_PATH)
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
})

test('its registration receipt shows person and identity creation as facts without personal values', async ({
  page,
}) => {
  const userId = await signUpAdmin(page)
  // The zone the browser names at sign-up rides the registration's receipt, so
  // the feed sums the person's entries without a key: the history names it.
  await gotoPage(page, `${CHANGE_LOG_TABLES_PATH}/hilos_user/${userId}`)
  await expectTrackedTableRecord(expect, page, 'hilos_user', String(userId))
  const receiptId = await expectRegistrationHistory(expect, page)
  await gotoPage(page, CHANGE_LOG_PATH)
  const rowKey = await expectRegistrationFeedRow(expect, page, receiptId)
  await shownByTestId(page, `hilos-change-log-open-${rowKey}`).click()
  await expectChangeLogReceiptOpen(expect, page, receiptId)
  await expectRegistrationReceiptFacts(expect, page, userId)
  await page.getByTestId('hilos-change-log-detail-close').click()
  await expect(page.getByTestId('modal')).toHaveCount(0)
})
