import { test, expect } from '../test.js'
import { gotoPage } from '../../../../../framework/frontend/e2e/index.js'

// spec-owner: demo — the shop's own storefront

// The shop front e2e: what a visitor finds on the shop's pages. The data is the
// catalog a database reset opens the shop on — six categories of five products
// each, every one of a kind and in a warehouse — so every product counts on
// the shop front. Neither the look of a tile nor the order of the tiles is
// asserted here: the order is the selector's, proved by its unit test.
test("a guest finds the shop's categories on the home, each with its count and the address of its page", async ({
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
})
