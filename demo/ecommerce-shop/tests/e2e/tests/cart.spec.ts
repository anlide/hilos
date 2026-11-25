import { randomBytes } from 'node:crypto'

import { test as base, expect } from '../test.js'
import {
  createStockProduct,
  deferCatalogProductDelete,
  setStock,
  warehouseRow,
} from '../helpers/catalogProducts.js'
import {
  bindGuestCartItem,
  deferGuestCartAddCleanup,
  deferGuestCartProductsCleanup,
} from '../helpers/guestCart.js'
import { moneyFieldLabel } from '../../../frontend/src/money/money.js'
import { PAGE_ADMIN_PRODUCT_STOCK } from '../../../frontend/src/pages/keys.js'
import { SIGNAL_TYPE_ACTION_SUCCESS } from '../../../../../framework/frontend/core/src/protocol/constants.js'
import {
  PAGE_READY,
  cleanupFixtures,
  clickSubmit,
  createTestUser,
  expectDocumentUnchanged,
  expectPageReady,
  gotoPage,
  grantAdminToSelf,
  login,
  logout,
  markDocument,
  openSignIn,
  sessionToken,
  timeZoneContextOptions,
  trackedActionReplies,
  type TestCleanup,
  typeInto,
  watchDeliveryFrames,
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
  const guestContext = await browser.newContext(
    timeZoneContextOptions(test.info().project.use, 'Africa/Mogadishu'),
  )
  cleanup.defer(() => guestContext.close())
  const guest = await guestContext.newPage()

  deferCatalogProductDelete(cleanup, name)
  await grantAdminToSelf(expect, page)

  const { overviewPath, productId } =
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

    const setQuantity = await bindGuestCartItem(cleanup, guest, productId)
    await setQuantity(3)

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
  const accountVoucher = 'SPRING10'
  const guestVoucher = 'WEDDING15'
  const cartLink = page.getByTestId('nav-cart')
  const badge = cartLink.getByTestId('cart-badge')
  const openVoucherForm = page.getByTestId('cart-voucher-apply-open')
  const voucherForm = page.getByTestId('cart-voucher-apply')
  const voucherRow = page.getByTestId('shop-cart-voucher')
  const items = page
    .getByText('Items', { exact: true })
    .locator('..')
    .locator('dd')
  const total = page.getByTestId('shop-cart-total')
  const command = createStandCommandChannel({ timeoutMs: 5_000 })

  const addProducts = async (cartProducts = products) => {
    for (const [index, { slug }] of cartProducts.entries()) {
      await gotoPage(expect, page, `/product/${slug}`)
      const addButton = page.getByTestId('shop-product-add-to-cart')
      await addButton.click()
      await expect(
        addButton.and(page.locator('[aria-busy="true"]')),
      ).toHaveCount(0)
      await expect(badge).toHaveText(String(index + 1))
    }
  }

  const openCart = async () => {
    await cartLink.click()
    await expect(page).toHaveURL('/cart')
    await expectPageReady(expect, page)
  }

  const applyVoucher = async (code: string) => {
    await openVoucherForm.click()
    await typeInto(voucherForm.getByTestId('cart-voucher-apply-code'), code)
    await clickSubmit(expect, page.getByTestId('cart-voucher-apply-submit'))
    await expect(voucherForm).toBeHidden()
    await expect(voucherRow.locator('dt')).toHaveText(`Voucher ${code}`)
  }

  const deferGuestCartCleanup = async (
    cartProducts: typeof products,
    code?: string,
  ) => {
    const guestToken = await deferGuestCartProductsCleanup(
      cleanup,
      page,
      cartProducts.map(({ id }) => id),
    )
    if (code !== undefined) {
      cleanup.defer(async () => {
        if ((await sessionToken(page)) !== guestToken) return
        await command('test:ecommerce-shop:guest-cart-set-voucher', {
          sessionToken: guestToken,
          code,
          applied: false,
        })
      })
    }
  }

  const user = await createTestUser(expect, page)
  await test.step('add both products and apply a voucher to the account cart', async () => {
    await addProducts()
    await openCart()
    await applyVoucher(accountVoucher)
  })

  await test.step('sign out, add both products, and apply a guest voucher', async () => {
    await logout(expect, page)
    await expect(badge).toHaveText('0')
    await deferGuestCartCleanup(products, guestVoucher)
    await addProducts()
    await openCart()
    await applyVoucher(guestVoucher)
  })

  await test.step('sign in with a password and see the combined cart with the guest voucher', async () => {
    await openSignIn(expect, page)
    await login(expect, page, user.email)
    await expect(page.getByTestId('nav-profile-name')).toBeVisible()
    await expect(badge).toHaveText('3')

    await openCart()
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
    await expect(voucherRow.locator('dt')).toHaveText(`Voucher ${guestVoucher}`)
    await expect(items).toHaveText('₴2,410')
    await expect(total).toHaveText('₴2,048.50')
  })

  await test.step('sign out and see an empty cart', async () => {
    await logout(expect, page)
    await expect(page.getByTestId('shop-cart-empty')).toBeVisible()
    await expect(badge).toHaveText('0')
  })

  await test.step('add a product to the new guest cart and see the undiscounted total', async () => {
    await deferGuestCartCleanup([chamomile])
    await addProducts([chamomile])
    await openCart()
    await expect(openVoucherForm).toBeVisible()
    await expect(openVoucherForm).toBeEnabled()
    await expect(openVoucherForm).toHaveText('Apply voucher')
    await expect(items).toHaveText('₴280')
    await expect(total).toHaveText('₴280')
  })
})

