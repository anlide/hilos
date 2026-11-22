import { randomBytes } from 'node:crypto'

import { test as base, expect } from '../test.js'

import {
  ROWS,
  cleanupFixtures,
  clickSubmit,
  dismissToasts,
  expectDocumentUnchanged,
  gotoPage,
  grantAdminToSelf,
  markDocument,
  typeInto,
  type TestCleanup,
} from '../../../../../framework/frontend/e2e/index.js'
import { createStandCommandChannel } from '../../../../../framework/frontend/scripts/standCommand.mjs'

// spec-owner: demo — proves the shop's own warehouse actions on its Warehouses page.

// The live Warehouses table carries creation, edits and removal between tabs.
// The dialogs preserve a refused draft and explain why a stocked warehouse
// cannot be deleted. The test command cleans up the warehouse this test creates.

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })

const WAREHOUSES_PATH = '/hilos/app/warehouses'

// EcommerceShopCommandConstants::WAREHOUSE_TEST_DELETE, request key `name`.
const WAREHOUSE_DELETE_COMMAND = 'test:ecommerce-shop:warehouse-delete'

const command = createStandCommandChannel({ timeoutMs: 5_000 })

test('shop.warehouses-add: an admin adds, edits and deletes an empty warehouse across two tabs, with refusals for duplicate names and stocked warehouses', async ({
  page,
  cleanup,
}) => {
  // "Zz" puts the row last in the name order, so it lands inside the second
  // tab's window rather than being announced above it.
  const name = `Zz e2e ${randomBytes(6).toString('hex')}`
  cleanup.defer(() => command(WAREHOUSE_DELETE_COMMAND, { name }))
  await grantAdminToSelf(expect, page)
  const second = await page.context().newPage()
  const rowsOf = (tab: typeof page) =>
    tab
      .getByTestId('admin-warehouses-table')
      .locator(ROWS)
      .filter({ hasText: name })

  await test.step('both tabs open the warehouses page', async () => {
    await gotoPage(expect, page, WAREHOUSES_PATH)
    await gotoPage(expect, second, WAREHOUSES_PATH)
    await expect(second.getByTestId('admin-warehouses-table')).toBeVisible()
  })

  await test.step('the first tab adds the warehouse from the dialog', async () => {
    await page.getByTestId('hilos-table-main-action').click()
    const dialog = page.getByTestId('admin-warehouses-add')
    await expect(dialog).toBeVisible()
    await typeInto(dialog.getByTestId('admin-warehouses-add-name'), name)
    await typeInto(
      dialog.getByTestId('admin-warehouses-add-address'),
      '1 Test Street',
    )
    await clickSubmit(expect, page.getByTestId('admin-warehouses-add-submit'))
    await expect(dialog).toBeHidden()
    await expect(rowsOf(page)).toHaveCount(1)
  })

  await test.step('the second tab sees the row arrive without a reload', async () => {
    await expect(rowsOf(second)).toHaveCount(1)
  })

  // Only the address changes: the name keeps the row's place, so the edit
  // lands at once without Apply, and the cleanup finds the row by it.
  await test.step('the first tab edits the address and the second tab shows it', async () => {
    await rowsOf(page).getByTestId('admin-warehouses-edit-open').click()
    const dialog = page.getByTestId('admin-warehouses-edit')
    await expect(dialog).toBeVisible()
    await expect(dialog.getByTestId('admin-warehouses-edit-name')).toHaveValue(
      name,
    )
    await typeInto(
      dialog.getByTestId('admin-warehouses-edit-address'),
      '3 Test Street',
    )
    await clickSubmit(expect, page.getByTestId('admin-warehouses-edit-submit'))
    await expect(dialog).toBeHidden()
    await expect(rowsOf(second)).toContainText('3 Test Street')
  })

  await test.step('the same name again is refused inside the dialog', async () => {
    await dismissToasts(page)
    await page.getByTestId('hilos-table-main-action').click()
    const dialog = page.getByTestId('admin-warehouses-add')
    await expect(dialog).toBeVisible()
    const nameField = dialog.getByTestId('admin-warehouses-add-name')
    await typeInto(nameField, name)
    await typeInto(
      dialog.getByTestId('admin-warehouses-add-address'),
      '2 Test Street',
    )
    await clickSubmit(expect, page.getByTestId('admin-warehouses-add-submit'))
    await expect(
      dialog.getByTestId('admin-warehouses-add-error'),
    ).toContainText('A warehouse with this name already exists')
    await expect(dialog).toBeVisible()
    await expect(nameField).toHaveValue(name)
  })

  await test.step('Cancel asks to discard the typing and closes the dialog', async () => {
    await page.getByTestId('admin-warehouses-add-cancel').click()
    await page.getByTestId('modal-confirm-discard').click()
    await expect(page.getByTestId('admin-warehouses-add')).toBeHidden()
    await expect(rowsOf(page)).toHaveCount(1)
  })

  await test.step('a warehouse with stock cannot open delete confirmation', async () => {
    const stocked = page
      .getByTestId('admin-warehouses-table')
      .locator(ROWS)
      .filter({ hasText: 'Kyiv · Podil' })
    await stocked.getByTestId('admin-warehouses-edit-open').click()
    const edit = page.getByTestId('admin-warehouses-edit')
    const remove = page.getByTestId('admin-warehouses-edit-delete')
    const cancel = page.getByTestId('admin-warehouses-edit-cancel')
    const reason = 'This warehouse keeps stock and cannot be deleted'
    await expect(remove).toBeVisible()
    await expect(remove).toBeDisabled()
    await expect(
      edit.locator('p').and(edit.getByText(reason, { exact: true })),
    ).toBeVisible()
    await expect(remove).toHaveAccessibleDescription(reason)
    await expect(edit.getByTestId('admin-warehouses-edit-name')).toBeFocused()
    await page.keyboard.press('Shift+Tab')
    await page.keyboard.press('Shift+Tab')
    await expect(cancel).toBeFocused()
    await expect(cancel).toHaveAccessibleDescription(reason)
    await expect(
      page.getByTestId('admin-warehouses-delete-confirm'),
    ).toBeHidden()
    await cancel.click()
    await expect(edit).toBeHidden()
  })

  await test.step('Escape returns to the delete opener and keeps the edit draft', async () => {
    const address = page.getByTestId('admin-warehouses-edit-address')
    const remove = page.getByTestId('admin-warehouses-edit-delete')
    await rowsOf(page).getByTestId('admin-warehouses-edit-open').click()
    await typeInto(address, '4 Test Street')
    await remove.press('Enter')
    const confirmation = page.getByRole('dialog', { name: 'Delete warehouse?' })
    await expect(confirmation).toBeFocused()
    await page.keyboard.press('Escape')
    await expect(confirmation).toBeHidden()
    await expect(remove).toBeFocused()
    await expect(address).toHaveValue('4 Test Street')
  })

  await test.step('the first tab deletes the empty warehouse and the second applies its marked removal', async () => {
    const firstTable = page.getByTestId('admin-warehouses-table')
    const secondTable = second.getByTestId('admin-warehouses-table')
    const apply = secondTable.getByTestId('hilos-table-apply')
    const secondAddress = second.getByTestId('admin-warehouses-edit-address')
    const secondConfirmation = second.getByRole('dialog', {
      name: 'Delete warehouse?',
    })
    await expect(apply).toBeHidden()
    await markDocument(page)
    await markDocument(second)

    await rowsOf(second).getByTestId('admin-warehouses-edit-open').click()
    await typeInto(secondAddress, '5 Test Street')
    await second.getByTestId('admin-warehouses-edit-delete').press('Enter')
    await expect(secondConfirmation).toBeFocused()

    await page.getByTestId('admin-warehouses-edit-delete').click()
    const confirmation = page.getByRole('dialog', { name: 'Delete warehouse?' })
    await expect(confirmation.getByText(name, { exact: true })).toBeVisible()
    await clickSubmit(
      expect,
      confirmation.getByTestId('admin-warehouses-delete-confirm'),
    )
    await expect(confirmation).toBeHidden()
    await expect(page.getByTestId('admin-warehouses-edit')).toBeHidden()
    await expect(firstTable.getByText(name, { exact: true })).toHaveCount(0)
    await expect(firstTable.getByTestId('hilos-table-apply')).toBeHidden()
    await expectDocumentUnchanged(expect, page)

    await expect(secondConfirmation).toBeHidden()
    await expect(second.getByTestId('admin-warehouses-edit-name')).toBeFocused()
    await expect(secondAddress).toHaveValue('5 Test Street')
    const save = second.getByTestId('admin-warehouses-edit-submit')
    await expect(save).toHaveText('Deleted')
    await expect(save).toBeDisabled()
    await second.getByTestId('admin-warehouses-edit-cancel').click()
    await second.getByTestId('modal-confirm-discard').click()
    await expect(second.getByTestId('admin-warehouses-edit')).toBeHidden()

    await expect(
      rowsOf(second).getByTestId(/^hilos-table-pending-remove-/),
    ).toBeVisible()
    await expect(apply).toBeVisible()
    await apply.click()
    await expect(apply).toBeHidden()
    await expect(secondTable.getByText(name, { exact: true })).toHaveCount(0)
    await expectDocumentUnchanged(expect, second)
  })
})
