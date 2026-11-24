import {
  HILOS_TABLE_ACTIONS_KEY,
  readNumber,
  readNumberOrNull,
  readString,
  type HilosTableColumnOf,
  type HilosTableFrame,
  type TableRow,
} from '@hilos/core/base'

import { kindText } from '../../catalog/catalog.js'
import {
  slotFields,
  type EntityFieldsResolver,
} from '../../entityFields/entityFields.js'
import { type CategoryProductRow } from './types/tables/CategoryProductRow.js'

/** Table key declared by EcommerceShopTableContext::categoryProducts. */
export const CATEGORY_PRODUCTS_TABLE = 'categoryProducts'
/** Product slot declared by the storefront table. */
const PRODUCT_SLOT = 'product'
/** The required category filter kept as the reset baseline. */
export const FILTER_CATEGORY = 'category'
const FILTER_KIND = 'kind'
const FILTER_AVAILABILITY = 'availability'

/** Row payload key of the product id. */
const PRODUCT_ID_FIELD = 'id'
/** Row payload key of the product slug. */
const PRODUCT_SLUG_FIELD = 'slug'
/** Row payload key of the product name. */
export const PRODUCT_NAME_FIELD = 'name'
/** Row payload key of the catalog tile image address. */
export const PRODUCT_PICTURE_URL_FIELD = 'pictureUrl'
/** Row payload key of the alternative text for the product picture. */
const PRODUCT_PICTURE_ALT_FIELD = 'pictureAlt'
/** Row payload key of the product kind. */
export const PRODUCT_KIND_FIELD = 'kind'
/** Row payload key of the price in kopecks. */
export const PRODUCT_PRICE_FIELD = 'price'
/** Row payload key of the total warehouse units. */
const PRODUCT_UNITS_FIELD = 'units'
/** Row payload key of the product availability. */
export const PRODUCT_AVAILABILITY_FIELD = 'availability'

/**
 * Project the storefront product slot into the fields both views display.
 * A slot with an id becomes an entity reference when it enters the page scope.
 *
 * @param row The table row carrying the product.
 * @param fieldsOf The resolver for a normalized slot's committed fields.
 */
export function resolveCategoryProductRow(
  row: TableRow,
  fieldsOf: EntityFieldsResolver,
): CategoryProductRow {
  const slot = row.slots[PRODUCT_SLOT]
  const product =
    typeof slot === 'object' && slot !== null && PRODUCT_NAME_FIELD in slot
      ? slot
      : slotFields(slot, fieldsOf)

  return {
    id: readNumberOrNull(product, PRODUCT_ID_FIELD) ?? Number(row.rowKey),
    slug: readString(product, PRODUCT_SLUG_FIELD),
    name: readString(product, PRODUCT_NAME_FIELD),
    pictureUrl: readString(product, PRODUCT_PICTURE_URL_FIELD),
    pictureAlt: readString(product, PRODUCT_PICTURE_ALT_FIELD),
    kind: readString(product, PRODUCT_KIND_FIELD),
    price: readNumber(product, PRODUCT_PRICE_FIELD),
    units: readNumber(product, PRODUCT_UNITS_FIELD),
    availability: readString(product, PRODUCT_AVAILABILITY_FIELD),
  }
}

/** Columns and the additional fields their cells read from the product slot. */
export const CATEGORY_PRODUCTS_COLUMNS: HilosTableColumnOf<CategoryProductRow>[] =
  [
    {
      key: PRODUCT_PICTURE_URL_FIELD,
      label: 'Picture',
      card: 'hidden',
      reads: [PRODUCT_PICTURE_ALT_FIELD],
    },
    {
      key: PRODUCT_NAME_FIELD,
      label: 'Product',
      card: 'title',
      reads: [PRODUCT_SLUG_FIELD],
    },
    { key: PRODUCT_KIND_FIELD, label: 'Kind' },
    {
      key: PRODUCT_PRICE_FIELD,
      label: 'Price',
      sortable: true,
      headerClass: 'text-end',
      cellClass: 'text-end',
    },
    {
      key: PRODUCT_AVAILABILITY_FIELD,
      label: 'Availability',
      card: 'badge',
      reads: [PRODUCT_UNITS_FIELD],
    },
    {
      key: HILOS_TABLE_ACTIONS_KEY,
      label: 'Actions',
      headerClass: 'text-end',
      cellClass: 'text-end',
      reads: [
        PRODUCT_ID_FIELD,
        PRODUCT_NAME_FIELD,
        PRODUCT_KIND_FIELD,
        PRODUCT_UNITS_FIELD,
        PRODUCT_AVAILABILITY_FIELD,
      ],
    },
  ]

/** The category heading names this table in both views. */
export const CATEGORY_PRODUCTS_FRAME = {
  search: { placeholder: 'Filter by name' },
  filters: [
    {
      kind: 'select',
      key: FILTER_KIND,
      label: 'Kind',
      options: () =>
        ['unique', 'stock'].map((value) => ({ value, label: kindText(value) })),
    },
    {
      kind: 'select',
      key: FILTER_AVAILABILITY,
      label: 'Availability',
      options: () => [
        { value: 'available', label: 'In stock' },
        { value: 'out_of_stock', label: 'Out of stock' },
      ],
    },
  ],
  columns: CATEGORY_PRODUCTS_COLUMNS,
  views: {
    tableKey: CATEGORY_PRODUCTS_TABLE,
    order: ['tiles', 'table'],
    imageColumn: PRODUCT_PICTURE_URL_FIELD,
  },
  empty: {
    title: 'No products here yet',
    hint: 'New products of this category will appear here.',
  },
} satisfies HilosTableFrame
