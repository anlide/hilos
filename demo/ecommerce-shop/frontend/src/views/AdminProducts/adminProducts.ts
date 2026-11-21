// Pure selectors and table declarations for the shop's Products section.
// The product and categories slots carry records bearing an `id`, so the
// normalizer stores each one as an entity of the page scope and leaves a
// reference in the slot; the fields are read back through the resolver the
// caller hands in, keeping this module free of the application bootstrap.
import {
  readNumber,
  readString,
  readStringOrNull,
  type HilosTableColumnOf,
  type HilosTableFilterOption,
  type HilosTableFrame,
  type ListItem,
  type TableRow,
} from '@hilos/core/base'

import {
  slotFields,
  type EntityFieldsResolver,
} from '../../entityFields/entityFields.js'
import { type ProductRow } from './types/tables/ProductRow.js'

/** Table key declared by EcommerceShopTableContext::products. */
export const PRODUCTS_TABLE = 'products'
/** Entity row slot declared by ProductsTable::ROW_SLOT. */
export const PRODUCT_SLOT = 'product'
/** Page list carrying the Category filter's live options. */
export const ADMIN_PRODUCT_CATEGORIES_LIST = 'adminProductCategories'
/** Entity slot of the categories browser list. */
const CATEGORY_SLOT = 'categories'
/** Category filter key accepted by ProductsTable. */
export const FILTER_CATEGORY = 'category'
/** Kind filter key accepted by ProductsTable. */
export const FILTER_KIND = 'kind'
/** Availability filter key accepted by ProductsTable. */
export const FILTER_AVAILABILITY = 'availability'

/** Row payload key of the product id. */
const PRODUCT_ID_FIELD = 'id'
/** Row payload key of the product name. */
export const PRODUCT_NAME_FIELD = 'name'
/** Row payload key of the product picture address. */
const PRODUCT_PICTURE_URL_FIELD = 'pictureUrl'
/** Row payload key of the product category id. */
const PRODUCT_CATEGORY_ID_FIELD = 'categoryId'
/** Row payload key of the product category name. */
export const PRODUCT_CATEGORY_NAME_FIELD = 'categoryName'
/** Row payload key of the product kind. */
export const PRODUCT_KIND_FIELD = 'kind'
/** Row payload key of the product price in kopecks. */
export const PRODUCT_PRICE_FIELD = 'price'
/** Row payload key of the total warehouse units. */
export const PRODUCT_UNITS_FIELD = 'units'
/** Row payload key of the warehouse holding a unique piece. */
const PRODUCT_WAREHOUSE_NAME_FIELD = 'warehouseName'
/** Row payload key of the product availability. */
export const PRODUCT_AVAILABILITY_FIELD = 'availability'
/** Row payload key of the last product change in epoch milliseconds. */
export const PRODUCT_CHANGED_AT_FIELD = 'changedAt'
/** Row payload key of the filter category id. */
const CATEGORY_ID_FIELD = 'id'
/** Row payload key of the filter category name. */
const CATEGORY_NAME_FIELD = 'name'
/** Row payload key of the filter category catalog position. */
const CATEGORY_POSITION_FIELD = 'position'

/**
 * Resolve a product entity row into the table's row view-model.
 *
 * @param row The raw row from the page-scoped table store.
 * @param fieldsOf The resolver of a slot's reference to its fields.
 */
export function resolveProductRow(
  row: TableRow,
  fieldsOf: EntityFieldsResolver,
): ProductRow {
  const product = slotFields(row.slots[PRODUCT_SLOT], fieldsOf)
  const id = product[PRODUCT_ID_FIELD]

  return {
    id: typeof id === 'number' ? id : Number(row.rowKey),
    name: readString(product, PRODUCT_NAME_FIELD),
    pictureUrl: readString(product, PRODUCT_PICTURE_URL_FIELD),
    categoryId: readNumber(product, PRODUCT_CATEGORY_ID_FIELD),
    categoryName: readString(product, PRODUCT_CATEGORY_NAME_FIELD),
    kind: readString(product, PRODUCT_KIND_FIELD),
    price: readNumber(product, PRODUCT_PRICE_FIELD),
    units: readNumber(product, PRODUCT_UNITS_FIELD),
    warehouseName: readStringOrNull(product, PRODUCT_WAREHOUSE_NAME_FIELD),
    availability: readString(product, PRODUCT_AVAILABILITY_FIELD),
    changedAt: readNumber(product, PRODUCT_CHANGED_AT_FIELD),
  }
}

/**
 * Name the product kind.
 *
 * @param kind The product's wire kind.
 */
export function kindText(kind: string): string {
  switch (kind) {
    case 'unique':
      return 'Unique piece'
    case 'stock':
      return 'Stock item'
    default:
      return ''
  }
}

/**
 * Print a quantity or the warehouse holding a unique piece.
 *
 * @param row The product rendered in this cell.
 */
export function stockText(row: ProductRow): string {
  if (row.kind === 'stock') return String(row.units)
  if (row.kind === 'unique' && row.warehouseName) {
    return `1 · ${row.warehouseName}`
  }
  return '—'
}

