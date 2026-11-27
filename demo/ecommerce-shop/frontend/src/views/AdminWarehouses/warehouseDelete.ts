import { type WarehouseRow } from './types/tables/WarehouseRow.js'

/** The explanation why a warehouse with remaining products cannot be deleted. */
export const WAREHOUSE_STOCK_REASON =
  'This warehouse keeps stock and cannot be deleted'

/**
 * Read a warehouse row's deletion hint. Deletion is disabled while the row is
 * missing or when it keeps stock; the stock explanation is attached only when
 * a live row has a non-zero product count.
 *
 * @param row The live row, or undefined when the warehouse has disappeared.
 */
export function warehouseRowDelete(row: WarehouseRow | undefined): {
  disabled: boolean
  reason: string | null
} {
  const hasStock = row !== undefined && row.productCount !== 0

  return {
    disabled: row === undefined || hasStock,
    reason: hasStock ? WAREHOUSE_STOCK_REASON : null,
  }
}
