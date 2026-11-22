// Signals of one product's admin page. The pure readers live in adminProduct.ts
// so they can also be read without application bootstrap. The card passes only
// under the address that names it: after Back or Forward to another product the
// previous card never shows under the new number. The names of the card's
// category and warehouse are read through the page scope's entities, so a
// rename redraws them.
import {
  computedSignal,
  type PageRouteMatch,
  type ReadonlySignal,
} from '@hilos/core/base'

import { scopes } from '../../bootstrap/session.js'
import {
  ADMIN_PRODUCT_CATEGORIES_LIST,
  categoryNameOf,
} from '../../catalog/catalog.js'
import { type EntityFieldsResolver } from '../../entityFields/entityFields.js'
import { PAGE_ADMIN_PRODUCT } from '../../pages/keys.js'
import {
  ADMIN_PRODUCT_DATA,
  ADMIN_PRODUCT_WAREHOUSES_LIST,
  resolveAdminProduct,
  warehouseNameOf,
} from './adminProduct.js'
import { type AdminProduct } from './types/AdminProduct.js'

/** Route param naming the product, as declared in pages/routes.ts. */
const PRODUCT_ID_PARAM = 'productId'

const productData = scopes.pageDataSignal(ADMIN_PRODUCT_DATA)
const categoryItems = scopes.pageListSignal(ADMIN_PRODUCT_CATEGORIES_LIST)
const warehouseItems = scopes.pageListSignal(ADMIN_PRODUCT_WAREHOUSES_LIST)
const fieldsOf: EntityFieldsResolver = (ref) =>
  scopes.entitySignal(ref).get()?.fields

/** The product the page shows and the live names its head, tab and card read. */
export interface AdminProductSignals {
  /** The product, or null until its card for the current address lands. */
  readonly product: ReadonlySignal<AdminProduct | null>
  /** The product's name, or undefined while the page shows no card of it. */
  readonly name: ReadonlySignal<string | undefined>
  /** The product's category name, or null while the page does not hold it. */
  readonly categoryName: ReadonlySignal<string | null>
  /**
   * The name of the warehouse keeping a unique piece, or null when no
   * warehouse keeps it or the page does not hold that warehouse.
   */
  readonly warehouseName: ReadonlySignal<string | null>
}

/**
 * Create the signals of one product's page.
 *
 * @param currentRoute The navigator's current route, whose productId the card must match.
 */
export function createAdminProductSignals(
  currentRoute: ReadonlySignal<PageRouteMatch>,
): AdminProductSignals {
  const product = computedSignal(() => {
    const current = resolveAdminProduct(productData.get())
    return current !== null &&
      String(current.productId) === currentRoute.get().params[PRODUCT_ID_PARAM]
      ? current
      : null
  })
  const name = computedSignal(() => {
    if (scopes.page()?.key !== PAGE_ADMIN_PRODUCT) return undefined
    return product.get()?.name
  })
  const categoryName = computedSignal(() => {
    const current = product.get()
    if (current === null) return null
    return categoryNameOf(categoryItems.get(), current.categoryId, fieldsOf)
  })
  const warehouseName = computedSignal(() => {
    const warehouseId = product.get()?.stock.warehouseId ?? null
    if (warehouseId === null) return null
    return warehouseNameOf(warehouseItems.get(), warehouseId, fieldsOf)
  })
  return { product, name, categoryName, warehouseName }
}
