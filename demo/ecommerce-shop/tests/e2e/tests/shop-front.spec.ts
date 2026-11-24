import { randomBytes } from 'node:crypto'
import type { Page } from '@playwright/test'

import { test as base, expect } from '../test.js'
import { moneyFieldLabel } from '../../../frontend/src/money/money.js'
import {
  PAGE_ADMIN_PRODUCT,
  PAGE_ADMIN_PRODUCT_STOCK,
  PAGE_PRODUCT,
} from '../../../frontend/src/pages/keys.js'
import { PRODUCT_STOCK_SET_UNITS } from '../../../frontend/src/views/AdminProductStock/adminProductStockActions.js'
import { SIGNAL_TYPE_ACTION } from '../../../../../framework/frontend/core/src/protocol/constants.js'
import {
  PAGE_READY,
  PAGE_REFUSED,
  cleanupFixtures,
  clickSubmit,
  deliveryObject,
  drawPhotoFixture,
  emulateSystemTheme,
  expectDocumentUnchanged,
  expectPageReady,
  gotoPage,
  grantAdminToSelf,
  markDocument,
  navigateByHistory,
  trackedActionReplies,
  type TestCleanup,
  typeInto,
  watchDeliveryFrames,
  watchPageErrorFrames,
} from '../../../../../framework/frontend/e2e/index.js'
import { createStandCommandChannel } from '../../../../../framework/frontend/scripts/standCommand.mjs'

// spec-owner: demo — the shop's own storefront

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })

// The shop front e2e: what a visitor finds on the shop's pages. The data is the
// catalog a database reset opens the shop on — six categories of five products
// each, every one of a kind and in a warehouse — so every product counts on
// the shop front. Neither the look of a tile nor the order of the tiles is
// asserted here: the order is the selector's, proved by its unit test.
test('a guest browses shop categories and sees a refusal for an unknown category', async ({
  page,
}) => {
  await gotoPage(expect, page, '/')

  const bouquets = page
    .getByTestId('shop-home-tile')
    .filter({ hasText: 'Bouquets' })
  await expect(bouquets).toContainText('5 products')
  await expect(bouquets).toHaveAttribute('href', '/category/bouquets')
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)

  await test.step('scroll the catalog from the left gutter', async () => {
    const main = page.locator('#hilos-main-content')
    const mainScrollBefore = await main.evaluate((node) => node.scrollTop)
    const documentScrollBefore = await page.evaluate(() => window.scrollY)
    const viewportMidY = await page.evaluate(() => window.innerHeight / 2)

    await page.mouse.move(8, viewportMidY)
    await page.mouse.wheel(0, 240)

    await expect
      .poll(() => main.evaluate((node) => node.scrollTop))
      .toBeGreaterThan(mainScrollBefore)
    const documentScrollAfter = await page.evaluate(() => window.scrollY)
    expect(documentScrollAfter).toBe(documentScrollBefore)
  })

  const heading = page.getByRole('heading', { level: 1 })
  const products = page.getByTestId('shop-category-products')
  const morningMeadow = { name: 'Morning meadow', exact: true }
  const monstera = { name: 'Monstera deliciosa, 80 cm', exact: true }

  await test.step('open Bouquets and see its products in Tiles', async () => {
    await bouquets.click()
    await expect(page).toHaveURL('/category/bouquets')
    await expectPageReady(expect, page)
    await expect(heading).toHaveText('Bouquets')
    await expect(
      page.getByText(
        'Author bouquets made once, and small bunches we keep in stock.',
        { exact: true },
      ),
    ).toBeVisible()
    await expect(
      products
        .getByRole('list', { name: 'Bouquets', exact: true })
        .getByRole('link', morningMeadow),
    ).toBeVisible()
    await expect(products.getByRole('link', monstera)).toHaveCount(0)
  })

  await test.step('see the same product in Table', async () => {
    const tableView = products.getByTestId('hilos-table-view-table')
    await expect(tableView).toHaveAccessibleName('Table')
    await tableView.click()
    await expect(
      products.getByRole('table').getByRole('link', morningMeadow),
    ).toBeVisible()
  })

  await test.step('switch categories through history without reloading', async () => {
    await markDocument(page)
    await navigateByHistory(expect, page, '/category/potted-plants', PAGE_READY)
    await expect(heading).toHaveText('Potted plants')
    await expect(
      page.getByText(
        'A particular plant in its pot, and small ones we keep by the dozen.',
        { exact: true },
      ),
    ).toBeVisible()
    await expect(products.getByRole('link', monstera)).toBeVisible()
    await expect(products.getByRole('link', morningMeadow)).toHaveCount(0)
    await expectDocumentUnchanged(expect, page)
  })

  await test.step('return to the catalog through Home', async () => {
    await page.getByTestId('hilos-breadcrumb-main').click()
    await expect(page).toHaveURL('/')
    await expectPageReady(expect, page)
    await expect(bouquets).toBeVisible()
  })

  await test.step('see the server refusal when opening an unknown category directly', async () => {
    await gotoPage(expect, page, '/category/unknown-category', PAGE_REFUSED)
    const error = page.getByTestId('page-error')
    await expect(error).toHaveAttribute('data-error-code', '404')
    await expect(
      error.getByText('Page Not Found', { exact: true }),
    ).toBeVisible()
    await expect(
      error.getByText('The page you are looking for does not exist.', {
        exact: true,
      }),
    ).toBeVisible()
  })
})

