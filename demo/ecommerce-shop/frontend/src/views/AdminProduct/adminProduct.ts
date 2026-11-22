// Overview's stock summary and live warehouse names. The warehouses list
// normalizes each slot into a page entity, so a rename redraws the summary.
import { readString, type ListItem } from '@hilos/core/base'

import {
  slotFields,
  type EntityFieldsResolver,
} from '../../entityFields/entityFields.js'
import { type AdminProduct } from '../../types/AdminProduct.js'

/** Page list carrying the live warehouses, by number. */
export const ADMIN_PRODUCT_WAREHOUSES_LIST = 'adminProductWarehouses'
/** Entity slot of the warehouses browser list. */
const WAREHOUSE_SLOT = 'warehouses'

/** List payload key of the warehouse id. */
const WAREHOUSE_ID_FIELD = 'id'
/** List payload key of the warehouse name. */
const WAREHOUSE_NAME_FIELD = 'name'

/**
 * Find the name of one warehouse in the live warehouses list.
 *
 * @param items The page-scoped warehouses list.
 * @param id The warehouse's number.
 * @param fieldsOf The resolver of a slot's reference to its fields.
 * @returns The warehouse's name, or null while the list or the scope does not
 * hold it.
 */
export function warehouseNameOf(
  items: readonly ListItem[],
  id: number,
  fieldsOf: EntityFieldsResolver,
): string | null {
  for (const item of items) {
    const warehouse = slotFields(item.slots[WAREHOUSE_SLOT], fieldsOf)
    if (warehouse[WAREHOUSE_ID_FIELD] === id) {
      return readString(warehouse, WAREHOUSE_NAME_FIELD)
    }
  }
  return null
}

/** The words of the stock line: the accent sits between the two plain runs. */
export interface StockParts {
  /** Plain words before the accent. */
  lead: string
  /** The accented number or name, or null when the line carries no accent. */
  strong: string | null
  /** Plain words after the accent. */
  tail: string
}

/**
 * Say what the warehouses hold of the product, split around the accent.
 *
 * @param product The product the page shows.
 * @param warehouseName Name of the warehouse keeping a unique piece, or null
 * while the warehouses list does not hold it.
 * @returns The lead, the accented part and the tail, which read as one line.
 */
export function stockParts(
  product: AdminProduct,
  warehouseName: string | null,
): StockParts {
  const { units, warehouses, warehouseId } = product.stock
  if (product.kind === 'stock') {
    if (units === 0) return { lead: 'Out of stock', strong: null, tail: '' }
    const unitWord = units === 1 ? 'unit' : 'units'
    const warehouseWord = warehouses === 1 ? 'warehouse' : 'warehouses'
    return {
      lead: '',
      strong: String(units),
      tail: ` ${unitWord} in ${warehouses} ${warehouseWord}`,
    }
  }
  if (warehouseId === null) {
    return { lead: 'Not in a warehouse yet', strong: null, tail: '' }
  }
  if (warehouseName === null) {
    return { lead: 'One piece', strong: null, tail: '' }
  }
  return { lead: 'One piece, kept in ', strong: warehouseName, tail: '' }
}
