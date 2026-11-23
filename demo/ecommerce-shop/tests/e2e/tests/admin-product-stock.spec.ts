import type { Page } from '@playwright/test'

import { test as base, expect } from '../test.js'
import {
  cleanupFixtures,
  clickSubmit,
  expectDocumentUnchanged,
  expectPageReady,
  gotoPage,
  grantAdminToSelf,
  markDocument,
  PAGE_REFUSED,
  trackedActionReplies,
  type TestCleanup,
  typeInto,
  watchDeliveryFrames,
} from '../../../../../framework/frontend/e2e/index.js'
import {
  PAGE_ADMIN_PRODUCT,
  PAGE_ADMIN_PRODUCT_STOCK,
} from '../../../frontend/src/pages/keys.js'

// spec-owner: demo — the shop's product pages and warehouse stock.

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })

// The edit test temporarily changes the seeded stock both tests use.
test.describe.configure({ mode: 'default' })

const OVERVIEW_PATH = '/hilos/app/product/17'
const STOCK_PATH = `${OVERVIEW_PATH}/stock`

test('an admin opens and reloads warehouse stock for a stock product, while a unique product refuses Stock', async ({
  page,
}) => {
  await grantAdminToSelf(expect, page)

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
    await gotoPage(expect, page, OVERVIEW_PATH)
    await expect(productName).toHaveText('Universal soil mix 10 L')
    await expect(overview).toHaveAttribute('aria-current', 'page')
    await expect(stock).toHaveAttribute('href', STOCK_PATH)

    await stock.click()
    await expect(page).toHaveURL(STOCK_PATH)
    await expectPageReady(expect, page)
    await expect(productName).toHaveText('Universal soil mix 10 L')
    await expect(stock).toHaveAttribute('aria-current', 'page')
    await expectSeededStock()
  })

  await test.step('reload Stock at its own address', async () => {
    await page.reload()
    await expectPageReady(expect, page)
    await expect(page).toHaveURL(STOCK_PATH)
    await expect(stock).toHaveAttribute('aria-current', 'page')
    await expectSeededStock()
  })

  await test.step('follow Overview back to the same product', async () => {
    await expect(overview).toHaveAttribute('href', OVERVIEW_PATH)
    await overview.click()
    await expect(page).toHaveURL(OVERVIEW_PATH)
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

test('shop.stock-set-units: an admin sets warehouse units and another tab sees the new total', async ({
  page,
  cleanup,
}) => {
  const context = page.context()
  const action = 'product_stock_set_units'
  const delivery = watchDeliveryFrames(page)
  let sourceSaveOpen = true
  let sourceSince: number | undefined
  const sourceReply = () =>
    sourceSince === undefined
      ? undefined
      : trackedActionReplies(delivery, action, sourceSince).at(0)

  const odesaRow = (stockPage: Page) =>
    stockPage
      .getByTestId('admin-product-stock-table')
      .getByRole('row')
      .filter({
        has: stockPage.getByRole('cell', {
          name: 'Odesa · Peresyp',
          exact: true,
        }),
      })

  const setUnits = async (
    stockPage: Page,
    units: string,
    beforeSubmit?: () => void,
  ) => {
    const odesa = odesaRow(stockPage)
    const dialog = stockPage.getByRole('dialog', {
      name: 'Set stock',
      exact: true,
    })
    await odesa.getByTestId('admin-product-stock-edit-open').click()
    await typeInto(dialog.getByTestId('admin-product-stock-edit-units'), units)
    const submit = dialog.getByTestId('admin-product-stock-edit-submit')
    beforeSubmit?.()
    await clickSubmit(expect, submit)
    await expect(dialog).toBeHidden()
    await expect(
      odesa.getByRole('cell', { name: units, exact: true }),
    ).toBeVisible()
  }

  cleanup.defer(() => delivery.stop())
  cleanup.defer(async () => {
    sourceSaveOpen = false
    if (sourceSince === undefined) return

    await test.step('restore the seeded warehouse stock', async () => {
      const restorePage = await context.newPage()
      const repliedBeforeRead = sourceReply() !== undefined
      await gotoPage(expect, restorePage, STOCK_PATH)
      const row = odesaRow(restorePage)
      if (!repliedBeforeRead) {
        const seven = row.getByRole('cell', { name: '7', exact: true })
        await expect
          .poll(
            async () =>
              sourceReply() !== undefined || (await seven.isVisible()),
            {
              message:
                'Stock cleanup needs the original Set stock reply or a fresh view of the written 7 units',
            },
          )
          .toBe(true)
        // A late reply may have arrived after the first Stock snapshot.
        if (sourceReply()) await gotoPage(expect, restorePage, STOCK_PATH)
      }

      await expect(row).toBeVisible()
      const zero = row.getByRole('cell', { name: '0', exact: true })
      if (!(await zero.isVisible())) await setUnits(restorePage, '0')
      await expect(zero).toBeVisible()
    })
  })

  await grantAdminToSelf(expect, page)
  const observer = await context.newPage()
  await gotoPage(expect, page, STOCK_PATH)
  await expect(
    odesaRow(page).getByRole('cell', { name: '0', exact: true }),
  ).toBeVisible()
  await gotoPage(expect, observer, OVERVIEW_PATH)
  const seededTotal = observer.getByText('130 units in 2 warehouses', {
    exact: true,
  })
  await expect(seededTotal).toBeVisible()
  await markDocument(observer)

  await setUnits(page, '7', () => {
    if (!sourceSaveOpen)
      throw new Error('Stock cleanup closed the original Save')
    sourceSince = delivery.sent.length
  })
  await expect.poll(() => sourceReply()?.frame.type).toBe('action_success')
  await expect(
    observer.getByText('137 units in 3 warehouses', { exact: true }),
  ).toBeVisible()
  await expect(observer).toHaveURL(OVERVIEW_PATH)
  await expectDocumentUnchanged(expect, observer)
})
