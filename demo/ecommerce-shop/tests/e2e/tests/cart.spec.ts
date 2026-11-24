import { randomBytes } from 'node:crypto'

import { test as base, expect } from '../test.js'
import { moneyFieldLabel } from '../../../frontend/src/money/money.js'
import { PAGE_ADMIN_PRODUCT_STOCK } from '../../../frontend/src/pages/keys.js'
import {
  PAGE_READY,
  cleanupFixtures,
  clickSubmit,
  drawPhotoFixture,
  expectDocumentUnchanged,
  expectPageReady,
  gotoPage,
  grantAdminToSelf,
  markDocument,
  sessionToken,
  type TestCleanup,
  typeInto,
} from '../../../../../framework/frontend/e2e/index.js'
import { createStandCommandChannel } from '../../../../../framework/frontend/scripts/standCommand.mjs'

// spec-owner: demo — the shop's guest cart, product prices, and storefront links.

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })

// Browser traces can include the guest's session cookie.
test.use({ colorScheme: 'light', trace: 'off' })

test('a guest sees live prices in the cart and follows the product link', async ({
  browser,
  page,
  cleanup,
}) => {
  const name = `e2e product ${randomBytes(6).toString('hex')}`
  const command = createStandCommandChannel({ timeoutMs: 5_000 })
  const setCartQuantity = 'test:ecommerce-shop:guest-cart-set-quantity'
  const guestContext = await browser.newContext()
  cleanup.defer(() => guestContext.close())
  const guest = await guestContext.newPage()

  cleanup.defer(() => command('test:ecommerce-shop:product-delete', { name }))
  await grantAdminToSelf(expect, page)

  const productId =
    await test.step('create a stock item with a picture', async () => {
      await gotoPage(expect, page, '/hilos/app/products', PAGE_READY)
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
        '410',
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
      await expect(page).toHaveURL(/\/hilos\/app\/product\/[1-9]\d*$/)

      return Number(new URL(page.url()).pathname.split('/').at(-1))
    })

  await test.step('set stock for the cart item', async () => {
    const overviewPath = new URL(page.url()).pathname
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
    await gotoPage(expect, page, overviewPath, PAGE_READY)
  })

  const line = guest.getByTestId('shop-cart-line')
  const productLink = line.getByTestId('shop-cart-product-link')
  const itemsRow = guest.getByText('Items', { exact: true }).locator('..')
  const expectAmounts = async (price: string, total: string) => {
    await expect(line.getByText(`${price} each`, { exact: true })).toBeVisible()
    await expect(line.getByTestId('shop-cart-line-total')).toHaveText(total)
    await expect(itemsRow.getByText(total, { exact: true })).toBeVisible()
    await expect(guest.getByTestId('shop-cart-total')).toHaveText(total)
  }

  await test.step('add a line to the open guest cart', async () => {
    await gotoPage(expect, guest, '/cart', PAGE_READY)
    await expect(guest.getByTestId('shop-cart-empty')).toBeVisible()
    await markDocument(guest)

    const token = await sessionToken(guest)
    cleanup.defer(() =>
      command(setCartQuantity, { sessionToken: token, productId, quantity: 0 }),
    )
    await command(setCartQuantity, {
      sessionToken: token,
      productId,
      quantity: 3,
    })

    await expect(productLink).toHaveText(name)
    await expect(line.getByText('Quantity: 3', { exact: true })).toBeVisible()
    await expectAmounts('₴410', '₴1,230')
    await expectDocumentUnchanged(expect, guest)
  })

  await test.step('step the quantity up and down in the live cart', async () => {
    const quantityControls = guest.getByRole('group', {
      name: `Quantity of ${name}`,
      exact: true,
    })
    const quantity = line.getByTestId('shop-cart-quantity')

    await quantityControls.getByRole('button', { name: 'One more' }).click()
    await expect(quantity).toHaveText('Quantity: 4')
    await expectAmounts('₴410', '₴1,640')

    await quantityControls.getByRole('button', { name: 'One less' }).click()
    await expect(quantity).toHaveText('Quantity: 3')
    await expectAmounts('₴410', '₴1,230')
    await expectDocumentUnchanged(expect, guest)
  })

  await test.step('raise the price and see the cart recalculate in place', async () => {
    await page.getByTestId('admin-product-edit-open').click()
    const dialog = page.getByRole('dialog', {
      name: 'Edit product',
      exact: true,
    })
    await typeInto(
      dialog.getByRole('textbox', { name: moneyFieldLabel('Price') }),
      '2 100.50',
    )
    await clickSubmit(expect, dialog.getByTestId('admin-product-edit-submit'))
    await expect(dialog).toBeHidden()

    await expectAmounts('₴2,100.50', '₴6,301.50')
    await expectDocumentUnchanged(expect, guest)
  })

  await test.step('follow the cart line to its product page', async () => {
    await productLink.click()
    await expect(guest).toHaveURL(/\/product\/[^/]+$/)
    await expectPageReady(expect, guest)
    await expect(guest.getByRole('heading', { level: 1 })).toHaveText(name)
  })
})
