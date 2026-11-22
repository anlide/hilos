// Overview reads the category and warehouse names through its page scope's
// entities, so a rename redraws them.
import {
  computedSignal,
  type PageRouteMatch,
  type ReadonlySignal,
} from '@hilos/core/base'

import { scopes } from '../../bootstrap/session.js'
import {
  createAdminProductCardSignals,
  type AdminProductCardSignals,
} from '../../catalog/adminProductCard.js'
import {
  ADMIN_PRODUCT_CATEGORIES_LIST,
  categoryNameOf,
} from '../../catalog/catalog.js'
import { type EntityFieldsResolver } from '../../entityFields/entityFields.js'
import { PAGE_ADMIN_PRODUCT } from '../../pages/keys.js'
import {
  ADMIN_PRODUCT_WAREHOUSES_LIST,
  warehouseNameOf,
} from './adminProduct.js'

const categoryItems = scopes.pageListSignal(ADMIN_PRODUCT_CATEGORIES_LIST)
const warehouseItems = scopes.pageListSignal(ADMIN_PRODUCT_WAREHOUSES_LIST)
const fieldsOf: EntityFieldsResolver = (ref) =>
  scopes.entitySignal(ref).get()?.fields

/** The product the page shows and the live names its head, tab and card read. */
export interface AdminProductSignals extends AdminProductCardSignals {
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
  const { product, name } = createAdminProductCardSignals(
    scopes,
    currentRoute,
    PAGE_ADMIN_PRODUCT,
  )
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
