import {
  computedSignal,
  readNumber,
  readNumberOrNull,
  readString,
  type PageRouteMatch,
  type ReadonlySignal,
  type ScopeManager,
} from '@hilos/core/base'

import { PAGE_ADMIN_PRODUCT, PAGE_ADMIN_PRODUCT_STOCK } from '../pages/keys.js'
import { type AdminProduct } from '../types/AdminProduct.js'

/** Page data carrying the product's card (backend AdminProductBrowserData). */
export const ADMIN_PRODUCT_DATA = 'adminProduct'

/** The admin pages that share the product card. */
export type AdminProductPageKey =
  typeof PAGE_ADMIN_PRODUCT | typeof PAGE_ADMIN_PRODUCT_STOCK

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
/** Card payload key of the stored picture file. */
const PRODUCT_PICTURE_FILE_ID_FIELD = 'pictureFileId'
/** Card payload key of the saved catalog fields' version. */
const PRODUCT_EDIT_VERSION_FIELD = 'editVersion'
/** Card payload key of the stock total record. */
const PRODUCT_STOCK_FIELD = 'stock'
/** Stock payload key of the units in all warehouses. */
const STOCK_UNITS_FIELD = 'units'
/** Stock payload key of the warehouses holding a unit. */
const STOCK_WAREHOUSES_FIELD = 'warehouses'
/** Stock payload key of the warehouse keeping a unique piece. */
const STOCK_WAREHOUSE_ID_FIELD = 'warehouseId'

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
    pictureFileId: readNumber(card, PRODUCT_PICTURE_FILE_ID_FIELD),
    editVersion: readString(card, PRODUCT_EDIT_VERSION_FIELD),
    stock: {
      units: readNumber(stock, STOCK_UNITS_FIELD),
      warehouses: readNumber(stock, STOCK_WAREHOUSES_FIELD),
      warehouseId: readNumberOrNull(stock, STOCK_WAREHOUSE_ID_FIELD),
    },
  }
}

/** The addressed product and its live name for the shared entity head. */
export interface AdminProductCardSignals {
  /** The product, or null until the current page has the matching card. */
  readonly product: ReadonlySignal<AdminProduct | null>
  /** The live name, or undefined while the card is absent. */
  readonly name: ReadonlySignal<string | undefined>
}

/**
 * Read a card only from this page's scope and for the product in its address.
 *
 * @param scopes The scope manager holding the page response.
 * @param currentRoute The navigator's current route.
 * @param page The page reading the card.
 */
export function createAdminProductCardSignals(
  scopes: ScopeManager,
  currentRoute: ReadonlySignal<PageRouteMatch>,
  page: AdminProductPageKey,
): AdminProductCardSignals {
  const productData = scopes.pageDataSignal(ADMIN_PRODUCT_DATA)
  const product = computedSignal(() => {
    const route = currentRoute.get()
    if (route.page !== page || scopes.page()?.key !== page) return null

    const { productId } = route.params
    // The server's positive decimal route allows a plus sign and leading zeros.
    if (!/^\+?0*[1-9]\d*$/.test(productId)) return null

    const current = resolveAdminProduct(productData.get())
    return current?.productId === Number(productId) ? current : null
  })
  const name = computedSignal(() => product.get()?.name)
  return { product, name }
}
