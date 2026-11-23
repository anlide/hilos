// Overview reads the category and warehouse names through its page scope's
// entities, so a rename redraws them.
import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type HilosRouter,
  type ReadonlySignal,
} from '@hilos/core/base'

import { connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'
import {
  ADMIN_PRODUCT_DATA,
  createAdminProductCardSignals,
  type AdminProductCardSignals,
} from '../../catalog/adminProductCard.js'
import {
  ADMIN_PRODUCT_CATEGORIES_LIST,
  categoryNameOf,
  categoryOptions,
} from '../../catalog/catalog.js'
import { type EntityFieldsResolver } from '../../entityFields/entityFields.js'
import { PAGE_ADMIN_PRODUCT } from '../../pages/keys.js'
import {
  ADMIN_PRODUCT_WAREHOUSES_LIST,
  warehouseNameOf,
  warehouseOptions,
} from './adminProduct.js'
import { type ProductEditCard } from './adminProductEdit.js'

const NOT_FOUND = 404

const productData = scopes.pageDataSignal(ADMIN_PRODUCT_DATA)
const categoryItems = scopes.pageListSignal(ADMIN_PRODUCT_CATEGORIES_LIST)
const warehouseItems = scopes.pageListSignal(ADMIN_PRODUCT_WAREHOUSES_LIST)
const fieldsOf: EntityFieldsResolver = (ref) =>
  scopes.entitySignal(ref).get()?.fields

/** The product the page shows and the live names its head, tab and card read. */
export interface AdminProductSignals extends AdminProductCardSignals {
  /** The card's live Category choices in catalog order. */
  readonly categories: ReadonlySignal<ReturnType<typeof categoryOptions>>
  /** The card's live Warehouse choices in list order. */
  readonly warehouses: ReadonlySignal<ReturnType<typeof warehouseOptions>>
  /** The fresh card for edits, with deletion distinct from an unavailable card. */
  readonly editCard: ReadonlySignal<ProductEditCard>
  /** A replacement subscription still owes the card after a connection loss. */
  readonly awaitingFreshCard: ReadonlySignal<boolean>
  /** The product's category name, or null while the page does not hold it. */
  readonly categoryName: ReadonlySignal<string | null>
  /**
   * The name of the warehouse keeping a unique piece, or null when no
   * warehouse keeps it or the page does not hold that warehouse.
   */
  readonly warehouseName: ReadonlySignal<string | null>
  /** Observe freshness while the Overview is mounted. Returns the cleanup. */
  watchFreshness(): () => void
}

/**
 * Create the signals of one product's page.
 *
 * @param router The navigator and the status of its current page subscription.
 */
export function createAdminProductSignals(
  router: Pick<HilosRouter, 'currentRoute' | 'pageError' | 'pageLoading'>,
): AdminProductSignals {
  const { product, name } = createAdminProductCardSignals(
    scopes,
    router.currentRoute,
    PAGE_ADMIN_PRODUCT,
  )
  const categories = computedSignal(() =>
    categoryOptions(categoryItems.get(), fieldsOf),
  )
  const warehouses = computedSignal(() =>
    warehouseOptions(warehouseItems.get(), fieldsOf),
  )
  const fresh = createSignal(connection.state === 'connected')
  const awaitingFreshCard = computedSignal(
    () => !fresh.get() && router.pageError.get() === null,
  )
  const editCard = computedSignal<ProductEditCard>(() => {
    const error = router.pageError.get()
    if (error?.httpCode === NOT_FOUND && error.errorCode === 'not_found') {
      return null
    }
    if (!fresh.get() || error !== null || router.pageLoading.get()) {
      return undefined
    }
    return product.get() ?? undefined
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
  function watchFreshness(): () => void {
    // Reconnect keeps the old page scope on screen. The socket can carry a
    // request again before the repeated subscription delivers its card.
    const stopConnection = connection.on('state', (state) => {
      if (state !== 'connected') fresh.set(false)
    })
    const stopCard = subscribeSignal(productData, () => {
      if (connection.state === 'connected' && product.get() !== null) {
        fresh.set(true)
      }
    })
    return () => {
      stopCard()
      stopConnection()
    }
  }

  return {
    product,
    categories,
    warehouses,
    editCard,
    awaitingFreshCard,
    name,
    categoryName,
    warehouseName,
    watchFreshness,
  }
}
