// Pure selectors for the shop's home: the categories and products lists of the
// home page turned into the tiles it draws. Both slots arrive as records
// bearing an `id`, so the normalizer stores each one as an entity of the page
// scope and leaves a reference in the slot; the fields are read back through
// the resolver the caller hands in. Kept free of the application bootstrap so
// it can be read on its own; the signal that feeds it lives in mainPage.ts.
import {
  readBoolean,
  readNumber,
  readString,
  type ListItem,
} from '@hilos/core/base'

import {
  slotFields,
  type EntityFieldsResolver,
} from '../../entityFields/entityFields.js'
import { type CategoryTileItem } from './types/lists/CategoryTileItem.js'

/** Page list carrying every category of the shop. */
export const MAIN_CATEGORIES_LIST = 'mainCategories'
/** Page list carrying every product with its category. */
export const MAIN_PRODUCTS_LIST = 'mainProducts'
/** Entity slot of the categories list. */
export const CATEGORY_SLOT = 'categories'
/** Entity slot of the products list. */
export const PRODUCT_SLOT = 'products'

/** Row payload key of the category id. */
const CATEGORY_ID_FIELD = 'id'
/** Row payload key of the category name. */
const CATEGORY_NAME_FIELD = 'name'
/** Row payload key of the category description. */
const CATEGORY_DESCRIPTION_FIELD = 'description'
/** Row payload key of the category address segment. */
const CATEGORY_SLUG_FIELD = 'slug'
/** Row payload key of the category catalog position. */
const CATEGORY_POSITION_FIELD = 'position'
/** Row payload key of the address of the category tile picture. */
const CATEGORY_TILE_PICTURE_FIELD = 'tilePicture'
/** Row payload key of the product's category id. */
const PRODUCT_CATEGORY_ID_FIELD = 'categoryId'
/** Row payload key of whether the product stands on the shop front. */
const PRODUCT_ON_SHOP_FRONT_FIELD = 'onShopFront'

/**
 * Build the home's tiles in the administrators' order, each with the number
 * of its products on the shop front.
 *
 * @param categories The page-scoped categories list.
 * @param products The page-scoped products list.
 * @param fieldsOf The resolver of a slot's reference to its fields.
 */
export function buildHomeTiles(
  categories: readonly ListItem[],
  products: readonly ListItem[],
  fieldsOf: EntityFieldsResolver,
): CategoryTileItem[] {
  const counts = new Map<number, number>()
  for (const item of products) {
    const product = slotFields(item.slots[PRODUCT_SLOT], fieldsOf)
    if (readBoolean(product, PRODUCT_ON_SHOP_FRONT_FIELD)) {
      const categoryId = readNumber(product, PRODUCT_CATEGORY_ID_FIELD)
      counts.set(categoryId, (counts.get(categoryId) ?? 0) + 1)
    }
  }

  return categories
    .map((item) => ({
      key: item.itemKey,
      category: slotFields(item.slots[CATEGORY_SLOT], fieldsOf),
    }))
    .sort(
      (left, right) =>
        readNumber(left.category, CATEGORY_POSITION_FIELD) -
          readNumber(right.category, CATEGORY_POSITION_FIELD) ||
        readNumber(left.category, CATEGORY_ID_FIELD) -
          readNumber(right.category, CATEGORY_ID_FIELD),
    )
    .map(({ key, category }) => ({
      key,
      name: readString(category, CATEGORY_NAME_FIELD),
      description: readString(category, CATEGORY_DESCRIPTION_FIELD),
      slug: readString(category, CATEGORY_SLUG_FIELD),
      picture: readString(category, CATEGORY_TILE_PICTURE_FIELD),
      productCount: counts.get(readNumber(category, CATEGORY_ID_FIELD)) ?? 0,
    }))
}

/**
 * Say how many products a category has.
 *
 * @param count The number of products.
 */
export function productCountLabel(count: number): string {
  return count === 1 ? '1 product' : `${count} products`
}
