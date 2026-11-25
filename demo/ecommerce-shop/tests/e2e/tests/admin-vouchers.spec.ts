import { randomBytes } from 'node:crypto'

import { test as base, expect } from '../test.js'
import {
  createStockProduct,
  deferCatalogProductDelete,
  setStock,
  warehouseRow,
} from '../helpers/catalogProducts.js'
import { bindGuestCartItem } from '../helpers/guestCart.js'
import {
  applyGuestCartVoucher,
  createVoucherFixture,
  reserveVoucherCodes,
} from '../helpers/voucherFixtures.js'
import { PAGE_ADMIN_PRODUCT_STOCK } from '../../../frontend/src/pages/keys.js'

import {
  PAGE_READY,
  ROWS,
  cleanupFixtures,
  clickSubmit,
  dismissToasts,
  expectDocumentUnchanged,
  expectPageReady,
  gotoPage,
  grantAdminToSelf,
  markDocument,
  timeZoneContextOptions,
  typeInto,
  type TestCleanup,
} from '../../../../../framework/frontend/e2e/index.js'

// spec-owner: demo — the shop's Vouchers page and live voucher discounts in guest carts.

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })

// Browser traces can include the guest's session cookie.
test.use({ colorScheme: 'light', trace: 'off' })

const VOUCHERS_PATH = '/hilos/vouchers'

test('shop.vouchers-add: an admin corrects a refused discount, adds and edits a voucher live and gets a duplicate-code refusal', async ({
  page,
  cleanup,
}) => {
  const code = `ZZE2EV${randomBytes(12).toString('hex').toUpperCase()}`
  const renamedCode = `ZZE2EV${randomBytes(12).toString('hex').toUpperCase()}`
  const enteredCode = code.toLowerCase()
  await reserveVoucherCodes(expect, cleanup, code, renamedCode)

  await grantAdminToSelf(expect, page)
  await gotoPage(expect, page, VOUCHERS_PATH)
  await markDocument(page)

  const open = page.getByTestId('hilos-table-main-action')
  const dialog = page.getByTestId('admin-vouchers-add')
  const codeField = dialog.getByTestId('admin-vouchers-add-code')
  const discount = dialog.getByTestId('admin-vouchers-add-discount-percent')
  const active = dialog.getByTestId('admin-vouchers-add-active')
  const error = dialog.getByTestId('admin-vouchers-add-error')
  const submit = page.getByTestId('admin-vouchers-add-submit')
  const rows = page.getByTestId('admin-vouchers-table').locator(ROWS)
  const addedRow = rows.filter({ hasText: code })
  const editedRow = rows.filter({ hasText: renamedCode })

  await test.step('the server refuses 101 percent and the dialog keeps the draft', async () => {
    await open.click()
    await expect(active).toBeChecked()
    await typeInto(codeField, enteredCode)
    await typeInto(discount, '101')
    await active.uncheck()
    await clickSubmit(expect, submit)

    await expect(error).toContainText(
      'Discount must be a whole percent from 1 to 100',
    )
    await expect(dialog).toBeVisible()
    await expect(codeField).toHaveValue(enteredCode)
    await expect(discount).toHaveValue('101')
    await expect(active).not.toBeChecked()
  })

  await test.step('correcting the discount adds one normalized row without reloading', async () => {
    await typeInto(discount, '20')
    await clickSubmit(expect, submit)

    await expect(dialog).toBeHidden()
    await expect(addedRow).toHaveCount(1)
    await expect(addedRow.getByText(code, { exact: true })).toBeVisible()
    await expect(addedRow.getByText('20%', { exact: true })).toBeVisible()
    await expectDocumentUnchanged(expect, page)
  })

  await test.step('editing the voucher replaces its row without reloading', async () => {
    await dismissToasts(page)
    await addedRow.getByTestId('admin-vouchers-edit-open').click()
    const edit = page.getByTestId('admin-vouchers-edit')
    const editCode = edit.getByTestId('admin-vouchers-edit-code')
    const editDiscount = edit.getByTestId(
      'admin-vouchers-edit-discount-percent',
    )
    const editActive = edit.getByTestId('admin-vouchers-edit-active')

    await expect(editCode).toHaveValue(code)
    await expect(editDiscount).toHaveValue('20')
    await expect(editActive).not.toBeChecked()
    await typeInto(editCode, renamedCode)
    await typeInto(editDiscount, '35')
    await editActive.check()
    await clickSubmit(expect, page.getByTestId('admin-vouchers-edit-submit'))

    await expect(edit).toBeHidden()
    await expect(editedRow).toHaveCount(1)
    await expect(
      editedRow.getByText(renamedCode, { exact: true }),
    ).toBeVisible()
    await expect(editedRow.getByText('35%', { exact: true })).toBeVisible()
    const activeToggle = editedRow.getByTestId('admin-vouchers-active-toggle')
    await expect(activeToggle).toBeVisible()
    await expect(activeToggle).toBeChecked()
    await expect(addedRow).toHaveCount(0)
    await expectDocumentUnchanged(expect, page)
  })

  await test.step('the server refuses the same code in another case and keeps the draft', async () => {
    await dismissToasts(page)
    await open.click()
    const duplicateCode = renamedCode.toLowerCase()
    await typeInto(codeField, duplicateCode)
    await typeInto(discount, '25')
    await active.uncheck()
    await clickSubmit(expect, submit)

    await expect(error).toContainText('A voucher with this code already exists')
    await expect(dialog).toBeVisible()
    await expect(codeField).toHaveValue(duplicateCode)
    await expect(discount).toHaveValue('25')
    await expect(active).not.toBeChecked()
    await expect(editedRow).toHaveCount(1)
    await expect(editedRow.getByText('35%', { exact: true })).toBeVisible()
  })
})

