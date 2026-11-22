import { test, expect } from '../test.js'
import {
  expectPageReady,
  gotoPage,
  grantAdminToSelf,
  PAGE_REFUSED,
} from '../../../../../framework/frontend/e2e/index.js'
import {
  PAGE_ADMIN_PRODUCT,
  PAGE_ADMIN_PRODUCT_STOCK,
} from '../../../frontend/src/pages/keys.js'

// spec-owner: demo — the shop's product pages and warehouse stock.

test('an admin opens and reloads warehouse stock for a stock product, while a unique product refuses Stock', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)

  const overviewPath = '/hilos/app/product/17'
  const stockPath = `${overviewPath}/stock`
  const productName = page.getByRole('heading', { level: 1 })
  const overview = page
    .getByTestId(`hilos-page-tab-${PAGE_ADMIN_PRODUCT}`)
    .and(page.getByRole('link', { name: 'Overview', exact: true }))
  const stock = page
    .getByTestId(`hilos-page-tab-${PAGE_ADMIN_PRODUCT_STOCK}`)
    .and(page.getByRole('link', { name: 'Stock', exact: true }))
  const stockWindow = page.getByTestId('admin-product-stock-table')

  const expectSeededStock = async () => {
    for (const [warehouse, units] of [
      ['Kyiv · Podil', '80'],
      ['Lviv · Sykhiv', '50'],
      ['Odesa · Peresyp', '0'],
    ] as const) {
      const row = stockWindow.getByRole('row').filter({
        has: page.getByRole('cell', { name: warehouse, exact: true }),
      })
      await expect(
        row.getByRole('cell', { name: units, exact: true }),
      ).toBeVisible()
    }
  }

  await test.step('follow Stock from the product Overview', async () => {
    await gotoPage(expect, page, overviewPath)
    await expect(productName).toHaveText('Universal soil mix 10 L')
    await expect(overview).toHaveAttribute('aria-current', 'page')
    await expect(stock).toHaveAttribute('href', stockPath)

    await stock.click()
    await expect(page).toHaveURL(stockPath)
    await expectPageReady(expect, page)
    await expect(productName).toHaveText('Universal soil mix 10 L')
    await expect(stock).toHaveAttribute('aria-current', 'page')
    await expectSeededStock()
  })

  await test.step('reload Stock at its own address', async () => {
    await page.reload()
    await expectPageReady(expect, page)
    await expect(page).toHaveURL(stockPath)
    await expect(stock).toHaveAttribute('aria-current', 'page')
    await expectSeededStock()
  })

  await test.step('follow Overview back to the same product', async () => {
    await expect(overview).toHaveAttribute('href', overviewPath)
    await overview.click()
    await expect(page).toHaveURL(overviewPath)
    await expectPageReady(expect, page)
    await expect(productName).toHaveText('Universal soil mix 10 L')
    await expect(overview).toHaveAttribute('aria-current', 'page')
    await expect(stockWindow).toHaveCount(0)
  })

  await test.step('a unique product refuses its Stock address', async () => {
    const uniquePath = '/hilos/app/product/1'
    await gotoPage(expect, page, uniquePath)
    await expect(productName).toHaveText('Morning meadow')
    await expect(stock).toHaveCount(0)

    await gotoPage(expect, page, `${uniquePath}/stock`, PAGE_REFUSED)
    await expect(page.getByTestId('page-error')).toHaveAttribute(
      'data-error-code',
      '404',
    )
    await expect(stockWindow).toHaveCount(0)
  })
})
