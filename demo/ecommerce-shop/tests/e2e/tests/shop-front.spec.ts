import { test, expect } from '../test.js'
import { PAGE_PRODUCT } from '../../../frontend/src/pages/keys.js'
import {
  PAGE_READY,
  PAGE_REFUSED,
  expectDocumentUnchanged,
  expectPageReady,
  gotoPage,
  markDocument,
  navigateByHistory,
  watchPageErrorFrames,
} from '../../../../../framework/frontend/e2e/index.js'

// spec-owner: demo — the shop's own storefront

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
