import { test, expect, type Page } from '@playwright/test'

import { signUpAdmin } from '../helpers/adminGrant'
import { gotoPage } from '../helpers/page'
import { clickSubmit, typeInto } from '../helpers/session'
import { goToLastPage } from '../helpers/table'

// spec-owner: demo — chat's own Users screen

// The chat's own "Users" screen (/hilos/app/users, the chat table adminUsers) — an
// admin screen of this demo, not the framework's people pages, so its spec stays
// here beside bots.spec and moderator.spec, which also follow their screens. The
// framework's people list and card are carried by binance-btc-tracker's
// users.spec.ts.

// HIL-1051: the chat admin's rename modal over the users table merges against
// the live row, the way the framework card's rename modal does (HIL-1050). Tab A holds the modal open on the row of the user
// this test registers itself; tab B renames that user from the card. A pristine
// modal follows and says so, a typed one conflicts and locks Save, and Keep mine
// sends the draft over the other tab's name. The table orders by id, so the
// rename moves the row nowhere and the modal keeps its live row.
test('an open admin rename follows the other tab, then conflicts and keeps mine', async ({
  page,
}) => {
  const userId = await signUpAdmin(page)
  const tabB = await page.context().newPage()
  await gotoPage(page, '/hilos/app/users')
  await gotoPage(tabB, `/hilos/user/${userId}`)
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(tabB.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()
  await expect(tabB.getByTestId('hilos-user-detail')).toBeVisible()

  // The user registered a moment ago has the highest id, so its row is on the
  // last page of the window.
  await goToLastPage(page)
  await page.getByTestId(`admin-users-edit-${userId}`).click()
  await expect(page.getByTestId('admin-users-name')).toBeVisible()
  await expect(page.getByTestId('admin-users-save')).toBeDisabled()
  await renameUser(tabB, 'E2E Elsewhere One')

  // A pristine modal follows the live row and says so on its message line.
  await expect(page.getByTestId('admin-users-name')).toHaveValue(
    'E2E Elsewhere One',
  )
  await expect(page.getByTestId('admin-users-edit-notice')).toContainText(
    'Updated just now',
  )
  await expect(page.getByTestId('conflict-badge')).toHaveCount(0)
  await expect(page.getByTestId('admin-users-save')).toBeDisabled()

  // Tab A types; tab B renames again: a conflict, Save locked, no Merge.
  await typeInto(page.getByTestId('admin-users-name'), 'E2E Mine')
  await renameUser(tabB, 'E2E Elsewhere Two')
  await expect(page.getByTestId('conflict-badge')).toBeVisible()
  await expect(page.getByTestId('admin-users-edit-notice')).toContainText(
    'Changed elsewhere to "E2E Elsewhere Two"',
  )
  await expect(page.getByTestId('conflict-merge')).toHaveCount(0)
  await expect(page.getByTestId('admin-users-save')).toBeDisabled()

  // Keep mine ends the conflict with the draft in place: Save opens, sends it,
  // and the card in tab B shows the name tab A kept.
  await page.getByTestId('conflict-accept-mine').click()
  await expect(page.getByTestId('conflict-badge')).toHaveCount(0)
  await clickSubmit(page.getByTestId('admin-users-save'))
  await expect(page.getByTestId('admin-users-name')).toHaveCount(0)
  await expect(tabB.getByTestId('hilos-user-name')).toHaveText('E2E Mine')
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
  await clickSubmit(tab.getByTestId('hilos-user-save'))
  await expect(tab.getByTestId('hilos-user-name-input')).toHaveCount(0)
  await expect(tab.getByTestId('hilos-user-name')).toHaveText(name)
}