/**
 * Describe availability with the current quantity where it matters.
 *
 * @param row The product rendered in this cell.
 */
export function availabilityText(row: ProductRow): string {
  switch (row.availability) {
    case 'free':
      return 'One of a kind'
    case 'in_stock':
      return `In stock: ${row.units}`
    case 'low':
      return `Only ${row.units} left`
    case 'out_of_stock':
      return 'Out of stock'
    case 'not_placed':
      return 'Not in a warehouse yet'
    default:
      return ''
  }
}

/**
 * Choose the semantic tone of the availability badge.
 *
 * @param availability The product's wire availability.
 */
export function availabilityTone(
  availability: string,
): 'success' | 'warning' | 'secondary' {
  switch (availability) {
    case 'free':
    case 'in_stock':
      return 'success'
    case 'low':
      return 'warning'
    default:
      return 'secondary'
  }
}

/** Product kinds offered by the Kind filter. */
export const KIND_OPTIONS: readonly HilosTableFilterOption[] = [
  { value: 'unique', label: 'Unique piece' },
  { value: 'stock', label: 'Stock item' },
]

/** Availability choices, in the order the filter offers them. */
export const AVAILABILITY_OPTIONS: readonly HilosTableFilterOption[] = [
  { value: 'in_stock', label: 'In stock' },
  { value: 'low', label: 'Only a few left' },
  { value: 'out_of_stock', label: 'Out of stock' },
  { value: 'free', label: 'One of a kind' },
  { value: 'not_placed', label: 'Not in a warehouse yet' },
]

/**
 * Read the live Category choices in catalog order, keeping numeric ids.
 *
 * @param items The page-scoped categories list.
 * @param fieldsOf The resolver of a slot's reference to its fields.
 */
export function categoryOptions(
  items: readonly ListItem[],
  fieldsOf: EntityFieldsResolver,
): HilosTableFilterOption[] {
  return items
    .map((item) => slotFields(item.slots[CATEGORY_SLOT], fieldsOf))
    .filter((category) => typeof category[CATEGORY_ID_FIELD] === 'number')
    .sort(
      (left, right) =>
        readNumber(left, CATEGORY_POSITION_FIELD) -
          readNumber(right, CATEGORY_POSITION_FIELD) ||
        readNumber(left, CATEGORY_ID_FIELD) -
          readNumber(right, CATEGORY_ID_FIELD),
    )
    .map((category) => ({
      value: readNumber(category, CATEGORY_ID_FIELD),
      label: readString(category, CATEGORY_NAME_FIELD),
    }))
}

/** Columns and the fields each cell needs for live redraws. */
export const PRODUCTS_COLUMNS: HilosTableColumnOf<ProductRow>[] = [
  {
    key: PRODUCT_NAME_FIELD,
    label: 'Product',
    sortable: true,
    card: 'title',
    reads: [PRODUCT_PICTURE_URL_FIELD],
  },
  {
    key: PRODUCT_CATEGORY_NAME_FIELD,
    label: 'Category',
    sortable: true,
    card: 'hidden',
  },
  { key: PRODUCT_KIND_FIELD, label: 'Kind' },
  {
    key: PRODUCT_PRICE_FIELD,
    label: 'Price',
    sortable: true,
    headerClass: 'text-end',
    cellClass: 'text-end text-nowrap',
  },
  {
    key: PRODUCT_UNITS_FIELD,
    label: 'Stock',
    headerClass: 'text-end',
    cellClass: 'text-end text-nowrap',
    reads: [PRODUCT_KIND_FIELD, PRODUCT_WAREHOUSE_NAME_FIELD],
  },
  {
    key: PRODUCT_AVAILABILITY_FIELD,
    label: 'Availability',
    card: 'badge',
    reads: [PRODUCT_UNITS_FIELD],
  },
  {
    key: PRODUCT_CHANGED_AT_FIELD,
    label: 'Changed',
    sortable: true,
    card: 'hidden',
    cellClass: 'text-nowrap',
  },
]

/**
 * Declare the standard table frame; the section head names the table.
 *
 * @param categoryOptionsOf Getter read reactively when filter options change.
 */
export function productsFrame(
  categoryOptionsOf: () => readonly HilosTableFilterOption[],
): HilosTableFrame {
  return {
    search: { placeholder: 'Search products' },
    filters: [
      {
        kind: 'select',
        key: FILTER_CATEGORY,
        label: 'Category',
        options: categoryOptionsOf,
      },
      {
        kind: 'select',
        key: FILTER_KIND,
        label: 'Kind',
        options: () => KIND_OPTIONS,
      },
      {
        kind: 'select',
        key: FILTER_AVAILABILITY,
        label: 'Availability',
        options: () => AVAILABILITY_OPTIONS,
      },
    ],
    columns: PRODUCTS_COLUMNS,
    empty: {
      title: 'No products yet',
      hint: 'Add the first one — categories and warehouses are ready.',
    },
  }
}
