import { expect, test } from '@playwright/test'

import {
  CHANGE_LOG_FEED_PATH,
  chooseChangeLogFeedTable,
  dismissToasts,
  expectChangeLogFeedRow,
  expectChangeLogReceiptOpen,
  expectChangeLogValues,
  expectTrackedTableRecord,
  shownByTestId,
  clickSubmit,
  typeInto,
  gotoPage,
  tableRowKeyByText,
  createTestAdmin,
} from '../../../../../framework/frontend/e2e/index.js'

// spec-owner: demo — a bot description is chat's product and the only long text under the journal

// Only chat's bot description supplies long text to prove full receipt values
// and their omission from record history. The common change-log*.spec.ts
// scenarios exercise the section through account blocking.
test("keeps a bot description's full old and new values in its receipt and leaves them out of its record's history", async ({
  page,
}) => {
  const userId = await createTestAdmin(expect, page)
  const name = `AAA journal ${Date.now()}`
  // The bot form trims boundary whitespace before saving. Keep the inputs in
  // that saved form so the journal assertions below remain byte-for-byte.
  const before =
    'Before: a saved description.\n' +
    'The full old value stays in the journal. '.repeat(6).trimEnd()
  const after =
    'After: the complete new description.\n' +
    'The receipt renders every word and line. '.repeat(6).trimEnd()
  await gotoPage(expect, page, '/hilos/app/bots')
  await page.getByTestId('hilos-table-main-action').click()
  await typeInto(page.getByTestId('admin-bots-name'), name)
  await typeInto(page.getByTestId('admin-bots-description'), before)
  await page.getByTestId('admin-bots-active').uncheck()
  await clickSubmit(expect, page.getByTestId('admin-bots-save'))
  await expect(page.getByTestId('admin-bots-save')).toHaveCount(0)
  const botKey = await tableRowKeyByText(expect, page, name)
  await dismissToasts(page)
  await shownByTestId(page, `admin-bots-edit-${botKey}`).click()
  await typeInto(page.getByTestId('admin-bots-description'), after)
  await clickSubmit(expect, page.getByTestId('admin-bots-save'))
  await expect(page.getByTestId('admin-bots-save')).toHaveCount(0)
  await dismissToasts(page)

  await gotoPage(expect, page, CHANGE_LOG_FEED_PATH)
  await chooseChangeLogFeedTable(page, 'bot')
  await typeInto(page.getByTestId('hilos-table-search'), `#${userId}`)
  const { rowKey, receiptId } = await expectChangeLogFeedRow(
    expect,
    page,
    `bot #${botKey} · updated`,
    'bot_update',
  )
  await shownByTestId(page, `hilos-change-log-open-${rowKey}`).click()
  await expectChangeLogReceiptOpen(expect, page, receiptId)
  await expectChangeLogValues(expect, page, 'description', before, after)
  const detail = page.getByTestId('hilos-change-log-detail')
  const oldValue = detail.getByTestId(
    /^hilos-change-log-value-\d+-description-old$/,
  )
  const newValue = detail.getByTestId(
    /^hilos-change-log-value-\d+-description-new$/,
  )
  expect(await oldValue.textContent()).toBe(before)
  expect(await newValue.textContent()).toBe(after)

  const record = detail.getByTestId(/^hilos-change-log-receipt-record-\d+$/)
  await expect(record).toHaveText(`bot #${botKey}`)
  await record.click()
  await expect(page.getByTestId('modal')).toHaveCount(0)
  await expectTrackedTableRecord(expect, page, 'bot', botKey)
  const editKey = await tableRowKeyByText(expect, page, 'updated')
  const edit = shownByTestId(page, `hilos-table-row-${editKey}`)
  await expect(edit).toContainText(`#${botKey}`)
  await expect(edit).toContainText('description: Value omitted')
  await expect(edit).toContainText('Web')
})
