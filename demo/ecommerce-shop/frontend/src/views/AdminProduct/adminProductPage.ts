// Overview reads the category and warehouse names through its page scope's
// entities, so a rename redraws them.
import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type HilosRouter,
  type ReadonlySignal,
  type ScopeManager,
  type ScopePayload,
} from '@hilos/core/base'

import { connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'
import {
  ADMIN_PRODUCT_DATA,
  createAdminProductCardSignals,
  resolveAddressedAdminProduct,
  type AdminProductCardSignals,
} from '../../catalog/adminProductCard.js'
import {
  ADMIN_PRODUCT_CATEGORIES_LIST,
  categoryNameOf,
  categoryOptions,
} from '../../catalog/catalog.js'
import {
  PRODUCT_CARTS_DATA,
  resolveProductCartCount,
} from '../../catalog/productCarts.js'
import { type EntityFieldsResolver } from '../../entityFields/entityFields.js'
import { PAGE_ADMIN_PRODUCT } from '../../pages/keys.js'
import { type AdminProduct } from '../../types/AdminProduct.js'
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

/**
 * Admit an addressed Overview and counts for an Overview already in scope.
 *
 * @param scopes The application's page scope manager.
 */
export function createAdminProductPageResponseMatcher(scopes: ScopeManager) {
  const productData = scopes.pageDataSignal(ADMIN_PRODUCT_DATA)

  return function matchesAdminProductPageResponse(
    params: Readonly<Record<string, unknown>>,
    payload: Readonly<ScopePayload>,
  ) {
    const data = payload.data ?? {}
    const snapshot = data[ADMIN_PRODUCT_DATA]
    if (snapshot === undefined && !(PRODUCT_CARTS_DATA in data)) return true

    const product = resolveAddressedAdminProduct(
      snapshot === undefined ? productData.get() : snapshot,
      params.productId,
    )
    if (product === null) return false

    const cartCount = resolveProductCartCount(
      data[PRODUCT_CARTS_DATA],
      product.productId,
    )
    if (cartCount !== undefined) return true
    if (snapshot === undefined) return false

    return PRODUCT_CARTS_DATA in data
      ? { omitData: [PRODUCT_CARTS_DATA] }
      : true
  }
}

/**
 * Follow the cart count for the addressed Overview card.
 *
 * @param scopes The application's page scope manager.
 * @param product The product snapshot for the current Overview route.
 */
export function createAdminProductCartCountSignal(
  scopes: ScopeManager,
  product: ReadonlySignal<AdminProduct | null>,
): ReadonlySignal<number | undefined> {
  const cartData = scopes.pageDataSignal(PRODUCT_CARTS_DATA)
  return computedSignal(() => {
    const current = product.get()
    return current === null
      ? undefined
      : resolveProductCartCount(cartData.get(), current.productId)
  })
}

/** The product the page shows and the live names its head, tab and card read. */
export interface AdminProductSignals extends AdminProductCardSignals {
  /** The aggregate cart count for the current Overview card. */
  readonly cartCount: ReadonlySignal<number | undefined>
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
    cartCount: createAdminProductCartCountSignal(scopes, product),
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
