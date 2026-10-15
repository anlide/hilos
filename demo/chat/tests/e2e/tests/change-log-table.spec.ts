import { expect, test } from '@playwright/test'

import {
  dismissToasts,
  shownByTestId,
  sidewaysOverflow,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { expectPageReady, gotoPage } from '../helpers/page.js'
import { clickSubmit, typeInto } from '../helpers/session.js'
import { tableRowKeyByText } from '../helpers/table.js'

test('follows a bot update receipt to the history of its record and back to the whole table', async ({
  page,
}) => {
  const userId = await signUpAdmin(page)
  const name = `AAA tracked ${Date.now()}`
  await gotoPage(page, '/hilos/app/bots')
  await page.getByTestId('hilos-table-main-action').click()
  await typeInto(page.getByTestId('admin-bots-name'), name)
  await typeInto(page.getByTestId('admin-bots-description'), 'Before.')
  await clickSubmit(page.getByTestId('admin-bots-save'))
  await expect(page.getByTestId('admin-bots-save')).toHaveCount(0)
  const botKey = await tableRowKeyByText(page, name)
  await dismissToasts(page)
  await shownByTestId(page, `admin-bots-edit-${botKey}`).click()
  await typeInto(page.getByTestId('admin-bots-description'), 'After.')
  await clickSubmit(page.getByTestId('admin-bots-save'))
  await expect(page.getByTestId('admin-bots-save')).toHaveCount(0)
  await dismissToasts(page)

  await gotoPage(page, '/hilos/change-log')
  const filter = page.getByTestId('hilos-table-filter-table')
  await filter.getByTestId('hilos-dropdown-toggle').click()
  // The overview supplies alphabetized live journaled names; bot is its first.
  const botOption = filter.getByTestId('hilos-dropdown-option-0')
  await expect(botOption).toHaveText('bot')
  await botOption.click()
  await typeInto(page.getByTestId('hilos-table-search'), `#${userId}`)
  const feedKey = await tableRowKeyByText(page, `bot #${botKey} · updated`)
  expect(feedKey).toMatch(/^receipt:\d+$/)
  const receiptId = feedKey.slice('receipt:'.length)
  await shownByTestId(page, `hilos-change-log-open-${feedKey}`).click()
  const modal = page.getByTestId('modal')
  await expect(modal).toHaveAccessibleName(`Receipt #${receiptId}`)
  const record = page
    .getByTestId('hilos-change-log-detail')
    .getByTestId(/^hilos-change-log-receipt-record-\d+$/)
  await expect(record).toHaveText(`bot #${botKey}`)
  await record.click()

  await expect(modal).toHaveCount(0)
  await expect(page).toHaveURL(
    new RegExp(`/hilos/change-log/tables/bot/${botKey}$`),
  )
  await expectPageReady(page)
  await expect(page.getByTestId('hilos-admin-title')).toHaveText(
    'Tracked table',
  )
  await expect(page.getByTestId('hilos-change-log-table-header')).toContainText(
    'bot',
  )
  await expect(
    page.getByTestId('hilos-change-log-record-filter'),
  ).toContainText(`Only record #${botKey}`)
  const editKey = await tableRowKeyByText(page, 'updated')
  const edit = shownByTestId(page, `hilos-table-row-${editKey}`)
  await expect(edit).toContainText(`#${botKey}`)
  await expect(edit).toContainText('description: Value omitted')
  await expect(edit).toContainText('Web')

  await shownByTestId(
    page,
    `hilos-change-log-history-receipt-${editKey}`,
  ).click()
  await expect(modal).toHaveAccessibleName(`Receipt #${receiptId}`)
  await expect(page.getByTestId('hilos-change-log-detail')).toContainText(
    'bot_update',
  )
  await page.getByTestId('hilos-change-log-detail-close').click()
  await expect(modal).toHaveCount(0)

  await page.getByTestId('hilos-change-log-record-filter-clear').click()
  await expect(page).toHaveURL(/\/hilos\/change-log\/tables\/bot$/)
  await expectPageReady(page)
  await expect(page.getByTestId('hilos-change-log-record-filter')).toHaveCount(
    0,
  )
  await expect(page.getByTestId('hilos-viewport-table')).toContainText(
    'All records of this table',
  )

  await page.setViewportSize({ width: 375, height: 812 })
  await expect(
    page.getByRole('table', { name: 'Columns', exact: true }),
  ).toBeHidden()
  const columns = page.getByRole('list', { name: 'Columns', exact: true })
  await expect(columns).toBeVisible()
  await expect(columns).toContainText('description')
  await expect(columns).toContainText('Long text')
  expect(await sidewaysOverflow(page)).toEqual([0, 0])
})
