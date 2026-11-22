// Catalog — what the shop's admin pages say about a product's kind and category,
// shared by the Products table and one product's page. Both read the same live
// categories list, so its keys and readers live here and nowhere else. Its slot
// carries a record bearing an `id`, so the normalizer stores each category as an
// entity of the page scope and leaves a reference in the slot; the fields are
// read back through the resolver the caller hands in.
import {
  readNumber,
  readString,
  type HilosTableFilterOption,
  type ListItem,
} from '@hilos/core/base'

import {
  slotFields,
  type EntityFieldsResolver,
} from '../entityFields/entityFields.js'

/** Page list carrying the live categories, by number and in catalog order. */
export const ADMIN_PRODUCT_CATEGORIES_LIST = 'adminProductCategories'
/** Entity slot of the categories browser list. */
const CATEGORY_SLOT = 'categories'

/** List payload key of the category id. */
const CATEGORY_ID_FIELD = 'id'
/** List payload key of the category name. */
const CATEGORY_NAME_FIELD = 'name'
/** List payload key of the category catalog position. */
const CATEGORY_POSITION_FIELD = 'position'

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

/**
 * Find the name of one category in the live categories list.
 *
 * @param items The page-scoped categories list.
 * @param id The category's number.
 * @param fieldsOf The resolver of a slot's reference to its fields.
 * @returns The category's name, or null while the list or the scope does not
 * hold it.
 */
export function categoryNameOf(
  items: readonly ListItem[],
  id: number,
  fieldsOf: EntityFieldsResolver,
): string | null {
  for (const item of items) {
    const category = slotFields(item.slots[CATEGORY_SLOT], fieldsOf)
    if (category[CATEGORY_ID_FIELD] === id) {
      return readString(category, CATEGORY_NAME_FIELD)
    }
  }
  return null
}
