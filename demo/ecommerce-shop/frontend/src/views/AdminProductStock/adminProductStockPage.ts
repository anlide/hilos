import {
  TableViewportController,
  bindTableViewport,
  readNumber,
  readNumberOrNull,
  readString,
  type HilosTableColumnOf,
  type TableRow,
} from '@hilos/core/base'

import { connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'
import { slotFields } from '../../entityFields/entityFields.js'
import { PAGE_ADMIN_PRODUCT_STOCK } from '../../pages/keys.js'
import { type ProductStockRow } from './types/tables/ProductStockRow.js'

const PRODUCT_STOCK_TABLE = 'productStock'
const STOCK_SLOT = 'stock'

/** Row payload key of the warehouse id. */
const STOCK_WAREHOUSE_ID_FIELD = 'warehouseId'
/** Row payload key of the warehouse name, also the sort field. */
export const STOCK_WAREHOUSE_NAME_FIELD = 'warehouseName'
/** Row payload key of the units held in the warehouse for this product. */
export const STOCK_UNITS_FIELD = 'units'

const columns: HilosTableColumnOf<ProductStockRow>[] = [
  {
    key: STOCK_WAREHOUSE_NAME_FIELD,
    label: 'Warehouse',
    sortable: true,
    card: 'title',
  },
  {
    key: STOCK_UNITS_FIELD,
    label: 'Units',
    headerClass: 'text-end',
    cellClass: 'text-end',
  },
]

function resolveProductStockRow(row: TableRow): ProductStockRow {
  const slot = row.slots[STOCK_SLOT]
  // The wire slot has warehouseId, so it stays inline through normalization.
  const stock =
    typeof slot === 'object' &&
    slot !== null &&
    STOCK_WAREHOUSE_ID_FIELD in slot
      ? slot
      : slotFields(slot, (ref) => scopes.entitySignal(ref).get()?.fields)

  return {
    warehouseId:
      readNumberOrNull(stock, STOCK_WAREHOUSE_ID_FIELD) ?? Number(row.rowKey),
    warehouseName: readString(stock, STOCK_WAREHOUSE_NAME_FIELD),
    units: readNumber(stock, STOCK_UNITS_FIELD),
  }
}

/** The stock viewport and its binding to the current page subscription. */
export interface ProductStockTable {
  /** The server-windowed controller rendered by the page. */
  readonly controller: TableViewportController<ProductStockRow>
  /** Bind the controller and consume the first window from the page response. */
  start(): void
  /** Release the binding and the controller when this product's view leaves. */
  dispose(): void
}

/**
 * Create the live stock viewport for the addressed product.
 *
 * @param productId The product id already matched to the current route.
 */
export function createProductStockTable(productId: number): ProductStockTable {
  const controller = new TableViewportController<ProductStockRow>({
    resolve: resolveProductStockRow,
    initialFilter: { product: productId },
    frame: {
      title: 'Stock',
      search: { placeholder: 'Search warehouses' },
      columns,
      empty: { title: 'No warehouses yet' },
    },
    sendViewport: (descriptor) =>
      connection.sendTableViewport(
        PAGE_ADMIN_PRODUCT_STOCK,
        PRODUCT_STOCK_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      connection.sendTableRendered(
        PAGE_ADMIN_PRODUCT_STOCK,
        PRODUCT_STOCK_TABLE,
        rendered,
      ),
    sendFocus: (rowKey) =>
      connection.sendTableRowFocus(
        PAGE_ADMIN_PRODUCT_STOCK,
        PRODUCT_STOCK_TABLE,
        rowKey,
      ),
  })
  let unbind: (() => void) | undefined

  return {
    controller,
    start() {
      unbind = bindTableViewport(
        connection,
        scopes,
        { page: PAGE_ADMIN_PRODUCT_STOCK, tableKey: PRODUCT_STOCK_TABLE },
        controller,
      )
    },
    dispose() {
      controller.releaseFocus()
      unbind?.()
      controller.dispose()
    },
  }
}