test('adding a product to a guest cart updates another tab without reloading', async ({
  browser,
  cleanup,
}) => {
  // The product ID in 001_hilos_flowers.sql identifies the cleanup target.
  const morningMeadow = {
    id: 1,
    slug: 'morning-meadow',
    name: 'Morning meadow',
  }
  const productPath = `/product/${morningMeadow.slug}`
  const guestContext = await browser.newContext(
    timeZoneContextOptions(test.info().project.use, 'Africa/Mogadishu'),
  )
  cleanup.defer(() => guestContext.close())

  const firstTab = await guestContext.newPage()
  const delivery = watchDeliveryFrames(firstTab)
  cleanup.defer(() => delivery.stop())
  await gotoPage(expect, firstTab, productPath)
  await expect(
    firstTab.getByTestId('nav-cart').getByTestId('cart-badge'),
  ).toHaveText('0')

  const secondTab = await guestContext.newPage()
  await gotoPage(expect, secondTab, productPath)
  const secondBadge = secondTab
    .getByTestId('nav-cart')
    .getByTestId('cart-badge')
  const availability = secondTab.getByTestId('shop-product-availability')
  await expect(secondBadge).toHaveText('0')
  await expect(availability).toHaveText('One of a kind')
  await markDocument(secondTab)

  const addToCart = 'product_add_to_cart'
  const since = delivery.sent.length
  const replies = () => trackedActionReplies(delivery, addToCart, since)
  const ownCartLine = availability.filter({ hasText: /^In your cart$/ })
  const clickAdd = await deferGuestCartAddCleanup(
    expect,
    cleanup,
    firstTab,
    morningMeadow.id,
    async () => replies().length > 0 || (await ownCartLine.isVisible()),
  )

  const addButton = firstTab.getByTestId('shop-product-add-to-cart')
  await clickAdd(() => addButton.click())
  await expect.poll(() => replies().length).toBe(1)
  expect(replies()[0].frame.type).toBe(SIGNAL_TYPE_ACTION_SUCCESS)
  await expect(firstTab.getByTestId('shop-product-go-to-cart')).toBeVisible()

  await expect(secondBadge).toHaveText('1')
  await expect(availability).toHaveText('In your cart')
  const cartLink = secondTab.getByTestId('shop-product-go-to-cart')
  await expect(cartLink).toHaveRole('link')
  await expect(cartLink).toHaveAccessibleName('In your cart — go to cart')
  await expect(cartLink).toHaveAttribute('href', '/cart')
  await expectDocumentUnchanged(expect, secondTab)

  await cartLink.click()
  await expect(secondTab).toHaveURL('/cart')
  await expectPageReady(expect, secondTab)
  await expect(
    secondTab
      .getByTestId('shop-cart-line')
      .getByTestId('shop-cart-product-link'),
  ).toHaveText(morningMeadow.name)
  expect(replies().length).toBe(1)
})
