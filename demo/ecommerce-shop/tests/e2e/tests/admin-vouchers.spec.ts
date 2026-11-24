import { randomBytes } from 'node:crypto'

import { test as base, expect } from '../test.js'
import { moneyFieldLabel } from '../../../frontend/src/money/money.js'
import { PAGE_ADMIN_PRODUCT_STOCK } from '../../../frontend/src/pages/keys.js'

import {
  PAGE_READY,
  ROWS,
  cleanupFixtures,
  clickSubmit,
  dismissToasts,
  drawPhotoFixture,
  expectDocumentUnchanged,
  expectPageReady,
  gotoPage,
  grantAdminToSelf,
  markDocument,
  sessionToken,
  typeInto,
  type TestCleanup,
} from '../../../../../framework/frontend/e2e/index.js'
import { createStandCommandChannel } from '../../../../../framework/frontend/scripts/standCommand.mjs'

// spec-owner: demo — the shop's Vouchers page and live voucher discounts in guest carts.

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })

// Browser traces can include the guest's session cookie.
test.use({ colorScheme: 'light', trace: 'off' })

const VOUCHERS_PATH = '/hilos/vouchers'
const VOUCHER_GET_COMMAND = 'test:ecommerce-shop:voucher-fixture-get'
const VOUCHER_DELETE_COMMAND = 'test:ecommerce-shop:voucher-fixture-delete'
const command = createStandCommandChannel({ timeoutMs: 5_000 })

test('shop.vouchers-add: an admin corrects a refused discount, adds and edits a voucher live and gets a duplicate-code refusal', async ({
  page,
  cleanup,
}) => {
  const code = `ZZE2EV${randomBytes(12).toString('hex').toUpperCase()}`
  const renamedCode = `ZZE2EV${randomBytes(12).toString('hex').toUpperCase()}`
  const enteredCode = code.toLowerCase()
  for (const ownedCode of [code, renamedCode]) {
    expect(await command(VOUCHER_GET_COMMAND, { code: ownedCode })).toEqual({
      voucher: null,
    })
    cleanup.defer(async () => {
      expect(await command(VOUCHER_GET_COMMAND, { code: ownedCode })).toEqual({
        voucher: null,
      })
    })
  }
  // LIFO deletes the original first, so a queued Save cannot leave a renamed row.
  for (const ownedCode of [renamedCode, code]) {
    cleanup.defer(() => command(VOUCHER_DELETE_COMMAND, { code: ownedCode }))
  }

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
  const itemsAmount = '₴1,200'
  const discountedTotal = '₴900'
  const deduction = '−₴300'
  const setCartQuantity = 'test:ecommerce-shop:guest-cart-set-quantity'
  const setCartVoucher = 'test:ecommerce-shop:guest-cart-set-voucher'

  const guestContext = await browser.newContext()
  cleanup.defer(() => guestContext.close())
  const guest = await guestContext.newPage()

  await test.step('create the active voucher', async () => {
    expect(await command(VOUCHER_GET_COMMAND, { code })).toEqual({
      voucher: null,
    })
    cleanup.defer(() => command(VOUCHER_DELETE_COMMAND, { code }))
    const voucher = { code, discountPercent, active: true }
    expect(
      await command('test:ecommerce-shop:voucher-fixture-create', voucher),
    ).toMatchObject({ voucher })
  })

  await grantAdminToSelf(expect, page)
  const productId =
    await test.step('create a stock item priced at ₴400', async () => {
      cleanup.defer(() =>
        command('test:ecommerce-shop:product-delete', { name }),
      )
      await gotoPage(expect, page, '/hilos/products', PAGE_READY)
      const products = page.getByTestId('admin-products-table')
      await products.getByTestId('hilos-table-main-action').click()

      const dialog = page.getByRole('dialog', {
        name: 'Add product',
        exact: true,
      })
      await typeInto(
        dialog.getByRole('textbox', { name: 'Name', exact: true }),
        name,
      )
      await dialog
        .getByRole('combobox', { name: 'Category' })
        .selectOption({ label: 'Pots & planters' })
      await dialog
        .getByRole('radio', { name: 'Unique piece' })
        .press('ArrowRight')
      await expect(
        dialog.getByRole('radio', { name: 'Stock item' }),
      ).toBeChecked()
      await typeInto(
        dialog.getByRole('textbox', { name: moneyFieldLabel('Price') }),
        '400',
      )
      await typeInto(
        dialog.getByRole('textbox', { name: 'Description' }),
        'A ceramic planter carried in stock.',
      )

      const [chooser] = await Promise.all([
        page.waitForEvent('filechooser'),
        dialog.getByTestId('admin-products-add-picture').click(),
      ])
      await chooser.setFiles({
        name: 'planter.png',
        mimeType: 'image/png',
        buffer: await drawPhotoFixture(page, {
          width: 320,
          height: 240,
          mimeType: 'image/png',
          rectangles: [
            { color: '#246ac4', x: 0, y: 0, width: 320, height: 240 },
          ],
        }),
      })
      await expect(
        dialog.getByTestId('admin-products-add-preview'),
      ).toBeVisible()
      await clickSubmit(expect, dialog.getByTestId('admin-products-add-submit'))
      await expect(dialog).toBeHidden()
      await products.getByRole('link', { name, exact: true }).click()
      await expectPageReady(expect, page)
      await expect(page).toHaveURL(/\/hilos\/product\/[1-9]\d*$/)

      return Number(new URL(page.url()).pathname.split('/').at(-1))
    })

  await test.step('set stock for the cart item', async () => {
    await page.getByTestId(`hilos-page-tab-${PAGE_ADMIN_PRODUCT_STOCK}`).click()
    await expectPageReady(expect, page)
    const stockPath = new URL(page.url()).pathname
    const warehouseRow = page
      .getByTestId('admin-product-stock-table')
      .getByRole('row')
      .filter({
        has: page.getByRole('cell', { name: 'Odesa · Peresyp', exact: true }),
      })
    const setStock = async (units: string) => {
      const dialog = page.getByRole('dialog', {
        name: 'Set stock',
        exact: true,
      })
      await warehouseRow.getByTestId('admin-product-stock-edit-open').click()
      await typeInto(
        dialog.getByTestId('admin-product-stock-edit-units'),
        units,
      )
      await clickSubmit(
        expect,
        dialog.getByTestId('admin-product-stock-edit-submit'),
      )
      await expect(dialog).toBeHidden()
      await expect(
        warehouseRow.getByRole('cell', { name: units, exact: true }),
      ).toBeVisible()
    }

    const zero = warehouseRow.getByRole('cell', { name: '0', exact: true })
    await expect(zero).toBeVisible()
    cleanup.defer(async () => {
      await gotoPage(expect, page, stockPath, PAGE_READY)
      await expect(warehouseRow).toBeVisible()
      if (!(await zero.isVisible())) await setStock('0')
    })
    await setStock('8')
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

    const token = await sessionToken(guest)
    cleanup.defer(() =>
      command(setCartQuantity, {
        sessionToken: token,
        productId,
        quantity: 0,
      }),
    )
    await command(setCartQuantity, {
      sessionToken: token,
      productId,
      quantity: 3,
    })
    cleanup.defer(async () => {
      expect(
        await command(setCartVoucher, {
          sessionToken: token,
          code,
          applied: false,
        }),
      ).toEqual({ applied: false })
    })
    expect(
      await command(setCartVoucher, {
        sessionToken: token,
        code,
        applied: true,
      }),
    ).toEqual({ applied: true })

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
