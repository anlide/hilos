// Pure selectors for one product's admin page. The card is an inline record of
// the adminProduct page data; the names of its category and warehouse are not on
// it — the page reads them by number from its live lists, so a rename redraws.
// A list slot bearing an `id` is normalized into an entity of the page scope,
// leaving a reference in the slot; its fields are read back through the
// resolver the caller hands in.
import {
  readNumber,
  readNumberOrNull,
  readString,
  type ListItem,
} from '@hilos/core/base'

import {
  slotFields,
  type EntityFieldsResolver,
} from '../../entityFields/entityFields.js'
import { type AdminProduct } from './types/AdminProduct.js'

/** Page data carrying the product's card (backend AdminProductBrowserData). */
export const ADMIN_PRODUCT_DATA = 'adminProduct'
/** Page list carrying the live warehouses, by number. */
export const ADMIN_PRODUCT_WAREHOUSES_LIST = 'adminProductWarehouses'
/** Entity slot of the warehouses browser list. */
const WAREHOUSE_SLOT = 'warehouses'

/** Card payload key of the product id. */
const PRODUCT_ID_FIELD = 'productId'
/** Card payload key of the product name. */
const PRODUCT_NAME_FIELD = 'name'
/** Card payload key of the product kind. */
const PRODUCT_KIND_FIELD = 'kind'
/** Card payload key of the product price in kopecks. */
const PRODUCT_PRICE_FIELD = 'price'
/** Card payload key of the product category id. */
const PRODUCT_CATEGORY_ID_FIELD = 'categoryId'
/** Card payload key of the product description. */
const PRODUCT_DESCRIPTION_FIELD = 'description'
/** Card payload key of the large picture address. */
const PRODUCT_PICTURE_URL_FIELD = 'pictureUrl'
/** Card payload key of the stock total record. */
const PRODUCT_STOCK_FIELD = 'stock'
/** Stock payload key of the units in all warehouses. */
const STOCK_UNITS_FIELD = 'units'
/** Stock payload key of the warehouses holding a unit. */
const STOCK_WAREHOUSES_FIELD = 'warehouses'
/** Stock payload key of the warehouse keeping a unique piece. */
const STOCK_WAREHOUSE_ID_FIELD = 'warehouseId'
/** List payload key of the warehouse id. */
const WAREHOUSE_ID_FIELD = 'id'
/** List payload key of the warehouse name. */
const WAREHOUSE_NAME_FIELD = 'name'

/**
 * Read a value as a record, or null when it is not one.
 *
 * @param value The raw value.
 */
function recordOrNull(
  value: unknown,
): Readonly<Record<string, unknown>> | null {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Readonly<Record<string, unknown>>)
    : null
}

/**
 * Resolve the page's card into the view-model.
 *
 * @param raw The raw adminProduct page data.
 * @returns The product, or null when the card is not a record, has no product
 * id or no stock record.
 */
export function resolveAdminProduct(raw: unknown): AdminProduct | null {
  const card = recordOrNull(raw)
  if (card === null || typeof card[PRODUCT_ID_FIELD] !== 'number') return null
  const stock = recordOrNull(card[PRODUCT_STOCK_FIELD])
  if (stock === null) return null

  return {
    productId: readNumber(card, PRODUCT_ID_FIELD),
    name: readString(card, PRODUCT_NAME_FIELD),
    kind: readString(card, PRODUCT_KIND_FIELD),
    price: readNumber(card, PRODUCT_PRICE_FIELD),
    categoryId: readNumber(card, PRODUCT_CATEGORY_ID_FIELD),
    description: readString(card, PRODUCT_DESCRIPTION_FIELD),
    pictureUrl: readString(card, PRODUCT_PICTURE_URL_FIELD),
    stock: {
      units: readNumber(stock, STOCK_UNITS_FIELD),
      warehouses: readNumber(stock, STOCK_WAREHOUSES_FIELD),
      warehouseId: readNumberOrNull(stock, STOCK_WAREHOUSE_ID_FIELD),
    },
  }
}

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
