import { expect, test } from '../test.js'

import {
  signUp,
  CHANGE_LOG_BLOCK_ACTION,
  CHANGE_LOG_FEED_PATH,
  CHANGE_LOG_TABLES_PATH,
  chooseChangeLogFeedTable,
  dismissToasts,
  expectChangeLogFeedRow,
  expectChangeLogReceiptOpen,
  expectRegistrationHistory,
  expectTrackedTableRecord,
  expectTrackedTableWhole,
  shownByTestId,
  tableRowKeyByText,
  clickSubmit,
  typeInto,
  gotoPage,
  grantAdminToSelf,
} from '../../../../../framework/frontend/e2e/index.js'

// spec-owner: demo — carries Polls' Angular browser journeys for these shared pages

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
    const adminId = await grantAdminToSelf(expect, page)
    const person = await signUp(expect, personPage)
    await gotoPage(expect, page, `/hilos/user/${person.userId}`)
    await clickSubmit(expect, page.getByTestId('hilos-user-block-open'))
    await expect(page.getByTestId('modal')).toBeVisible()
    await clickSubmit(expect, page.getByTestId('hilos-user-lifecycle-confirm'))
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
      51,
    )
    const recordPath = `/hilos/change-log/table/hilos_user/history/${person.userId}`
    await expect(page).toHaveURL(recordPath)
    const clearRecordFilter = shownByTestId(
      page,
      'hilos-change-log-record-filter-clear',
    )
    await expect(clearRecordFilter).toHaveRole('link')
    await expect(clearRecordFilter).toHaveAttribute(
      'href',
      `/hilos/change-log/table/hilos_user/history`,
    )
    // The registration's receipt also carries the zone the browser named at
    // sign-up, a second update of this record, so the block row is found by field.
    await expectRegistrationHistory(expect, page)
    const editKey = await tableRowKeyByText(expect, page, 'block')
    const edit = shownByTestId(page, `hilos-table-row-${editKey}`)
    const changes = edit.getByRole('cell').filter({ hasText: 'block' })
    await expect(changes).toHaveCount(1)
    await expect(changes).toContainText(/block\s*Was\s*0\s*Now\s*1/)
    await expect(edit).toContainText(`#${person.userId}`)
    await expect(edit).toContainText('updated')
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
    await expectTrackedTableWhole(expect, page, 'hilos_user', 51)

    await gotoPage(expect, page, recordPath)
    await expectTrackedTableRecord(
      expect,
      page,
      'hilos_user',
      String(person.userId),
      51,
    )
    await expect(page).toHaveURL(recordPath)
    await expect(clearRecordFilter).toHaveAttribute(
      'href',
      `/hilos/change-log/table/hilos_user/history`,
    )
    await expectRegistrationHistory(expect, page)
    const directEditKey = await tableRowKeyByText(expect, page, 'block')
    const directEdit = shownByTestId(page, `hilos-table-row-${directEditKey}`)
    const directChanges = directEdit
      .getByRole('cell')
      .filter({ hasText: 'block' })
    await expect(directChanges).toHaveCount(1)
    await expect(directChanges).toContainText(/block\s*Was\s*0\s*Now\s*1/)
    await expect(directEdit).toContainText(`#${person.userId}`)

    await page.reload()
    await expectTrackedTableRecord(
      expect,
      page,
      'hilos_user',
      String(person.userId),
      51,
    )
    await expectRegistrationHistory(expect, page)
    await expect(clearRecordFilter).toHaveAttribute(
      'href',
      `/hilos/change-log/table/hilos_user/history`,
    )
    await page.goBack()
    await expectTrackedTableWhole(expect, page, 'hilos_user', 51)
    await expect(page).toHaveURL(`/hilos/change-log/table/hilos_user/history`)
    await page.goForward()
    await expectTrackedTableRecord(
      expect,
      page,
      'hilos_user',
      String(person.userId),
      51,
    )
    const oldRecordPath = `${CHANGE_LOG_TABLES_PATH}/hilos_user/${person.userId}`
    await gotoPage(expect, page, oldRecordPath, 'error')
    await expect(page).toHaveURL(oldRecordPath)
    await expect(page.getByTestId('page-error')).toHaveAttribute(
      'data-error-code',
      '404',
    )
    await expect(
      page.getByTestId('hilos-change-log-record-filter'),
    ).toHaveCount(0)
    await expect(page.getByTestId('hilos-admin-title')).toHaveCount(0)
    await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
  } finally {
    await personContext.close()
  }
})
