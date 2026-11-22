import { randomBytes } from 'node:crypto'

import { test as base, expect } from '../test.js'

import {
  ROWS,
  cleanupFixtures,
  clickSubmit,
  dismissToasts,
  gotoPage,
  grantAdminToSelf,
  typeInto,
  type TestCleanup,
} from '../../../../../framework/frontend/e2e/index.js'
import { createStandCommandChannel } from '../../../../../framework/frontend/scripts/standCommand.mjs'

// spec-owner: demo — proves the shop's own warehouse_create and warehouse_update actions on its Warehouses page.

// Warehouses admin e2e: an admin adds a warehouse from the table's main action,
// the row reaches a second tab of the same admin over the live table, the pencil
// in the row opens the edit dialog filled with the row and its saved address
// reaches the second tab too, and a name already taken is refused inside the
// dialog, which keeps what was typed. The test-only command removes the reserved
// warehouse whatever the outcome.

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })

const WAREHOUSES_PATH = '/hilos/app/warehouses'

// EcommerceShopCommandConstants::WAREHOUSE_TEST_DELETE, request key `name`.
const WAREHOUSE_DELETE_COMMAND = 'test:ecommerce-shop:warehouse-delete'

const command = createStandCommandChannel({ timeoutMs: 5_000 })

test('shop.warehouses-add: an admin adds a warehouse, a second tab sees it arrive and sees its edited address, and a taken name is refused in the window', async ({
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
})
