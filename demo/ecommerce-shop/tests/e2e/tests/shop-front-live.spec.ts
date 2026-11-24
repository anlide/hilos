import { randomBytes } from 'node:crypto'
import type { Page } from '@playwright/test'

import { test as base, expect } from '../test.js'
import { drawCatalogPicture } from '../helpers/catalogPictures.js'
import { moneyFieldLabel } from '../../../frontend/src/money/money.js'
import {
  PAGE_ADMIN_PRODUCT,
  PAGE_ADMIN_PRODUCT_STOCK,
  PAGE_CATEGORY,
} from '../../../frontend/src/pages/keys.js'
import { PRODUCT_STOCK_SET_UNITS } from '../../../frontend/src/views/AdminProductStock/adminProductStockActions.js'
import {
  CATEGORY_PRODUCTS_TABLE,
  FILTER_CATEGORY,
} from '../../../frontend/src/views/Category/categoryProducts.js'
import {
  SIGNAL_TYPE_ACTION,
  SIGNAL_TYPE_ACTION_SUCCESS,
  SIGNAL_TYPE_PAGE_RESPONSE,
  SIGNAL_TYPE_PAGE_SUBSCRIBE,
} from '../../../../../framework/frontend/core/src/protocol/constants.js'
import {
  PAGE_READY,
  ROWS,
  cleanupFixtures,
  clickSubmit,
  deliveryObject,
  dismissToasts,
  drawPhotoFixture,
  emulateSystemTheme,
  expectDocumentUnchanged,
  expectPageReady,
  expectTableTotal,
  goToLastPage,
  gotoPage,
  grantAdminToSelf,
  markDocument,
  sessionToken,
  shownByTestId,
  tableTotal,
  trackedActionReplies,
  type TestCleanup,
  typeInto,
  watchDeliveryFrames,
} from '../../../../../framework/frontend/e2e/index.js'
import { createStandCommandChannel } from '../../../../../framework/frontend/scripts/standCommand.mjs'

// spec-owner: demo — the shop's own live storefront across guest and admin windows

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })

const CATEGORIES_PATH = '/hilos/categories'

const CATEGORIES_TABLE = 'admin-categories-table'

// EcommerceShopCommandConstants::CATEGORY_TEST_DELETE, request key `name`.
const CATEGORY_DELETE_COMMAND = 'test:ecommerce-shop:category-delete'

// EcommerceShopCommandConstants::PRODUCT_TEST_DELETE, request key `name`.
const PRODUCT_DELETE_COMMAND = 'test:ecommerce-shop:product-delete'

// Browser traces can include the guest's session cookie.
const guestCartTest = test.extend({ trace: 'off' })

guestCartTest(
  'adding a product to a guest cart updates another tab without reloading',
  async ({ browser, cleanup }) => {
    // The product ID in 001_hilos_flowers.sql identifies the cleanup target.
    const morningMeadow = {
      id: 1,
      slug: 'morning-meadow',
      name: 'Morning meadow',
    }
    const productPath = `/product/${morningMeadow.slug}`
    const guestContext = await browser.newContext({ colorScheme: 'light' })
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

    const token = await sessionToken(firstTab)
    const command = createStandCommandChannel({ timeoutMs: 5_000 })
    const addToCart = 'product_add_to_cart'
    const since = delivery.sent.length
    const replies = () => trackedActionReplies(delivery, addToCart, since)
    let clickAttempted = false

    cleanup.defer(async () => {
      if (!clickAttempted) return

      // The browser can send the add before the observer receives framesent.
      const ownCartLine = availability.filter({ hasText: /^In your cart$/ })
      let unconfirmed: Error | undefined
      try {
        await expect
          .poll(() => replies().length > 0 || ownCartLine.isVisible(), {
            message: 'Guest cart cleanup waits for the add reply or cart line',
          })
          .toBe(true)
      } catch (error) {
        unconfirmed = new Error(
          'Guest cart cleanup could not confirm that the add finished',
          { cause: error },
        )
      }

      // An error can arrive after the cart write commits, so remove after either reply.
      try {
        await command('test:ecommerce-shop:guest-cart-set-quantity', {
          sessionToken: token,
          productId: morningMeadow.id,
          quantity: 0,
        })
      } catch (error) {
        if (unconfirmed === undefined) throw error
        throw new AggregateError(
          [unconfirmed, error],
          'Guest cart add was unconfirmed and the fallback removal failed',
          { cause: error },
        )
      }
      if (unconfirmed !== undefined) throw unconfirmed
    })

    const addButton = firstTab.getByTestId('shop-product-add-to-cart')
    clickAttempted = true
    await addButton.click()
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
  },
)

