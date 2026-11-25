import type { Expect, Locator, Page } from '@playwright/test'

import { drawCatalogPicture } from './catalogPictures.js'
import { moneyFieldLabel } from '../../../frontend/src/money/money.js'
import {
  PAGE_READY,
  clickSubmit,
  expectPageReady,
  gotoPage,
  type TestCleanup,
  typeInto,
} from '../../../../../framework/frontend/e2e/index.js'
import { createStandCommandChannel } from '../../../../../framework/frontend/scripts/standCommand.mjs'

// EcommerceShopCommandConstants::PRODUCT_TEST_DELETE, request key `name`.
export const PRODUCT_DELETE_COMMAND = 'test:ecommerce-shop:product-delete'

const COMMAND_TIMEOUT_MS = 5_000

const command = createStandCommandChannel({ timeoutMs: COMMAND_TIMEOUT_MS })

/**
 * Delete a test product fixture by name through the stand command channel.
 *
 * @param name The product name to delete.
 */
export async function deleteCatalogProduct(
  name: string,
): Promise<Record<string, unknown>> {
  return command(PRODUCT_DELETE_COMMAND, { name })
}

/**
 * Register teardown deletion of a test product fixture by name.
 *
 * @param cleanup The test-scoped cleanup registrar.
 * @param name The product name to delete on teardown.
 */
export function deferCatalogProductDelete(
  cleanup: TestCleanup,
  name: string,
): void {
  cleanup.defer(() => deleteCatalogProduct(name))
}

/**
 * Create a stock product with a picture through the admin products dialog and
 * open its product overview page.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page The admin page driving product creation.
 * @param name The product display name.
 * @param categoryName The category label to select.
 * @param description The product description text.
 */
export async function createStockProduct(
  expect: Expect,
  page: Page,
  name: string,
  categoryName: string,
  description: string,
): Promise<{ overviewPath: string; productId: number }> {
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
    .selectOption({ label: categoryName })
  await dialog.getByRole('radio', { name: 'Unique piece' }).press('ArrowRight')
  await expect(dialog.getByRole('radio', { name: 'Stock item' })).toBeChecked()
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
    buffer: await drawCatalogPicture(page, 'image/png'),
  })
  await expect(dialog.getByTestId('admin-products-add-preview')).toBeVisible()
  await clickSubmit(expect, dialog.getByTestId('admin-products-add-submit'))
  await expect(dialog).toBeHidden()
  await products.getByRole('link', { name, exact: true }).click()
  await expectPageReady(expect, page)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(name)
  await expect(page).toHaveURL(/\/hilos\/product\/[1-9]\d*$/)

  const overviewPath = new URL(page.url()).pathname
  return {
    overviewPath,
    productId: Number(overviewPath.split('/').at(-1)),
  }
}

/**
 * Locate the Odesa · Peresyp warehouse row in the admin product stock table.
 *
 * @param stockPage The page displaying the admin product stock table.
 */
export function warehouseRow(stockPage: Page): Locator {
  return stockPage
    .getByTestId('admin-product-stock-table')
    .getByRole('row')
    .filter({
      has: stockPage.getByRole('cell', {
        name: 'Odesa · Peresyp',
        exact: true,
      }),
    })
}

/**
 * Open the Set stock dialog on the Odesa · Peresyp row and save the given units.
 *
 * @param expect The calling spec's Playwright expect.
 * @param stockPage The page displaying the admin product stock table.
 * @param units The stock quantity text to submit.
 */
export async function setStock(
  expect: Expect,
  stockPage: Page,
  units: string,
): Promise<void> {
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

/**
 * Open a fresh admin tab on the product stock path, run a callback once the
 * warehouse row is visible, and close the tab afterwards.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page A page in the admin browser context.
 * @param path The product stock route path to open.
 * @param read Callback invoked with the ready stock page.
 */
export async function withStockPage<T>(
  expect: Expect,
  page: Page,
  path: string,
  read: (stockPage: Page) => Promise<T>,
): Promise<T> {
  const stockPage = await page.context().newPage()
  try {
    await gotoPage(expect, stockPage, path, PAGE_READY)
    await expect(warehouseRow(stockPage)).toBeVisible()

    return await read(stockPage)
  } finally {
    await stockPage.close()
  }
}
