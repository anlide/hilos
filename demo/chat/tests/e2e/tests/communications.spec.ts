import { expect, test, type Page } from '@playwright/test'

import {
  shareOneRow,
  shownByTestId,
  sidewaysOverflow,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant'
import { gotoPage } from '../helpers/page'
import { clickSubmit, typeInto } from '../helpers/session'

test('draws no pager under a single-page declared table', async ({ page }) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/communications')

  await expect(page.getByTestId('hilos-table-count')).toBeVisible()
  await expect(page.getByTestId('hilos-table-prev')).toHaveCount(0)
  await expect(page.getByTestId('hilos-table-next')).toHaveCount(0)
})

// HIL-1050: the channel field's edit modal merges against the live row. Two
// tabs of one context on the sms channel and its `from` field, which no other
// spec reads (the sms helper catches a code by its recipient): a pristine open
// modal follows the other tab's save and says so, a typed one conflicts and
// locks Save, Keep mine sends the typed value to both tabs. The override is
// reset at the end so the field goes back to its env/default.
test('an open channel-field edit follows the other tab, then conflicts and keeps mine', async ({
  page,
}) => {
  await signUpAdmin(page)
  const tabB = await page.context().newPage()
  await gotoPage(page, '/hilos/communications/sms')
  await gotoPage(tabB, '/hilos/communications/sms')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(tabB.getByTestId('conn-state')).toHaveText('connected')
  const row = 'hilos-table-row-notifications.channel.sms.from'
  await expect(page.getByTestId(row)).toBeVisible()
  await expect(tabB.getByTestId(row)).toBeVisible()

  // Start from the env/default: an override a broken earlier attempt left
  // behind would make the first save a no-op. The reset control is live only
  // while an override stands, so its going dark is the reset having landed.
  const reset = shownByTestId(page, 'hilos-channel-field-reset-from')
  if (await reset.isEnabled()) {
    await reset.click()
    await expect(reset).toBeDisabled()
  }

  // Tab A opens the modal and does not type; tab B saves a value.
  await shownByTestId(page, 'hilos-channel-field-edit-from').click()
  await expect(page.getByTestId('hilos-channel-edit-value')).toBeVisible()
  await saveChannelField(tabB, '+15550000001')
  await expect(tabB.getByTestId(row)).toContainText('+15550000001')

  // A pristine modal follows the live row and says so on its message line.
  await expect(page.getByTestId('hilos-channel-edit-value')).toHaveValue(
    '+15550000001',
  )
  await expect(page.getByTestId('hilos-channel-edit-notice')).toContainText(
    'Updated just now',
  )
  await expect(page.getByTestId('conflict-badge')).toHaveCount(0)
  await expect(page.getByTestId('hilos-channel-edit-save')).toBeDisabled()

  // Tab A types; tab B saves something else: a conflict, Save locked.
  await typeInto(page.getByTestId('hilos-channel-edit-value'), '+15550000002')
  await saveChannelField(tabB, '+15550000003')
  await expect(tabB.getByTestId(row)).toContainText('+15550000003')
  await expect(page.getByTestId('conflict-badge')).toBeVisible()
  await expect(page.getByTestId('hilos-channel-edit-notice')).toContainText(
    'Changed elsewhere to "+15550000003"',
  )
  await expect(page.getByTestId('conflict-merge')).toHaveCount(0)
  await expect(page.getByTestId('hilos-channel-edit-save')).toBeDisabled()

  // Keep mine unlocks Save; the typed value lands in both tabs.
  await page.getByTestId('conflict-accept-mine').click()
  await expect(page.getByTestId('conflict-badge')).toHaveCount(0)
  await clickSubmit(page.getByTestId('hilos-channel-edit-save'))
  await expect(page.getByTestId('hilos-channel-edit-value')).toHaveCount(0)
  await expect(page.getByTestId(row)).toContainText('+15550000002')
  await expect(tabB.getByTestId(row)).toContainText('+15550000002')

  // Back to the env/default, the same way.
  await expect(reset).toBeEnabled()
  await reset.click()
  await expect(reset).toBeDisabled()
  await tabB.close()
})

test('a narrow window draws channel field actions in one row and never scrolls sideways', async ({
  page,
}) => {
  await signUpAdmin(page)
  const desktop = page.viewportSize() ?? { width: 1280, height: 720 }
  await page.setViewportSize({ width: 375, height: desktop.height })
  await gotoPage(page, '/hilos/communications/sms')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')

  const card = page
    .getByTestId('hilos-table-cards')
    .getByTestId('hilos-table-card-notifications.channel.sms.from')
  await expect(card).toBeVisible()

  const edit = card.getByTestId('hilos-channel-field-edit-from')
  const reset = card.getByTestId('hilos-channel-field-reset-from')
  await expect(edit).toBeVisible()
  await expect(reset).toBeVisible()

  await shareOneRow(edit, reset)
  expect(await sidewaysOverflow(page)).toEqual([0, 0])
  await page.setViewportSize(desktop)
})

/**
 * Save one value into the sms `from` field through its edit modal, and settle
 * when the modal closes.
 *
 * @param tab The tab on the sms channel page.
 * @param value The value to save.
 */
async function saveChannelField(tab: Page, value: string): Promise<void> {
  await shownByTestId(tab, 'hilos-channel-field-edit-from').click()
  await typeInto(tab.getByTestId('hilos-channel-edit-value'), value)
  await clickSubmit(tab.getByTestId('hilos-channel-edit-save'))
  await expect(tab.getByTestId('hilos-channel-edit-value')).toHaveCount(0)
}
