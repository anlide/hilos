import { TableViewportController, bindTableViewport } from '@hilos/core/base'

import { connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'
import { type EntityFieldsResolver } from '../../entityFields/entityFields.js'
import {
  CATEGORY_PRODUCTS_FRAME,
  CATEGORY_PRODUCTS_TABLE,
  FILTER_CATEGORY,
  resolveCategoryProductRow,
} from './categoryProducts.js'
import { type CategoryProductRow } from './types/tables/CategoryProductRow.js'

const fieldsOf: EntityFieldsResolver = (ref) =>
  scopes.entitySignal(ref).get()?.fields

/** The storefront table controller and its mount lifecycle. */
export interface CategoryProductsTable {
  /** The one server window shared by Table and Tiles. */
  readonly controller: TableViewportController<CategoryProductRow>
  /** Bind this table to the page's window stream. */
  start(): void
  /** Permanently release this controller and its bindings. */
  dispose(): void
}

/**
 * Create a storefront table whose filter reset keeps the category constraint.
 *
 * @param page The page mounting the table.
 * @param categoryId The numeric category id used by the server window.
 */
export function createCategoryProductsTable(
  page: string,
  categoryId: number,
): CategoryProductsTable {
  const controller = new TableViewportController<CategoryProductRow>({
    resolve: (row) => resolveCategoryProductRow(row, fieldsOf),
    frame: CATEGORY_PRODUCTS_FRAME,
    initialFilter: { [FILTER_CATEGORY]: categoryId },
    sendViewport: (descriptor) =>
      connection.sendTableViewport(page, CATEGORY_PRODUCTS_TABLE, descriptor),
    sendRendered: (rendered) =>
      connection.sendTableRendered(page, CATEGORY_PRODUCTS_TABLE, rendered),
    sendFacets: (facets) =>
      connection.sendTableFacets(page, CATEGORY_PRODUCTS_TABLE, facets),
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
        { page, tableKey: CATEGORY_PRODUCTS_TABLE },
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
