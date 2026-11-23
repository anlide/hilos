import type { Browser, Locator, Page, TestInfo } from '@playwright/test'

import { expect } from '../test.js'
import {
  clickSubmit,
  expectDocumentUnchanged,
  grantAdminToSelf,
  markDocument,
  newClusterMasterPage,
  trackedActionReplies,
  typeInto,
  watchClusterMasterUpgrades,
  watchDeliveryFrames,
  type ClusterBrowserStand,
  type TestCleanup,
} from '../../../../../framework/frontend/e2e/index.js'

/** Observe the selected master and physical sockets from before the first navigation. */
export async function watchCatalogPage(
  page: Page,
  stand: ClusterBrowserStand,
  master: string,
  cleanup: TestCleanup,
) {
  const delivery = watchDeliveryFrames(page)
  cleanup.defer(delivery.stop)
  const upgrades = await watchClusterMasterUpgrades(expect, page, stand, master)
  cleanup.defer(upgrades.stop)
  return { page, delivery, upgrades }
}

type CatalogPage = Awaited<ReturnType<typeof watchCatalogPage>>

/** Open an independent administrator on the selected master. */
export async function openCatalogAdmin(
  browser: Browser,
  stand: ClusterBrowserStand,
  master: string,
  cleanup: TestCleanup,
  screenshot?: { info: TestInfo; name: string },
) {
  const page = await newClusterMasterPage(browser, stand, master)
  cleanup.defer(() => page.context().close())
  if (screenshot) {
    cleanup.defer(async () =>
      screenshot.info.attach(screenshot.name, {
        body: await page.screenshot(),
        contentType: 'image/png',
      }),
    )
  }
  const admin = await watchCatalogPage(page, stand, master, cleanup)
  const userId = await grantAdminToSelf(expect, page)
  await admin.upgrades.assert()
  return { ...admin, userId }
}

/** Bookmark a subscribed window, then check the same live socket after delivery. */
export async function markCatalogWindow({
  page,
  delivery,
  upgrades,
}: CatalogPage) {
  await markDocument(page)
  await upgrades.assert()
  const original = delivery.connections.at(-1)
  expect(original, 'The subscribed window has an open socket').toBeDefined()
  expect(original?.closed).toBeUndefined()
  return async () => {
    await expectDocumentUnchanged(expect, page)
    expect(delivery.connections.at(-1)).toBe(original)
    expect(original?.closed, 'The original socket stayed open').toBeUndefined()
  }
}

/** Submit once and match the server reply to this form's request. */
export async function saveCatalogEdit(
  admin: CatalogPage,
  submit: Locator,
  action: string,
) {
  await admin.upgrades.assert()
  const since = admin.delivery.sent.length
  await clickSubmit(expect, submit)
  await expect
    .poll(() =>
      trackedActionReplies(admin.delivery, action, since).map(
        ({ frame }) => frame.type,
      ),
    )
    .toEqual(['action_success'])
}

export function warehouseRow(page: Page, warehouseId: number) {
  return page
    .getByTestId('admin-warehouses-table')
    .getByTestId(`hilos-table-row-${warehouseId}`)
}

export function productStockRow(page: Page, warehouseId: number) {
  return page
    .getByTestId('admin-product-stock-table')
    .getByTestId(`hilos-table-row-${warehouseId}`)
}

/** Check the Products and Units cells in the warehouse table's declared order. */
export async function expectWarehouseTotals(
  page: Page,
  warehouseId: number,
  totals: { productCount: number; unitCount: number },
) {
  const counts = warehouseRow(page, warehouseId)
    .getByRole('cell')
    .filter({ hasText: /^\d+$/ })
  await expect(counts).toHaveText([
    String(totals.productCount),
    String(totals.unitCount),
  ])
}

export async function setStockUnits(
  admin: CatalogPage,
  warehouseId: number,
  units: number,
) {
  const row = productStockRow(admin.page, warehouseId)
  await row.getByTestId('admin-product-stock-edit-open').click()
  const dialog = admin.page.getByRole('dialog', {
    name: 'Set stock',
    exact: true,
  })
  await typeInto(
    dialog.getByTestId('admin-product-stock-edit-units'),
    String(units),
  )
  await saveCatalogEdit(
    admin,
    dialog.getByTestId('admin-product-stock-edit-submit'),
    'product_stock_set_units',
  )
  await expect(dialog).toBeHidden()
  await expect(
    row.getByRole('cell', { name: String(units), exact: true }),
  ).toBeVisible()
}
