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
  expectChangeLogReceiptMoment,
  expectChangeLogTimes,
  expectChangeLogValues,
  expectRegistrationFeedRow,
  expectRegistrationHistory,
  expectRegistrationReceiptFacts,
  expectTrackedTableRecord,
  forwardSessionTimeZone,
  shownByTestId,
  watchChangeLogDisplay,
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

// FIXME(F-024, 2026-10-24): окно чека не открывается после Enter · чужой · попытка 1 · HIL-1661
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

test.describe('Change Log reader time zone', () => {
  test.use({ timezoneId: 'UTC' })

  test('renders the delivered own zone and returns the same receipt and feed to Automatic', async ({
    page,
  }) => {
    // This proves display of an existing session contract. The real daemon
    // still supplies the receipt and feed; backend zone writes are HIL-1580.
    const socket = await forwardSessionTimeZone(page, 'Europe/Kyiv')
    try {
      const userId = await signUpAdmin(page)
      await gotoPage(page, `${CHANGE_LOG_TABLES_PATH}/hilos_user/${userId}`)
      const receiptId = await expectRegistrationHistory(expect, page)
      await gotoPage(page, CHANGE_LOG_PATH)
      const rowKey = await expectRegistrationFeedRow(expect, page, receiptId)
      await shownByTestId(page, `hilos-change-log-open-${rowKey}`).click()
      await expectChangeLogReceiptOpen(expect, page, receiptId)
      await expect(
        page.getByTestId('hilos-change-log-receipt-time'),
      ).toBeVisible()
      const createdAt = expectChangeLogReceiptMoment(
        expect,
        socket.frames,
        receiptId,
      )
      const ownTime = await expectChangeLogTimes(
        expect,
        page,
        receiptId,
        createdAt,
        'Europe/Kyiv',
      )
      const display = await watchChangeLogDisplay(expect, page, receiptId)
      const session = socket.sessions.at(-1)
      expect(session).toMatchObject({
        data: {
          entities: { currentUser: { id: userId, timeZone: 'Europe/Kyiv' } },
        },
      })
      const sockets = socket.sockets()

      socket.replay(null)
      const automaticTime = await expectChangeLogTimes(
        expect,
        page,
        receiptId,
        createdAt,
        'UTC',
      )
      expect(automaticTime).not.toBe(ownTime)
      expect(socket.sessions.at(-1)).toMatchObject({
        data: { entities: { currentUser: { id: userId, timeZone: null } } },
      })
      expect(socket.sockets()).toBe(sockets)
      expect(
        expectChangeLogReceiptMoment(expect, socket.frames, receiptId),
      ).toBe(createdAt)
      await display.unchanged()
      await display.dispose()
    } finally {
      socket.dispose()
    }
  })
})