test('a guest sees live price and stock changes on a product and a server refusal for an unknown product', async ({
  browser,
  page,
  cleanup,
}) => {
  const name = `e2e product ${randomBytes(6).toString('hex')}`
  const renamedSuffix = randomBytes(6).toString('hex')
  const renamedName = `e2e product ${renamedSuffix}`
  const renamedPath = `/product/e2e-product-${renamedSuffix}`
  const categoryName = 'Pots & planters'
  const categoryPath = '/category/pots-planters'
  const description = 'A ceramic planter carried in stock.'
  const deleteProductCommand = 'test:ecommerce-shop:product-delete'
  const deleteProductFailure = `${deleteProductCommand} failed: ${deleteProductCommand} failed: The product fixture could not be deleted`
  const command = createStandCommandChannel({ timeoutMs: 5_000 })
  const delivery = watchDeliveryFrames(page)
  const stockUnits = '3'
  let stockWrite: { path: string; since: number } | undefined
  let pendingStockPath: string | undefined

  const warehouseRow = (stockPage: Page) =>
    stockPage
      .getByTestId('admin-product-stock-table')
      .getByRole('row')
      .filter({
        has: stockPage.getByRole('cell', {
          name: 'Odesa · Peresyp',
          exact: true,
        }),
      })

  const setStock = async (stockPage: Page, units: string) => {
    const row = warehouseRow(stockPage)
    const dialog = stockPage.getByRole('dialog', {
      name: 'Set stock',
      exact: true,
    })
    await row.getByTestId('admin-product-stock-edit-open').click()
    await typeInto(dialog.getByTestId('admin-product-stock-edit-units'), units)
    await clickSubmit(
      expect,
      dialog.getByTestId('admin-product-stock-edit-submit'),
    )
    await expect(dialog).toBeHidden()
    await expect(
      row.getByRole('cell', { name: units, exact: true }),
    ).toBeVisible()
  }

  const withStockPage = async <T>(
    path: string,
    read: (stockPage: Page) => Promise<T>,
  ): Promise<T> => {
    const stockPage = await page.context().newPage()
    try {
      await gotoPage(expect, stockPage, path, PAGE_READY)
      await expect(warehouseRow(stockPage)).toBeVisible()

      return await read(stockPage)
    } finally {
      await stockPage.close()
    }
  }

  cleanup.defer(() => delivery.stop())
  cleanup.defer(async () => {
    try {
      await command(deleteProductCommand, { name })
    } catch (error) {
      if (
        pendingStockPath === undefined ||
        !(error instanceof Error) ||
        error.message !== deleteProductFailure
      ) {
        throw error
      }

      // The command wraps database failures. Confirm the late write that
      // now holds the stock foreign key before trying the deletion again.
      const cleared = await withStockPage(
        pendingStockPath,
        async (stockPage) => {
          const written = warehouseRow(stockPage).getByRole('cell', {
            name: stockUnits,
            exact: true,
          })
          if (!(await written.isVisible())) return false

          await setStock(stockPage, '0')

          return true
        },
      )
      if (!cleared) throw error

      await command(deleteProductCommand, { name })
    }
  })
  cleanup.defer(() => command(deleteProductCommand, { name: renamedName }))
  cleanup.defer(async () => {
    if (!stockWrite) return
    const { path, since } = stockWrite
    const wasSent = delivery.sent.slice(since).some(({ wire }) => {
      const frame = deliveryObject(wire)
      return (
        frame.type === SIGNAL_TYPE_ACTION &&
        frame.action === PRODUCT_STOCK_SET_UNITS
      )
    })
    const hasReply = () =>
      trackedActionReplies(delivery, PRODUCT_STOCK_SET_UNITS, since).length > 0
    const repliedBeforeRead = hasReply()
    await withStockPage(path, async (restorePage) => {
      const row = warehouseRow(restorePage)
      let replyMayFollowRead = false
      if (wasSent && !repliedBeforeRead) {
        const written = row.getByRole('cell', {
          name: stockUnits,
          exact: true,
        })
        // A fresh view can see the committed write even if its reply was lost.
        try {
          await expect
            .poll(async () => hasReply() || (await written.isVisible()), {
              message: 'Stock cleanup waits for the reply or the written units',
            })
            .toBe(true)
        } catch (error) {
          test.info().annotations.push({
            type: 'stock-cleanup-wait',
            description: String(error),
          })
        }
        // The first snapshot can predate the write or its reply.
        if (!(await written.isVisible())) {
          replyMayFollowRead = !hasReply()
          await gotoPage(expect, restorePage, path, PAGE_READY)
          await expect(row).toBeVisible()
        }
      }
      const zero = row.getByRole('cell', { name: '0', exact: true })
      if (!(await zero.isVisible())) {
        await setStock(restorePage, '0')
      } else if (replyMayFollowRead) {
        pendingStockPath = path
      }
    })
  })

  await grantAdminToSelf(expect, page)
  await test.step('create a stock item through Add product', async () => {
    await gotoPage(expect, page, '/hilos/app/products')
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
      .selectOption({ label: categoryName })
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
      description,
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
        rectangles: [{ color: '#246ac4', x: 0, y: 0, width: 320, height: 240 }],
      }),
    })
    await expect(dialog.getByTestId('admin-products-add-preview')).toBeVisible()
    await clickSubmit(expect, dialog.getByTestId('admin-products-add-submit'))
    await expect(dialog).toBeHidden()
    await products.getByRole('link', { name, exact: true }).click()
    await expectPageReady(expect, page)
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(name)
  })

  const guestContext = await browser.newContext()
  cleanup.defer(() => guestContext.close())
  const guest = await guestContext.newPage()
  const price = guest.getByTestId('shop-product-price')
  const availability = guest.getByTestId('shop-product-availability')
  const heading = guest.getByRole('heading', { level: 1 })
  const expectCard = async () => {
    const crumbs = guest.getByRole('navigation', { name: 'breadcrumb' })
    await expect(
      crumbs.getByRole('link', { name: 'Home', exact: true }),
    ).toHaveAttribute('href', '/')
    await expect(
      crumbs.getByRole('link', { name: categoryName, exact: true }),
    ).toHaveAttribute('href', categoryPath)
    await expect(crumbs.locator('[aria-current="page"]')).toContainText(name)
    await expect(heading).toHaveCount(1)
    await expect(heading).toHaveText(name)
    await expect(guest.getByRole('img', { name, exact: true })).toBeVisible()
    await expect(guest.getByText(description, { exact: true })).toBeVisible()
    await expect(price).toHaveText('₴410')
    await expect(availability).toHaveText('Out of stock')
  }

  const productPath =
    await test.step('open the same product through its category link and directly', async () => {
      await gotoPage(expect, guest, categoryPath)
      const product = guest
        .getByTestId('shop-category-products')
        .getByRole('link', { name, exact: true })
      await product.click()
      await expect(guest).toHaveURL(/\/product\/[^/]+$/)
      await expectPageReady(expect, guest)
      await expectCard()

      const path = new URL(guest.url()).pathname
      await gotoPage(expect, guest, path, PAGE_READY)
      await expectCard()
      await markDocument(guest)
      return path
    })

  await test.step('change only the price and see the exact amount in the open product', async () => {
    const dialog = page.getByRole('dialog', {
      name: 'Edit product',
      exact: true,
    })
    await page.getByTestId('admin-product-edit-open').click()
    const field = dialog.getByRole('textbox', {
      name: moneyFieldLabel('Price'),
    })
    await expect(field).toHaveValue('410')
    await typeInto(field, '2 100.50')
    await clickSubmit(expect, dialog.getByTestId('admin-product-edit-submit'))
    await expect(dialog).toBeHidden()

    await expect(price).toHaveText('₴2,100.50')
    await expect(availability).toHaveText('Out of stock')
    await expect(guest).toHaveURL(productPath)
    await expectDocumentUnchanged(expect, guest)
  })

  await test.step('set three units at one warehouse and see the low stock in the open product', async () => {
    await page.getByTestId(`hilos-page-tab-${PAGE_ADMIN_PRODUCT_STOCK}`).click()
    await expectPageReady(expect, page)
    await expect(
      warehouseRow(page).getByRole('cell', { name: '0', exact: true }),
    ).toBeVisible()
    stockWrite = {
      path: new URL(page.url()).pathname,
      since: delivery.sent.length,
    }
    await setStock(page, stockUnits)

    await expect(availability).toHaveText('Only 3 left')
    await expect(guest).toHaveURL(productPath)
    await expectDocumentUnchanged(expect, guest)
  })

  await test.step('return stock to zero and keep the product open at the same address', async () => {
    await setStock(page, '0')
    stockWrite = undefined

    await expect(availability).toHaveText('Out of stock')
    await expect(price).toHaveText('₴2,100.50')
    await expect(heading).toHaveText(name)
    await expect(guest).toHaveURL(productPath)
    await expectDocumentUnchanged(expect, guest)
  })

  await test.step('renaming the product moves the open guest page without a reload', async () => {
    await page.getByTestId(`hilos-page-tab-${PAGE_ADMIN_PRODUCT}`).click()
    await expectPageReady(expect, page)
    await page.getByTestId('admin-product-edit-open').click()
    const dialog = page.getByRole('dialog', {
      name: 'Edit product',
      exact: true,
    })
    await typeInto(
      dialog.getByRole('textbox', { name: 'Name', exact: true }),
      renamedName,
    )
    await clickSubmit(expect, dialog.getByTestId('admin-product-edit-submit'))
    await expect(dialog).toBeHidden()

    await expect(guest).toHaveURL(renamedPath)
    await expectPageReady(expect, guest)
    await expect(heading).toHaveText(renamedName)
    await expectDocumentUnchanged(expect, guest)
  })

  await test.step('opening the former product link in a new tab reaches its current address', async () => {
    const fresh = await guestContext.newPage()
    await gotoPage(expect, fresh, productPath, PAGE_READY)
    await expect(fresh).toHaveURL(renamedPath)
    await expect(fresh.getByRole('heading', { level: 1 })).toHaveText(
      renamedName,
    )
    await expect(fresh.getByTestId('shop-product-price')).toHaveText(
      '₴2,100.50',
    )
    await expect(fresh.getByTestId('shop-product-availability')).toHaveText(
      'Out of stock',
    )
  })

  await test.step('see the server refusal and its reason for an unknown product', async () => {
    const refusals = watchPageErrorFrames(guest)
    await gotoPage(expect, guest, '/product/unknown-product', PAGE_REFUSED)
    const title = 'Page Not Found'
    const message = 'The page you are looking for does not exist.'
    expect(refusals.map(({ data }) => data)).toContainEqual(
      expect.objectContaining({
        page: PAGE_PRODUCT,
        httpCode: 404,
        errorCode: 'not_found',
        title,
        message,
      }),
    )
    const error = guest.getByTestId('page-error')
    await expect(error).toHaveAttribute('data-error-code', '404')
    await expect(error.getByText(title, { exact: true })).toBeVisible()
    await expect(error.getByText(message, { exact: true })).toBeVisible()
  })
})