test('shop.vouchers-toggle: an admin switches a discount off and back on in an open guest cart', async ({
  browser,
  page,
  cleanup,
}) => {
  const code = `ZZE2EV${randomBytes(12).toString('hex').toUpperCase()}`
  const name = `e2e product ${randomBytes(6).toString('hex')}`
  const discountPercent = 25
  const itemsAmount = '₴1,230'
  const discountedTotal = '₴922.50'
  const deduction = '−₴307.50'

  const guestContext = await browser.newContext(
    timeZoneContextOptions(test.info().project.use, 'Africa/Mogadishu'),
  )
  cleanup.defer(() => guestContext.close())
  const guest = await guestContext.newPage()

  await test.step('create the active voucher', () =>
    createVoucherFixture(expect, cleanup, {
      code,
      discountPercent,
      active: true,
    }))

  deferCatalogProductDelete(cleanup, name)
  await grantAdminToSelf(expect, page)

  const { productId } =
    await test.step('create a stock item with a picture', () =>
      createStockProduct(
        expect,
        page,
        name,
        'Pots & planters',
        'A ceramic planter carried in stock.',
      ))

  await test.step('set stock for the cart item', async () => {
    await page.getByTestId(`hilos-page-tab-${PAGE_ADMIN_PRODUCT_STOCK}`).click()
    await expectPageReady(expect, page)
    const stockPath = new URL(page.url()).pathname
    const row = warehouseRow(page)
    const zero = row.getByRole('cell', { name: '0', exact: true })
    await expect(zero).toBeVisible()
    cleanup.defer(async () => {
      await gotoPage(expect, page, stockPath, PAGE_READY)
      await expect(row).toBeVisible()
      if (!(await zero.isVisible())) await setStock(expect, page, '0')
    })
    await setStock(expect, page, '8')
  })

  const items = guest
    .getByText('Items', { exact: true })
    .locator('..')
    .locator('dd')
  const voucherRow = guest.getByTestId('shop-cart-voucher')
  const voucherLabel = voucherRow.locator('dt')
  const voucherAmount = voucherRow.locator('dd')
  const total = guest.getByTestId('shop-cart-total')

  await test.step('apply the discount to three units in the open guest cart', async () => {
    await gotoPage(expect, guest, '/cart', PAGE_READY)
    await expect(guest.getByTestId('shop-cart-empty')).toBeVisible()
    await markDocument(guest)

    const setQuantity = await bindGuestCartItem(cleanup, guest, productId)
    await setQuantity(3)
    await applyGuestCartVoucher(expect, cleanup, guest, code)

    await expect(guest.getByTestId('shop-cart-product-link')).toHaveText(name)
    await expect(voucherLabel).toHaveText(`Voucher ${code}`)
    await expect(voucherAmount).toHaveText(deduction)
    await expect(items).toHaveText(itemsAmount)
    await expect(total).toHaveText(discountedTotal)
    await expectDocumentUnchanged(expect, guest)
  })

  await gotoPage(expect, page, VOUCHERS_PATH, PAGE_READY)
  await markDocument(page)
  const table = page.getByTestId('admin-vouchers-table')
  const row = table
    .locator(ROWS)
    .filter({ has: page.getByText(code, { exact: true }) })
  const activeToggle = row.getByTestId('admin-vouchers-active-toggle')
  await expect(
    table.getByRole('button', { name: 'Active: Any', exact: true }),
  ).toBeVisible()
  await expect(activeToggle).toBeChecked()

  await test.step('switch off the voucher and keep its code in the same cart', async () => {
    await activeToggle.press('Space')
    await expect(activeToggle).not.toHaveAttribute('aria-busy', 'true')
    await expect(activeToggle).toBeEnabled()
    await expect(activeToggle).not.toBeChecked()
    await expect(activeToggle).toBeFocused()
    await expect(row).toBeVisible()
    await expectDocumentUnchanged(expect, page)

    await expect(voucherLabel).toHaveText(`Voucher ${code} · Inactive`)
    await expect(voucherAmount).toHaveText('—')
    await expect(items).toHaveText(itemsAmount)
    await expect(total).toHaveText(itemsAmount)
    await expectDocumentUnchanged(expect, guest)
  })

  await test.step('switch on the voucher and restore the discount in place', async () => {
    await activeToggle.click()
    await expect(activeToggle).toBeEnabled()
    await expect(activeToggle).toBeChecked()
    await expect(row).toBeVisible()
    await expectDocumentUnchanged(expect, page)

    await expect(voucherLabel).toHaveText(`Voucher ${code}`)
    await expect(voucherAmount).toHaveText(deduction)
    await expect(items).toHaveText(itemsAmount)
    await expect(total).toHaveText(discountedTotal)
    await expectDocumentUnchanged(expect, guest)
  })
})
