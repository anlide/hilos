import { expect, test } from '@playwright/test'

import {
  CHANGE_LOG_BLOCK_ACTION,
  CHANGE_LOG_PATH,
  chooseChangeLogFeedTable,
  dismissToasts,
  expectChangeLogFeedRow,
  expectChangeLogReceiptOpen,
  expectNarrowColumns,
  expectTrackedTableRecord,
  expectTrackedTableWhole,
  shownByTestId,
  tableRowKeyByText,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { gotoPage } from '../helpers/page.js'
import { clickSubmit, signUp, typeInto } from '../helpers/session.js'

test('follows a block receipt to the history of its record and back to the whole table', async ({
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
    await shownByTestId(page, `hilos-change-log-open-${rowKey}`).click()
    await expectChangeLogReceiptOpen(expect, page, receiptId)
    const record = page
      .getByTestId('hilos-change-log-detail')
      .getByTestId(/^hilos-change-log-receipt-record-\d+$/)
    await expect(record).toHaveText(`hilos_user #${person.userId}`)
    await record.click()

    const modal = page.getByTestId('modal')
    await expect(modal).toHaveCount(0)
    await expectTrackedTableRecord(
      expect,
      page,
      'hilos_user',
      String(person.userId),
    )
    const editKey = await tableRowKeyByText(expect, page, 'updated')
    const edit = shownByTestId(page, `hilos-table-row-${editKey}`)
    await expect(edit).toContainText(`#${person.userId}`)
    await expect(edit).toContainText('block: 0 → 1')
    await expect(edit).toContainText('Web')

    await shownByTestId(
      page,
      `hilos-change-log-history-receipt-${editKey}`,
    ).click()
    await expectChangeLogReceiptOpen(expect, page, receiptId)
    await expect(page.getByTestId('hilos-change-log-detail')).toContainText(
      CHANGE_LOG_BLOCK_ACTION,
    )
    await page.getByTestId('hilos-change-log-detail-close').click()
    await expect(modal).toHaveCount(0)

    await page.getByTestId('hilos-change-log-record-filter-clear').click()
    await expectTrackedTableWhole(expect, page, 'hilos_user')

    await page.setViewportSize({ width: 375, height: 812 })
    await expectNarrowColumns(expect, page, 'block', 'Yes / no')
  } finally {
    await personContext.close()
  }
})
