import { randomBytes } from 'node:crypto'

import { test as base, expect } from '../test.js'
import {
  createStockProduct,
  deferCatalogProductDelete,
  setStock,
  warehouseRow,
} from '../helpers/catalogProducts.js'
import { submitCartVoucher } from '../helpers/cartVoucher.js'
import { bindGuestCartItem } from '../helpers/guestCart.js'
import {
  createVoucherFixture,
  deferGuestCartVoucherClear,
} from '../helpers/voucherFixtures.js'
import { PAGE_ADMIN_PRODUCT_STOCK } from '../../../frontend/src/pages/keys.js'

import {
  PAGE_READY,
  cleanupFixtures,
  expectDocumentUnchanged,
  expectPageReady,
  gotoPage,
  grantAdminToSelf,
  markDocument,
  timeZoneContextOptions,
  type TestCleanup,
} from '../../../../../framework/frontend/e2e/index.js'

// spec-owner: demo — the shop's guest cart applies, refuses, replaces, and removes a voucher.

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })

// Browser traces can include the guest's session cookie.
test.use({ colorScheme: 'light', trace: 'off' })

test('a guest applies a mixed-case voucher, keeps it through refusals, replaces it, and removes it', async ({
  browser,
  page,
  cleanup,
}) => {
  const name = `e2e product ${randomBytes(6).toString('hex')}`
  const voucherPrefix = `ZZE2EV${randomBytes(11).toString('hex').toUpperCase()}`
  const firstCode = `${voucherPrefix}01`
  const replacementCode = `${voucherPrefix}02`
  const inactiveCode = `${voucherPrefix}03`
  const unknownCode = `${voucherPrefix}04`
  const itemsAmount = '₴1,230'
  const firstDeduction = '−₴123'
  const firstTotal = '₴1,107'
  const replacementDeduction = '−₴246'
  const replacementTotal = '₴984'

  // cleanupFixtures runs LIFO. Register the context, product, and stock
  // before the vouchers, and the cart line before the voucher clear, so
  // teardown clears the voucher and the line first.
  const guestContext = await browser.newContext(
    timeZoneContextOptions(test.info().project.use, 'Africa/Mogadishu'),
  )
  cleanup.defer(() => guestContext.close())
  const guest = await guestContext.newPage()

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

  await test.step('create the vouchers', async () => {
    await createVoucherFixture(expect, cleanup, {
      code: firstCode,
      discountPercent: 10,
      active: true,
    })
    await createVoucherFixture(expect, cleanup, {
      code: replacementCode,
      discountPercent: 20,
      active: true,
    })
    await createVoucherFixture(expect, cleanup, {
      code: inactiveCode,
      discountPercent: 25,
      active: false,
    })
  })

  const line = guest.getByTestId('shop-cart-line')
  const items = guest
    .getByText('Items', { exact: true })
    .locator('..')
    .locator('dd')
  const total = guest.getByTestId('shop-cart-total')
  const openVoucherForm = guest.getByTestId('cart-voucher-apply-open')
  const voucherForm = guest.getByTestId('cart-voucher-apply')
  const voucherCode = voucherForm.getByTestId('cart-voucher-apply-code')
  const voucherError = voucherForm.getByTestId('cart-voucher-apply-error')
  const voucherRow = guest.getByTestId('shop-cart-voucher')
  const expectVoucher = async (
    code: string,
    deduction: string,
    cartTotal: string,
  ) => {
    await expect(voucherRow.locator('dt')).toHaveText(`Voucher ${code}`)
    await expect(voucherRow.locator('dd')).toHaveText(deduction)
    await expect(items).toHaveText(itemsAmount)
    await expect(total).toHaveText(cartTotal)
    await expectDocumentUnchanged(expect, guest)
  }

  await test.step('add three units to the open guest cart', async () => {
    await gotoPage(expect, guest, '/cart', PAGE_READY)
    await expect(guest.getByTestId('shop-cart-empty')).toBeVisible()
    await markDocument(guest)

    const setQuantity = await bindGuestCartItem(cleanup, guest, productId)
    await setQuantity(3)
    await deferGuestCartVoucherClear(
      expect,
      cleanup,
      guest,
      replacementCode,
      firstCode,
    )

    await expect(line.getByTestId('shop-cart-product-link')).toHaveText(name)
    await expect(line.getByText('Quantity: 3', { exact: true })).toBeVisible()
    await expect(items).toHaveText(itemsAmount)
    await expect(total).toHaveText(itemsAmount)
    await expectDocumentUnchanged(expect, guest)
  })

  await test.step('apply a mixed-case voucher and see the discounted total', async () => {
    await openVoucherForm.click()
    await submitCartVoucher(expect, guest, `z${firstCode.slice(1)}`)
    await expect(voucherForm).toBeHidden()
    await expectVoucher(firstCode, firstDeduction, firstTotal)
  })

  await test.step('keep the applied voucher when another code is refused', async () => {
    await openVoucherForm.click()
    for (const { code, reason } of [
      { code: unknownCode, reason: 'Voucher code was not found' },
      { code: inactiveCode, reason: 'This voucher is inactive' },
    ]) {
      await submitCartVoucher(expect, guest, code)
      await expect(
        voucherError.getByText(reason, { exact: true }),
      ).toBeVisible()
      await expect(voucherCode).toHaveValue(code)
      await expectVoucher(firstCode, firstDeduction, firstTotal)
    }
  })

  await test.step('replace the voucher and see the new total in the same cart', async () => {
    // The refused code is still in the open form.
    await submitCartVoucher(expect, guest, replacementCode)
    await expect(voucherForm).toBeHidden()
    await expectVoucher(replacementCode, replacementDeduction, replacementTotal)
    await expect(voucherRow).not.toContainText(firstCode)
  })

  await test.step('remove the voucher and see the undiscounted total in the same cart', async () => {
    await guest.getByTestId('cart-voucher-remove').click()
    await expect(voucherRow).toHaveCount(0)
    await expect(items).toHaveText(itemsAmount)
    await expect(total).toHaveText(itemsAmount)
    await expect(openVoucherForm).toBeVisible()
    await expect(openVoucherForm).toBeEnabled()
    await expect(openVoucherForm).toHaveText('Apply voucher')
    await expectDocumentUnchanged(expect, guest)
  })
})