test('a guest sees live price, stock, and address changes on a product', async ({
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
    await gotoPage(expect, page, '/hilos/products')
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
    await gotoPage(expect, page, '/hilos/product/1', PAGE_READY)
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

test('shop.categories-add: an admin adds a category with a picture, a second tab sees it arrive last, and a taken name is refused in the window', async ({
  browser,
  page,
  cleanup,
}) => {
  const anchorSuffix = randomBytes(6).toString('hex')
  const anchorName = `e2e anchor ${anchorSuffix}`
  const anchorSlug = `e2e-anchor-${anchorSuffix}`
  const suffix = randomBytes(6).toString('hex')
  const name = `e2e category ${suffix}`
  const renamedName = `${name} renamed`
  const categorySlug = `e2e-category-${suffix}`
  const renamedSlug = `${categorySlug}-renamed`
  const categoryPath = `/category/${categorySlug}`
  const renamedPath = `/category/${renamedSlug}`
  const productName = `e2e product ${randomBytes(6).toString('hex')}`
  const command = createStandCommandChannel({ timeoutMs: 5_000 })

  // cleanupFixtures runs LIFO: register category removals first and product
  // removal after them so the product is deleted before its category.
  cleanup.defer(() => command(CATEGORY_DELETE_COMMAND, { name: anchorName }))
  cleanup.defer(() => command(CATEGORY_DELETE_COMMAND, { name }))
  cleanup.defer(() => command(CATEGORY_DELETE_COMMAND, { name: renamedName }))
  cleanup.defer(() => command(PRODUCT_DELETE_COMMAND, { name: productName }))

  const guestContext = await browser.newContext()
  cleanup.defer(() => guestContext.close())
  const guestHome = await guestContext.newPage()
  const homeTiles = guestHome.getByTestId('shop-home-tile')
  const homeTile = (tileName: string) =>
    homeTiles.filter({ has: guestHome.getByText(tileName, { exact: true }) })
  const expectTileOrder = async (firstName: string, secondName: string) => {
    await expect
      .poll(async () => {
        const texts = await homeTiles.allInnerTexts()
        const hasLine = (text: string, line: string) =>
          text
            .split(/[\r\n\t]+/)
            .map((part) => part.trim())
            .includes(line)
        const firstIndex = texts.findIndex((text) => hasLine(text, firstName))
        const secondIndex = texts.findIndex((text) => hasLine(text, secondName))
        return firstIndex !== -1 && secondIndex === firstIndex + 1
      })
      .toBe(true)
  }

  await test.step('a guest keeps the storefront open from the start', async () => {
    await gotoPage(expect, guestHome, '/')
    await markDocument(guestHome)
  })

  await grantAdminToSelf(expect, page)
  const rowsOf = (tab: Page, categoryName = name) =>
    tab
      .getByTestId(CATEGORIES_TABLE)
      .locator(ROWS)
      .filter({ has: tab.getByText(categoryName, { exact: true }) })
  // The table's window is as tall as the screen allows, so "last" is read on
  // the last page of the catalog order rather than in the first window.
  const expectLastRow = async (tab: Page, categoryName = name) => {
    await goToLastPage(expect, tab, CATEGORIES_TABLE)
    await expect(
      tab.getByTestId(CATEGORIES_TABLE).locator(ROWS).last(),
    ).toContainText(categoryName)
  }
  const expectTablePair = async (firstName: string, secondName: string) => {
    await goToLastPage(expect, page, CATEGORIES_TABLE)
    await expect
      .poll(async () => {
        const texts = await page
          .getByTestId(CATEGORIES_TABLE)
          .locator(ROWS)
          .allInnerTexts()
        const hasLine = (text: string, line: string) =>
          text
            .split(/[\r\n\t]+/)
            .map((part) => part.trim())
            .includes(line)
        const firstIndex = texts.findIndex((text) => hasLine(text, firstName))
        const secondIndex = texts.findIndex((text) => hasLine(text, secondName))
        return firstIndex !== -1 && secondIndex === firstIndex + 1
      })
      .toBe(true)
  }
  const dialog = page.getByTestId('admin-categories-add')
  const preview = dialog.getByTestId('admin-categories-add-preview')
  const choosePicture = async (mimeType: 'image/jpeg' | 'image/png') => {
    await dialog
      .getByTestId('admin-categories-add-picture-input')
      .setInputFiles({
        name: mimeType === 'image/png' ? 'picture.png' : 'picture.jpg',
        mimeType,
        buffer: await drawCatalogPicture(page, mimeType),
      })
  }

  await test.step('the admin creates an empty anchor category before the second tab subscribes', async () => {
    await gotoPage(expect, page, CATEGORIES_PATH)
    await page.getByTestId('hilos-table-main-action').click()
    await expect(dialog).toBeVisible()
    await typeInto(dialog.getByTestId('admin-categories-add-name'), anchorName)
    await typeInto(
      dialog.getByTestId('admin-categories-add-description'),
      'Anchor category for the e2e scenario',
    )
    await choosePicture('image/png')
    await expect(preview).toBeVisible()
    await clickSubmit(expect, page.getByTestId('admin-categories-add-submit'))
    await expect(dialog).toBeHidden()
    await expectLastRow(page, anchorName)

    const anchorTile = homeTile(anchorName)
    await expect(anchorTile).toBeVisible()
    await expect(anchorTile).toContainText('0 products')
    await expect(anchorTile).toHaveAttribute('href', `/category/${anchorSlug}`)
    await expect(guestHome).toHaveURL('/')
    await expectDocumentUnchanged(expect, guestHome)
  })

  const second = await page.context().newPage()
  let total = 0

  await test.step('both admin tabs subscribe to Categories before the tested Add', async () => {
    await gotoPage(expect, second, CATEGORIES_PATH)
    await expect(second.getByTestId(CATEGORIES_TABLE)).toBeVisible()
    total = await tableTotal(expect, page)
    await expectTableTotal(expect, second, total)
  })

  await test.step('the picture is published on upload and its copy shows', async () => {
    await dismissToasts(page)
    await page.getByTestId('hilos-table-main-action').click()
    await expect(dialog).toBeVisible()
    await typeInto(dialog.getByTestId('admin-categories-add-name'), name)
    await typeInto(
      dialog.getByTestId('admin-categories-add-description'),
      'Added by the e2e scenario',
    )
    await choosePicture('image/png')
    await expect(preview).toBeVisible()
    await expect
      .poll(() => preview.evaluate((img: HTMLImageElement) => img.naturalWidth))
      .toBeGreaterThan(0)
  })

  await test.step('Add closes the dialog, the row lands last in both admin tabs, and the open storefront shows 0 products after the anchor', async () => {
    await clickSubmit(expect, page.getByTestId('admin-categories-add-submit'))
    await expect(dialog).toBeHidden()
    await expectTableTotal(expect, page, total + 1)
    await expectLastRow(page, name)

    await expectTableTotal(expect, second, total + 1)
    await expectLastRow(second, name)

    const tile = homeTile(name)
    await expect(tile).toBeVisible()
    await expect(tile).toContainText('0 products')
    await expect(tile).toHaveAttribute('href', categoryPath)
    await expectTileOrder(anchorName, name)
    await expect(guestHome).toHaveURL('/')
    await expectDocumentUnchanged(expect, guestHome)
  })

  await test.step('the same name again is refused inside the dialog', async () => {
    await dismissToasts(page)
    await page.getByTestId('hilos-table-main-action').click()
    await expect(dialog).toBeVisible()
    const nameField = dialog.getByTestId('admin-categories-add-name')
    await typeInto(nameField, name)
    await typeInto(
      dialog.getByTestId('admin-categories-add-description'),
      'Added twice by the e2e scenario',
    )
    await choosePicture('image/jpeg')
    await expect(preview).toBeVisible()
    await clickSubmit(expect, page.getByTestId('admin-categories-add-submit'))
    await expect(
      dialog.getByTestId('admin-categories-add-error'),
    ).toContainText('A category with this name already exists')
    await expect(dialog).toBeVisible()
    await expect(nameField).toHaveValue(name)
    await expect(preview).toBeVisible()
  })

  await test.step('Cancel asks to discard the typing and closes the dialog', async () => {
    await page.getByTestId('admin-categories-add-cancel').click()
    await page.getByTestId('modal-confirm-discard').click()
    await expect(dialog).toBeHidden()
    await expect(rowsOf(page, name)).toHaveCount(1)
  })

  await test.step('adding a temporary stock item in the second admin tab updates the open storefront tile to 1 product', async () => {
    await gotoPage(expect, second, '/hilos/products')
    const adminProducts = second.getByTestId('admin-products-table')
    await adminProducts.getByTestId('hilos-table-main-action').click()

    const productDialog = second.getByRole('dialog', {
      name: 'Add product',
      exact: true,
    })
    await typeInto(
      productDialog.getByRole('textbox', { name: 'Name', exact: true }),
      productName,
    )
    await productDialog
      .getByRole('combobox', { name: 'Category' })
      .selectOption({ label: name })
    await productDialog
      .getByRole('radio', { name: 'Unique piece' })
      .press('ArrowRight')
    await expect(
      productDialog.getByRole('radio', { name: 'Stock item' }),
    ).toBeChecked()
    await typeInto(
      productDialog.getByRole('textbox', { name: moneyFieldLabel('Price') }),
      '410',
    )
    await typeInto(
      productDialog.getByRole('textbox', { name: 'Description' }),
      'Temporary stock item for the category e2e scenario.',
    )

    const [chooser] = await Promise.all([
      second.waitForEvent('filechooser'),
      productDialog.getByTestId('admin-products-add-picture').click(),
    ])
    await chooser.setFiles({
      name: 'product.png',
      mimeType: 'image/png',
      buffer: await drawCatalogPicture(second, 'image/png'),
    })
    await expect(
      productDialog.getByTestId('admin-products-add-preview'),
    ).toBeVisible()
    await clickSubmit(
      expect,
      productDialog.getByTestId('admin-products-add-submit'),
    )
    await expect(productDialog).toBeHidden()

    await expect(homeTile(name)).toContainText('1 product')
    await expect(homeTile(anchorName)).toContainText('0 products')
    await expect(guestHome).toHaveURL('/')
    await expectDocumentUnchanged(expect, guestHome)
  })

  const guest = await guestContext.newPage()
  const delivery = watchDeliveryFrames(guest)
  cleanup.defer(() => delivery.stop())
  const heading = guest.getByRole('heading', { level: 1 })
  const products = guest.getByTestId('shop-category-products')
  const productLink = products.getByRole('link', {
    name: productName,
    exact: true,
  })
  const kind = products.getByTestId('hilos-table-filter-kind')
  const kindToggle = kind.getByTestId('hilos-dropdown-toggle')
  const noMatches = shownByTestId(products, 'hilos-table-no-matches')

  const readFirstCategoryResponse = async (slug: string) => {
    const firstResponse = () => {
      const subscribe = delivery.sent.find(({ wire }) => {
        const frame = deliveryObject(wire)
        return (
          frame.type === SIGNAL_TYPE_PAGE_SUBSCRIBE &&
          frame.page === PAGE_CATEGORY &&
          deliveryObject(frame.params).slug === slug
        )
      })
      if (!subscribe) return

      return delivery.packets
        .filter(
          ({ socket, at }) => socket === subscribe.socket && at >= subscribe.at,
        )
        .flatMap(({ frames }) => frames)
        .find(({ wire }) => {
          const frame = deliveryObject(wire)
          return (
            frame.type === SIGNAL_TYPE_PAGE_RESPONSE &&
            deliveryObject(frame.data).page === PAGE_CATEGORY
          )
        })
    }
    await expect
      .poll(firstResponse, {
        message: `First category page response for ${slug}`,
      })
      .toBeDefined()
    const response = firstResponse()!
    expect(response.failure).toBeUndefined()
    const payload = deliveryObject(
      deliveryObject(deliveryObject(response.wire).data).payload,
    )
    const category = deliveryObject(deliveryObject(payload.data).category)
    expect(category.slug).toBe(slug)
    return {
      categoryId: category.id,
      window: deliveryObject(
        deliveryObject(payload.windows)[CATEGORY_PRODUCTS_TABLE],
      ),
    }
  }

  const categoryId =
    await test.step('a guest follows the category link from the storefront and sees the stock item', async () => {
      await gotoPage(expect, guest, '/')
      await guest
        .getByTestId('shop-home-tile')
        .filter({ has: guest.getByText(name, { exact: true }) })
        .click()
      await expect(guest).toHaveURL(categoryPath)
      await expectPageReady(expect, guest)
      await expect(heading).toHaveText(name)
      await markDocument(guest)

      const { categoryId, window } =
        await readFirstCategoryResponse(categorySlug)
      expect(Number.isInteger(categoryId)).toBe(true)
      expect(categoryId).toBeGreaterThan(0)
      expect(window.filter).toEqual({ [FILTER_CATEGORY]: categoryId })
      expect(Array.isArray(window.rows)).toBe(true)
      expect(window.rows).toHaveLength(1)
      await expect(productLink).toBeVisible()
      return categoryId
    })

  await test.step('the guest filters the category by Kind to hide the stock item', async () => {
    await expect(kindToggle).toBeEnabled()
    await expect(kindToggle).toHaveText('Kind: Any')
    await kindToggle.click()
    await kind.getByRole('option', { name: /^Unique piece\b/ }).click()
    await expect(kindToggle).toHaveText('Kind: Unique piece')
    await expect(noMatches).toBeVisible()
    await expect(productLink).toHaveCount(0)
  })

  await test.step('renaming the category updates the open storefront tile and moves the open category page with its Kind filter intact', async () => {
    await dismissToasts(page)
    await rowsOf(page, name).getByTestId('admin-categories-edit-open').click()
    const edit = page.getByTestId('admin-categories-edit')
    await typeInto(edit.getByTestId('admin-categories-edit-name'), renamedName)
    await clickSubmit(expect, page.getByTestId('admin-categories-edit-submit'))
    await expect(edit).toBeHidden()

    const renamedTile = homeTile(renamedName)
    await expect(renamedTile).toBeVisible()
    await expect(renamedTile).toHaveAttribute('href', renamedPath)
    await expect(renamedTile).toContainText('1 product')
    await expect(homeTile(name)).toHaveCount(0)
    await expect(guestHome).toHaveURL('/')
    await expectDocumentUnchanged(expect, guestHome)

    await expect(guest).toHaveURL(renamedPath)
    await expectPageReady(expect, guest)
    await expect(heading).toHaveText(renamedName)
    await expectDocumentUnchanged(expect, guest)

    const moved = await readFirstCategoryResponse(renamedSlug)
    expect(moved.categoryId).toBe(categoryId)
    expect(moved.window.filter).toEqual({
      [FILTER_CATEGORY]: categoryId,
      kind: 'unique',
    })
    expect(moved.window.rows).toEqual([])

    await expect(kindToggle).toHaveText('Kind: Unique piece')
    await expect(noMatches).toBeVisible()
    await expect(products.locator(ROWS)).toHaveCount(0)
    await expect(products.getByRole('listitem')).toHaveCount(0)
    await expect(productLink).toHaveCount(0)

    await kindToggle.click()
    await kind.getByRole('option', { name: /^Any\b/ }).click()
    await expect(kindToggle).toHaveText('Kind: Any')
    await expect(productLink).toBeVisible()
    await expectDocumentUnchanged(expect, guest)
  })

  await test.step('opening the former category link in a new tab reaches its current address', async () => {
    const fresh = await guestContext.newPage()
    await gotoPage(expect, fresh, categoryPath, PAGE_READY)
    await expect(fresh).toHaveURL(renamedPath)
    await expect(fresh.getByRole('heading', { level: 1 })).toHaveText(
      renamedName,
    )
    await fresh.close()
  })

  await test.step('one Back returns from the moved category to the storefront', async () => {
    await guest.goBack()
    await expect(guest).toHaveURL('/')
    await expectPageReady(expect, guest)
    await expectDocumentUnchanged(expect, guest)
  })

  await test.step('Move up swaps the two test categories and preserves their product counts on the open storefront', async () => {
    await expectTablePair(anchorName, renamedName)
    await expectTileOrder(anchorName, renamedName)

    await dismissToasts(page)
    await clickSubmit(
      expect,
      rowsOf(page, renamedName).getByTestId(
        `admin-categories-move-up-${String(categoryId)}`,
      ),
    )

    await expectTablePair(renamedName, anchorName)
    await expectTileOrder(renamedName, anchorName)
    await expect(homeTile(renamedName)).toContainText('1 product')
    await expect(homeTile(anchorName)).toContainText('0 products')
    await expect(guestHome).toHaveURL('/')
    await expectDocumentUnchanged(expect, guestHome)
  })

  await test.step('removing the temporary product updates the tile to 0 products and allows deleting the category live', async () => {
    await command(PRODUCT_DELETE_COMMAND, { name: productName })
    await expect(homeTile(renamedName)).toContainText('0 products')
    await expect(guestHome).toHaveURL('/')
    await expectDocumentUnchanged(expect, guestHome)

    await dismissToasts(page)
    await rowsOf(page, renamedName)
      .getByTestId('admin-categories-edit-open')
      .click()
    const edit = page.getByTestId('admin-categories-edit')
    await expect(edit).toBeVisible()
    const deleteButton = page.getByTestId('admin-categories-edit-delete')
    await expect(deleteButton).toBeEnabled()
    await deleteButton.click()
    await clickSubmit(
      expect,
      page.getByTestId('admin-categories-delete-confirm'),
    )
    await expect(edit).toBeHidden()

    await expect(homeTile(renamedName)).toHaveCount(0)
    await expect(homeTile(anchorName)).toBeVisible()
    await expect(homeTile(anchorName)).toContainText('0 products')
    await expect(guestHome).toHaveURL('/')
    await expectDocumentUnchanged(expect, guestHome)
  })
})
