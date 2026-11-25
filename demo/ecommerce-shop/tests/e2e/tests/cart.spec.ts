import { randomBytes } from 'node:crypto'

import { test as base, expect } from '../test.js'
import { moneyFieldLabel } from '../../../frontend/src/money/money.js'
import { PAGE_ADMIN_PRODUCT_STOCK } from '../../../frontend/src/pages/keys.js'
import {
  PAGE_READY,
  cleanupFixtures,
  clickSubmit,
  createTestUser,
  drawPhotoFixture,
  expectDocumentUnchanged,
  expectPageReady,
  gotoPage,
  grantAdminToSelf,
  login,
  logout,
  markDocument,
  openSignIn,
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
      await expect(page).toHaveURL(/\/hilos\/product\/[1-9]\d*$/)

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

  const voucherPrefix = `ZZE2EV${randomBytes(11).toString('hex').toUpperCase()}`
  const firstCode = `${voucherPrefix}01`
  const replacementCode = `${voucherPrefix}02`
  const inactiveCode = `${voucherPrefix}03`
  const unknownCode = `${voucherPrefix}04`
  const openVoucherForm = guest.getByTestId('cart-voucher-apply-open')
  const voucherForm = guest.getByTestId('cart-voucher-apply')
  const voucherCode = voucherForm.getByTestId('cart-voucher-apply-code')
  const voucherError = voucherForm.getByTestId('cart-voucher-apply-error')
  const applyVoucher = async (code: string) => {
    await typeInto(voucherCode, code)
    await clickSubmit(expect, guest.getByTestId('cart-voucher-apply-submit'))
  }
  const voucherRow = guest.getByTestId('shop-cart-voucher')
  const expectVoucher = async (
    code: string,
    deduction: string,
    total: string,
  ) => {
    await expect(voucherRow.locator('dt')).toHaveText(`Voucher ${code}`)
    await expect(voucherRow.locator('dd')).toHaveText(deduction)
    await expect(guest.getByTestId('shop-cart-total')).toHaveText(total)
    await expectDocumentUnchanged(expect, guest)
  }

  await test.step('apply a mixed-case voucher and see the discounted total', async () => {
    for (const voucher of [
      { code: firstCode, discountPercent: 10, active: true },
      { code: replacementCode, discountPercent: 20, active: true },
      { code: inactiveCode, discountPercent: 25, active: false },
    ]) {
      const { code } = voucher
      expect(
        await command('test:ecommerce-shop:voucher-fixture-get', { code }),
      ).toEqual({ voucher: null })
      cleanup.defer(() =>
        command('test:ecommerce-shop:voucher-fixture-delete', { code }),
      )
      expect(
        await command('test:ecommerce-shop:voucher-fixture-create', voucher),
      ).toMatchObject({ voucher })
    }

    const token = await sessionToken(guest)
    const setCartVoucher = 'test:ecommerce-shop:guest-cart-set-voucher'
    const clearVoucher = (code: string) =>
      command(setCartVoucher, { sessionToken: token, code, applied: false })
    cleanup.defer(async () => {
      // The browser may have applied either code even if an assertion failed.
      const reply = await clearVoucher(replacementCode).catch(
        (error: unknown) => {
          if (
            !(error instanceof Error) ||
            error.message !==
              `${setCartVoucher} failed: This guest cart holds a different voucher`
          ) {
            throw error
          }
          return clearVoucher(firstCode)
        },
      )
      expect(reply).toEqual({ applied: false })
    })

    await openVoucherForm.click()
    await applyVoucher(`z${firstCode.slice(1)}`)
    await expect(voucherForm).toBeHidden()
    await expectVoucher(firstCode, '−₴630.15', '₴5,671.35')
  })

  await test.step('keep the applied voucher when another code is refused', async () => {
    await openVoucherForm.click()
    for (const { code, reason } of [
      { code: unknownCode, reason: 'Voucher code was not found' },
      { code: inactiveCode, reason: 'This voucher is inactive' },
    ]) {
      await applyVoucher(code)
      await expect(
        voucherError.getByText(reason, { exact: true }),
      ).toBeVisible()
      await expect(voucherCode).toHaveValue(code)
      await expectVoucher(firstCode, '−₴630.15', '₴5,671.35')
    }
  })

  await test.step('replace the voucher and see the new total in the same cart', async () => {
    await applyVoucher(replacementCode)
    await expect(voucherForm).toBeHidden()
    await expectVoucher(replacementCode, '−₴1,260.30', '₴5,041.20')
    await expect(voucherRow).not.toContainText(firstCode)
  })

  await test.step('follow the cart line to its product page', async () => {
    await productLink.click()
    await expect(guest).toHaveURL(/\/product\/[^/]+$/)
    await expectPageReady(expect, guest)
    await expect(guest.getByRole('heading', { level: 1 })).toHaveText(name)
  })

  await test.step('remove the line and see the guest cart empty in place', async () => {
    const cartLink = guest.getByTestId('nav-cart')
    await cartLink.click()
    await expect(guest).toHaveURL('/cart')
    await expectPageReady(expect, guest)
    await line
      .getByRole('button', { name: `Remove ${name}`, exact: true })
      .click()
    await expect(guest.getByTestId('shop-cart-empty')).toBeVisible()
    await expect(cartLink.getByTestId('cart-badge')).toHaveText('0')
    await expectDocumentUnchanged(expect, guest)
  })
})

