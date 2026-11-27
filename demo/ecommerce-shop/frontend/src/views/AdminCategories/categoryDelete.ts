import { type CategoryRow } from './types/tables/CategoryRow.js'

/** The explanation why a category with remaining products cannot be deleted. */
export const CATEGORY_PRODUCTS_REASON =
  'This category has products and cannot be deleted'

/**
 * Read a category row's deletion hint. Deletion is disabled while the row is
 * missing or when it holds products; the products explanation is attached only
 * when a live row has a non-zero product count.
 *
 * @param row The live row, or undefined when the category has disappeared.
 */
export function categoryRowDelete(row: CategoryRow | undefined): {
  disabled: boolean
  reason: string | null
} {
  const hasProducts = row !== undefined && row.productCount !== 0

  return {
    disabled: row === undefined || hasProducts,
    reason: hasProducts ? CATEGORY_PRODUCTS_REASON : null,
  }
}
