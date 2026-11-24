import { test as base, expect } from '../test.js'
import { PAGE_PRODUCT } from '../../../frontend/src/pages/keys.js'
import { SIGNAL_TYPE_ACTION_SUCCESS } from '../../../../../framework/frontend/core/src/protocol/constants.js'
import {
  PAGE_READY,
  PAGE_REFUSED,
  cleanupFixtures,
  expectDocumentUnchanged,
  expectPageReady,
  gotoPage,
  markDocument,
  navigateByHistory,
  sessionToken,
  trackedActionReplies,
  type TestCleanup,
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

  await test.step('see the server refusal and its reason for an unknown product', async () => {
    const refusals = watchPageErrorFrames(page)
    await gotoPage(expect, page, '/product/unknown-product', PAGE_REFUSED)
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
    const error = page.getByTestId('page-error')
    await expect(error).toHaveAttribute('data-error-code', '404')
    await expect(error.getByText(title, { exact: true })).toBeVisible()
    await expect(error.getByText(message, { exact: true })).toBeVisible()
  })
})

// Browser traces can include the guest's session cookie.
const guestCartTest = test.extend({ trace: 'off' })

function deferGuestCartCleanup(
  cleanup: TestCleanup,
  token: string,
  productId: number,
  addFinished: () => boolean | Promise<boolean>,
): void {
  const command = createStandCommandChannel({ timeoutMs: 5_000 })
  cleanup.defer(async () => {
    // The browser can send the add before the observer receives framesent.
    let unconfirmed: Error | undefined
    try {
      await expect
        .poll(addFinished, {
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
        productId,
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
}

guestCartTest(
  'a guest adds from the category table and opens the cart from tiles',
  async ({ browser, cleanup }) => {
    // The product ID in 001_hilos_flowers.sql identifies the cleanup target.
    const morningMeadow = { id: 1, name: 'Morning meadow' }
    const guestContext = await browser.newContext({ colorScheme: 'light' })
    cleanup.defer(() => guestContext.close())

    const guest = await guestContext.newPage()
    const delivery = watchDeliveryFrames(guest)
    cleanup.defer(() => delivery.stop())
    await gotoPage(expect, guest, '/category/bouquets')
    await expect(
      guest.getByTestId('nav-cart').getByTestId('cart-badge'),
    ).toHaveText('0')

    const products = guest.getByTestId('shop-category-products')
    const productLink = guest.getByRole('link', {
      name: morningMeadow.name,
      exact: true,
    })
    await products.getByTestId('hilos-table-view-table').click()
    const row = products.getByRole('table').getByRole('row').filter({
      has: productLink,
    })
    const tile = products.getByRole('listitem').filter({ has: productLink })

    const token = await sessionToken(guest)
    const since = delivery.sent.length
    const replies = () =>
      trackedActionReplies(delivery, 'category_add_to_cart', since)
    const cartLink = tile.getByTestId('shop-category-go-to-cart')
    const rowCartLink = row.getByTestId('shop-category-go-to-cart')
    deferGuestCartCleanup(
      cleanup,
      token,
      morningMeadow.id,
      async () =>
        replies().length > 0 ||
        (await rowCartLink.isVisible()) ||
        (await cartLink.isVisible()),
    )
    await row.getByTestId('shop-category-add-to-cart').click()
    await expect.poll(() => replies().length).toBe(1)
    expect(replies()[0].frame.type).toBe(SIGNAL_TYPE_ACTION_SUCCESS)

    await products.getByTestId('hilos-table-view-tiles').click()
    await expect(cartLink).toHaveText('In cart')
    await cartLink.click()
    await expect(guest).toHaveURL('/cart')
    await expectPageReady(expect, guest)

    const lines = guest.getByTestId('shop-cart-line')
    await expect(lines).toHaveCount(1)
    await expect(lines.getByTestId('shop-cart-product-link')).toHaveText(
      morningMeadow.name,
    )
  },
)
