import { expect, test as base } from '../../test.js'
import {
  cleanupFixtures,
  clusterPlacementNode,
  expectClusterSingleAgents,
  gotoPage,
  readClusterBrowserStand,
  requestClusterNodeStop,
  typeInto,
  type TestCleanup,
} from '../../../../../../framework/frontend/e2e/index.js'
import {
  expectWarehouseTotals,
  markCatalogWindow,
  openCatalogAdmin,
  productStockRow,
  saveCatalogEdit,
  setStockUnits,
  warehouseRow,
  watchCatalogPage,
} from '../catalog.js'

// spec-owner: demo — the shop's catalog libraries, edit forms and warehouse totals.

const test = base.extend<{ cleanup: TestCleanup }>({ cleanup: cleanupFixtures })
const stand = readClusterBrowserStand()
const libraries = [
  'warehouses_library',
  'categories_library',
  'products_library',
  'stock_library',
]
const productId = 17
const warehouseId = 3
const stockPath = `/hilos/product/${productId}/stock`

// The first positive stock for product 17 at warehouse 3 adds one product and seven units.
const expectedWarehouseTotals = {
  1: { productCount: 19, unitCount: 546 },
  2: { productCount: 16, unitCount: 361 },
  3: { productCount: 12, unitCount: 181 },
}

test('catalog edits reach an administrator on the other master', async ({
  browser,
  cleanup,
}) => {
  await test.step('each catalog library runs once on a worker', async () => {
    await expectClusterSingleAgents(expect, stand, libraries)
    for (const id of libraries) {
      expect(['s1', 's2'], id).toContain(
        await clusterPlacementNode(expect, stand, id),
      )
    }
  })

  const author = await openCatalogAdmin(browser, stand, stand.leader, cleanup)
  const observer = await openCatalogAdmin(
    browser,
    stand,
    stand.follower,
    cleanup,
  )
  expect(author.userId).not.toBe(observer.userId)

  await test.step('warehouse 3 receives its new address on both masters', async () => {
    const address = 'Odesa, 7 Chornomorska Dorohy St'
    await gotoPage(expect, observer.page, '/hilos/warehouses')
    const observedRow = warehouseRow(observer.page, warehouseId)
    await expect(
      observedRow.getByText('Odesa, 4 Chornomorska Dorohy St', { exact: true }),
    ).toBeVisible()
    await gotoPage(expect, author.page, '/hilos/warehouses')
    const row = warehouseRow(author.page, warehouseId)
    await row.getByTestId('admin-warehouses-edit-open').click()
    const edit = author.page.getByTestId('admin-warehouses-edit')
    await typeInto(edit.getByTestId('admin-warehouses-edit-address'), address)
    const unchanged = await markCatalogWindow(observer)
    await saveCatalogEdit(
      author,
      author.page.getByTestId('admin-warehouses-edit-submit'),
      'warehouse_update',
    )
    await expect(edit).toBeHidden()
    await expect(row.getByText(address, { exact: true })).toBeVisible()
    await expect(observedRow.getByText(address, { exact: true })).toBeVisible()
    await unchanged()
  })

  // Leave Warehouses subscribed while the other tab follows the category and stock rows.
  const tab = await observer.page.context().newPage()
  const detail = await watchCatalogPage(tab, stand, stand.follower, cleanup)

  await test.step('category 1 changes name in the open catalog window', async () => {
    const name = 'Seasonal bouquets'
    await gotoPage(expect, tab, '/hilos/categories')
    const observedRow = tab
      .getByTestId('admin-categories-table')
      .getByTestId('hilos-table-row-1')
    await expect(
      observedRow.getByText('Bouquets', { exact: true }),
    ).toBeVisible()
    await gotoPage(expect, author.page, '/hilos/categories')
    const row = author.page
      .getByTestId('admin-categories-table')
      .getByTestId('hilos-table-row-1')
    await row.getByTestId('admin-categories-edit-open').click()
    const edit = author.page.getByTestId('admin-categories-edit')
    await typeInto(edit.getByTestId('admin-categories-edit-name'), name)
    const unchanged = await markCatalogWindow(detail)
    await saveCatalogEdit(
      author,
      author.page.getByTestId('admin-categories-edit-submit'),
      'category_update',
    )
    await expect(edit).toBeHidden()
    await expect(row.getByText(name, { exact: true })).toBeVisible()
    await expect(observedRow.getByText(name, { exact: true })).toBeVisible()
    await unchanged()
  })

  await test.step('setting seven units updates both Stock and Warehouses on the other master', async () => {
    await gotoPage(expect, tab, stockPath)
    const row = productStockRow(tab, warehouseId)
    await expect(
      row.getByRole('cell', { name: '0', exact: true }),
    ).toBeVisible()
    await expectWarehouseTotals(observer.page, warehouseId, {
      productCount: 11,
      unitCount: 174,
    })
    await gotoPage(expect, author.page, stockPath)
    const stockUnchanged = await markCatalogWindow(detail)
    const warehousesUnchanged = await markCatalogWindow(observer)
    await setStockUnits(author, warehouseId, 7)
    await expect(
      row.getByRole('cell', { name: '7', exact: true }),
    ).toBeVisible()
    await expectWarehouseTotals(
      observer.page,
      warehouseId,
      expectedWarehouseTotals[warehouseId],
    )
    await stockUnchanged()
    await warehousesUnchanged()
  })

  const previousStockHost = await clusterPlacementNode(
    expect,
    stand,
    'stock_library',
  )
  expect(['s1', 's2']).toContain(previousStockHost)
  requestClusterNodeStop(
    stand,
    previousStockHost,
    'Move the active stock library to the remaining worker',
    {
      previousStockHost,
      productId,
      warehouseId,
      expectedWarehouseTotals,
    },
    'stock_library',
  )
})
