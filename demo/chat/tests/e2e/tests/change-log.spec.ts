import { expect, test } from '@playwright/test'

import {
  dismissToasts,
  shownByTestId,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { gotoPage } from '../helpers/page.js'
import { clickSubmit, typeInto } from '../helpers/session.js'
import { tableRowKeyByText } from '../helpers/table.js'

test('opens Change Log from the admin dashboard with its overview and feed', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos')

  const card = page.getByTestId('dashboard-card-hilos_change_log')
  await expect(card).toBeVisible()
  await expect(card).toContainText(
    'Who changed what in the tracked tables, and through which action.',
  )
  await card.click()

  await expect(page.getByTestId('hilos-admin-title')).toHaveText('Change Log')
  await expect(page.getByTestId('hilos-admin-page')).toContainText(
    'Who changed what in the tracked tables, and through which action.',
  )
  await expect(page.getByTestId('hilos-change-log-tile-journal')).toHaveText(
    /[1-9]/,
  )
  await expect(page.getByTestId('hilos-change-log-tile-tracked')).toHaveText(
    /\d+ of \d+/,
  )
  await expect(page.getByTestId('hilos-table-title')).toHaveText(
    'Recent actions',
  )
  await expect(
    page.locator('table [data-id^="hilos-table-row-"]').first(),
  ).toBeVisible()
})

test('opens its own bot update receipt with full values and returns keyboard focus on a narrow screen', async ({
  page,
}) => {
  const userId = await signUpAdmin(page)
  const name = `AAA journal ${Date.now()}`
  // The bot form trims boundary whitespace before saving. Keep the inputs in
  // that saved form so the journal assertions below remain byte-for-byte.
  const before =
    'Before: a saved description.\n' +
    'The full old value stays in the journal. '.repeat(6).trimEnd()
  const after =
    'After: the complete new description.\n' +
    'The receipt renders every word and line. '.repeat(6).trimEnd()
  await gotoPage(page, '/hilos/app/bots')
  await page.getByTestId('hilos-table-main-action').click()
  await typeInto(page.getByTestId('admin-bots-name'), name)
  await typeInto(page.getByTestId('admin-bots-description'), before)
  await page.getByTestId('admin-bots-active').uncheck()
  await clickSubmit(page.getByTestId('admin-bots-save'))
  await expect(page.getByTestId('admin-bots-save')).toHaveCount(0)
  const botKey = await tableRowKeyByText(page, name)
  await dismissToasts(page)
  await shownByTestId(page, `admin-bots-edit-${botKey}`).click()
  await typeInto(page.getByTestId('admin-bots-description'), after)
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
  const rowKey = await tableRowKeyByText(page, `bot #${botKey} · updated`)
  const row = shownByTestId(page, `hilos-table-row-${rowKey}`)
  await expect(row).toContainText('bot_update')
  expect(rowKey).toMatch(/^receipt:\d+$/)
  const receiptId = rowKey.slice('receipt:'.length)

  await page.setViewportSize({ width: 375, height: 812 })
  const opener = shownByTestId(page, `hilos-change-log-open-${rowKey}`)
  await expect(opener).toHaveAccessibleName(`View receipt #${receiptId}`)
  await opener.focus()
  await page.keyboard.press('Enter')
  const modal = page.getByTestId('modal')
  await expect(modal).toHaveAccessibleName(`Receipt #${receiptId}`)
  await expect(modal).toBeFocused()
  const detail = page.getByTestId('hilos-change-log-detail')
  await expect(detail).toContainText(`bot #${botKey}`)
  await expect(detail).toContainText('bot_update')
  const oldValue = detail.getByTestId(
    /^hilos-change-log-value-\d+-description-old$/,
  )
  const newValue = detail.getByTestId(
    /^hilos-change-log-value-\d+-description-new$/,
  )
  await expect(oldValue).toHaveText(before)
  await expect(newValue).toHaveText(after)
  expect(await oldValue.textContent()).toBe(before)
  expect(await newValue.textContent()).toBe(after)
  // Bootstrap row gutters reach into the body's padding. Measure the body
  // that owns scrolling, rather than the unpadded content wrapper inside it.
  expect(
    await modal
      .locator('.modal-body')
      .evaluate((node) => node.scrollWidth - node.clientWidth),
  ).toBe(0)
  await page.keyboard.press('Escape')
  await expect(modal).toHaveCount(0)
  await expect(opener).toBeFocused()
})

test('its registration receipt shows person and identity creation as facts without personal values', async ({
  page,
}) => {
  const userId = await signUpAdmin(page)
  await gotoPage(page, '/hilos/change-log')
  const rowKey = await tableRowKeyByText(
    page,
    `hilos_user #${userId} · created`,
  )
  await expect(shownByTestId(page, `hilos-table-row-${rowKey}`)).toContainText(
    'hilos_complete_registration',
  )
  await shownByTestId(page, `hilos-change-log-open-${rowKey}`).click()
  const detail = page.getByTestId('hilos-change-log-detail')
  await expect(detail).toContainText(`hilos_user #${userId}`)
  await expect(detail).toContainText('hilos_identity #')
  const creations = detail.getByTestId(/^hilos-change-log-entry-\d+$/)
  await expect(creations).toHaveCount(2)
  for (const creation of await creations.all()) {
    await expect(creation).toContainText(
      'Created as a fact; values belong to the record itself.',
    )
    await expect(creation.getByTestId(/^hilos-change-log-value-/)).toHaveCount(
      0,
    )
    await expect(creation).not.toContainText('@example.test')
  }
  await page.getByTestId('hilos-change-log-detail-close').click()
  await expect(page.getByTestId('modal')).toHaveCount(0)
})
