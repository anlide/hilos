// Connection lifecycle for the Products table. The pure row selectors live in
// adminProducts.ts so they can also be read without application bootstrap.
import { TableViewportController, bindTableViewport } from '@hilos/core/base'

import { connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'
import { PAGE_ADMIN_PRODUCTS } from '../../pages/keys.js'
import {
  ADMIN_PRODUCT_CATEGORIES_LIST,
  PRODUCTS_TABLE,
  categoryOptions,
  productsFrame,
  resolveProductRow,
} from './adminProducts.js'
import { type ProductRow } from './types/tables/ProductRow.js'

const categoryItems = scopes.pageListSignal(ADMIN_PRODUCT_CATEGORIES_LIST)

/** The Products table controller and its mount lifecycle. */
export interface ProductsTable {
  /** The server-windowed controller rendered by the view. */
  readonly controller: TableViewportController<ProductRow>
  /** Bind the table on mount. */
  start(): void
  /** Permanently release the controller and its bindings on unmount. */
  dispose(): void
}

/** Create the server-windowed Products table with reactive Category choices. */
export function createProductsTable(): ProductsTable {
  const controller = new TableViewportController<ProductRow>({
    resolve: resolveProductRow,
    frame: productsFrame(() => categoryOptions(categoryItems.get())),
    sendViewport: (descriptor) =>
      connection.sendTableViewport(
        PAGE_ADMIN_PRODUCTS,
        PRODUCTS_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      connection.sendTableRendered(
        PAGE_ADMIN_PRODUCTS,
        PRODUCTS_TABLE,
        rendered,
      ),
    sendFocus: (rowKey) =>
      connection.sendTableRowFocus(PAGE_ADMIN_PRODUCTS, PRODUCTS_TABLE, rowKey),
    sendFacets: (facets) =>
      connection.sendTableFacets(PAGE_ADMIN_PRODUCTS, PRODUCTS_TABLE, facets),
  })
  let off: (() => void) | undefined
  let disposed = false

  return {
    controller,
    start() {
      if (disposed || off !== undefined) return
      off = bindTableViewport(
        connection,
        scopes,
        { page: PAGE_ADMIN_PRODUCTS, tableKey: PRODUCTS_TABLE },
        controller,
      )
    },
    dispose() {
      if (disposed) return
      disposed = true
      off?.()
      off = undefined
      controller.dispose()
    },
  }
}