test('shop.product-viewers: Overview and shop tabs see viewers join and leave live', async ({
  browser,
  page,
  cleanup,
}) => {
  const productName = 'Morning meadow'
  const shopPath = '/product/morning-meadow'
  const nobodyElse = 'Nobody else is looking at it now'
  const oneOther = '1 other is looking at it now'
  const nobody = 'Nobody is looking at it now'
  const onePerson = '1 person is looking at it now'
  const viewers = (tab: Page) =>
    tab.getByTestId('product-viewer-count').filter({ visible: true })

  await emulateSystemTheme(page, 'light')
  await grantAdminToSelf(expect, page)
  await test.step('open Overview before anyone views the product', async () => {
    await gotoPage(expect, page, '/hilos/app/product/1', PAGE_READY)
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(
      productName,
    )
    await expect(viewers(page)).toHaveText(nobody)
    await markDocument(page)
  })

  const guestContext = await browser.newContext({ colorScheme: 'light' })
  cleanup.defer(() => guestContext.close())
  const guest = await guestContext.newPage()
  await test.step('count the first guest in the open Overview', async () => {
    await gotoPage(expect, guest, shopPath, PAGE_READY)
    await expect(guest.getByRole('heading', { level: 1 })).toHaveText(
      productName,
    )
    await expect(viewers(guest)).toHaveText(nobodyElse)
    await markDocument(guest)
    await expect(viewers(page)).toHaveText(onePerson)
    await expectDocumentUnchanged(expect, page)
  })

  const guestSecondTab = await guestContext.newPage()
  await test.step('count two tabs in the guest session as one viewer', async () => {
    await gotoPage(expect, guestSecondTab, shopPath, PAGE_READY)
    await expect(guestSecondTab.getByRole('heading', { level: 1 })).toHaveText(
      productName,
    )
    await expect(viewers(guestSecondTab)).toHaveText(nobodyElse)
    await markDocument(guestSecondTab)
    await expect(viewers(guest)).toHaveText(nobodyElse)
    await expect(viewers(page)).toHaveText(onePerson)
  })

  const adminShop = await page.context().newPage()
  await test.step('join as a second viewer and update the open tabs', async () => {
    await emulateSystemTheme(adminShop, 'light')
    await gotoPage(expect, adminShop, shopPath, PAGE_READY)
    await expect(adminShop.getByRole('heading', { level: 1 })).toHaveText(
      productName,
    )
    await expect(viewers(adminShop)).toHaveText(oneOther)
    await markDocument(adminShop)

    await expect(viewers(guest)).toHaveText(oneOther)
    await expect(viewers(guestSecondTab)).toHaveText(oneOther)
    await expect(viewers(page)).toHaveText('2 people are looking at it now')
    await expectDocumentUnchanged(expect, guest)
    await expectDocumentUnchanged(expect, guestSecondTab)
    await expectDocumentUnchanged(expect, page)
  })

  await test.step('close both guest tabs and see the remaining viewer', async () => {
    await guest.close()
    await guestSecondTab.close()
    await expect(viewers(page)).toHaveText(onePerson)
    await expect(viewers(adminShop)).toHaveText(nobodyElse)
    await expectDocumentUnchanged(expect, page)
    await expectDocumentUnchanged(expect, adminShop)
  })

  await test.step('close the last shop tab and see zero viewers in Overview', async () => {
    await adminShop.close()
    await expect(viewers(page)).toHaveText(nobody)
    await expectDocumentUnchanged(expect, page)
  })
})