test('signing in combines the guest and account carts and signing out opens an empty cart', async ({
  page,
  cleanup,
}) => {
  const chamomile = {
    id: 4,
    slug: 'small-bunch-of-chamomile',
    name: 'Small bunch of chamomile',
  }
  const meadow = { id: 1, slug: 'morning-meadow', name: 'Morning meadow' }
  const products = [chamomile, meadow]
  const cartLink = page.getByTestId('nav-cart')
  const badge = cartLink.getByTestId('cart-badge')

  const addProducts = async () => {
    for (const [index, { slug }] of products.entries()) {
      await gotoPage(expect, page, `/product/${slug}`)
      const addButton = page.getByTestId('shop-product-add-to-cart')
      await addButton.click()
      await expect(
        addButton.and(page.locator('[aria-busy="true"]')),
      ).toHaveCount(0)
      await expect(badge).toHaveText(String(index + 1))
    }
  }

  const user = await createTestUser(expect, page)
  await test.step('add both products to the account cart', addProducts)

  await test.step('sign out and add both products to the guest cart', async () => {
    await logout(expect, page)
    await expect(badge).toHaveText('0')

    const guestToken = await sessionToken(page)
    const command = createStandCommandChannel({ timeoutMs: 5_000 })
    for (const { id: productId } of products) {
      cleanup.defer(async () => {
        // The transfer owns this cart after sign-in rotates the guest token.
        if ((await sessionToken(page)) !== guestToken) return
        await command('test:ecommerce-shop:guest-cart-set-quantity', {
          sessionToken: guestToken,
          productId,
          quantity: 0,
        })
      })
    }

    await addProducts()
  })

  await test.step('sign in with a password and see the combined quantities', async () => {
    await openSignIn(expect, page)
    await login(expect, page, user.email)
    await expect(page.getByTestId('nav-profile-name')).toBeVisible()
    await expect(badge).toHaveText('3')

    await cartLink.click()
    await expect(page).toHaveURL('/cart')
    await expectPageReady(expect, page)
    const lines = page.getByTestId('shop-cart-line')
    await expect(lines).toHaveCount(2)
    const chamomileLine = lines.filter({
      has: page.getByRole('link', { name: chamomile.name, exact: true }),
    })
    await expect(chamomileLine.getByTestId('shop-cart-quantity')).toHaveText(
      'Quantity: 2',
    )
    const meadowLine = lines.filter({
      has: page.getByRole('link', { name: meadow.name, exact: true }),
    })
    await expect(
      meadowLine.getByText('1 · one of a kind', { exact: true }),
    ).toBeVisible()
  })

  await test.step('sign out and see an empty cart', async () => {
    await logout(expect, page)
    await expect(page.getByTestId('shop-cart-empty')).toBeVisible()
    await expect(badge).toHaveText('0')
  })
})
