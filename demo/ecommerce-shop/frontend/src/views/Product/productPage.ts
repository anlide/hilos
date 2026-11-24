import {
  computedSignal,
  type PageRouteMatch,
  type ReadonlySignal,
  type ScopeManager,
  type ScopePayload,
} from '@hilos/core/base'

import { PAGE_PRODUCT } from '../../pages/keys.js'
import { type ProductDetails } from './types/ProductDetails.js'

const PRODUCT_DATA = 'product'
const PRODUCT_VIEWERS_DATA = 'productViewers'

/**
 * Read the product snapshot for the exact slug in the current address.
 *
 * @param raw The inline product record from the page response.
 * @param currentSlug The decoded slug from the current route.
 */
function resolveProductDetails(
  raw: unknown,
  currentSlug: string | undefined,
): ProductDetails | null {
  if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) {
    return null
  }

  const {
    id,
    slug,
    name,
    kind,
    price,
    description,
    pictureUrl,
    units,
    availability,
    categoryName,
    categorySlug,
  } = raw as Readonly<Record<string, unknown>>
  if (
    typeof id !== 'number' ||
    !Number.isInteger(id) ||
    id <= 0 ||
    typeof slug !== 'string' ||
    slug === '' ||
    slug !== currentSlug ||
    typeof name !== 'string' ||
    (kind !== 'unique' && kind !== 'stock') ||
    typeof price !== 'number' ||
    !Number.isInteger(price) ||
    typeof description !== 'string' ||
    typeof pictureUrl !== 'string' ||
    typeof units !== 'number' ||
    !Number.isInteger(units) ||
    units < 0 ||
    (availability !== 'free' &&
      availability !== 'in_stock' &&
      availability !== 'low' &&
      availability !== 'out_of_stock') ||
    typeof categoryName !== 'string' ||
    typeof categorySlug !== 'string'
  ) {
    return null
  }

  return {
    id,
    slug,
    name,
    kind,
    price,
    description,
    pictureUrl,
    units,
    availability,
    categoryName,
    categorySlug,
  }
}

/**
 * Read the other-viewer count for the current product.
 *
 * @param raw The inline viewer record from the page response.
 * @param currentSlug The decoded slug from the current route.
 */
function resolveOtherViewerCount(
  raw: unknown,
  currentSlug: string,
): number | undefined {
  if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) {
    return undefined
  }

  const { slug, otherViewerCount } = raw as Readonly<Record<string, unknown>>
  return slug === currentSlug &&
    typeof otherViewerCount === 'number' &&
    Number.isInteger(otherViewerCount) &&
    otherViewerCount >= 0
    ? otherViewerCount
    : undefined
}

/**
 * Admit a snapshot for its slug and counts for the card already in scope.
 * Ignore an invalid count accompanying an otherwise valid snapshot.
 *
 * @param scopes The application's page scope manager.
 */
export function createProductPageResponseMatcher(scopes: ScopeManager) {
  const productData = scopes.pageDataSignal(PRODUCT_DATA)

  return function matchesProductPageResponse(
    params: Readonly<Record<string, unknown>>,
    payload: Readonly<ScopePayload>,
  ) {
    const { slug } = params
    if (typeof slug !== 'string') return false

    const data = payload.data ?? {}
    const snapshot = data[PRODUCT_DATA]
    const count = resolveOtherViewerCount(data[PRODUCT_VIEWERS_DATA], slug)
    if (snapshot !== undefined) {
      if (resolveProductDetails(snapshot, slug) === null) return false

      return count === undefined && PRODUCT_VIEWERS_DATA in data
        ? { omitData: [PRODUCT_VIEWERS_DATA] }
        : true
    }

    // A count alone can only update an answered card. Opening a page or
    // refusing it starts a fresh scope, which still needs the full snapshot.
    return (
      count !== undefined &&
      resolveProductDetails(productData.get(), slug) !== null
    )
  }
}

/**
 * Follow the product snapshot as the current route and page scope change.
 *
 * @param scopes The application's page scope manager.
 * @param currentRoute The navigator's current route.
 */
export function createProductDetailsSignal(
  scopes: ScopeManager,
  currentRoute: ReadonlySignal<PageRouteMatch>,
): ReadonlySignal<ProductDetails | null> {
  const productData = scopes.pageDataSignal(PRODUCT_DATA)
  return computedSignal(() => {
    const route = currentRoute.get()
    return route.page === PAGE_PRODUCT
      ? resolveProductDetails(productData.get(), route.params.slug)
      : null
  })
}

/**
 * Follow the viewer count belonging to the currently displayed card.
 *
 * @param scopes The application's page scope manager.
 * @param details The product snapshot for the current route.
 */
export function createProductViewerCountSignal(
  scopes: ScopeManager,
  details: ReadonlySignal<ProductDetails | null>,
): ReadonlySignal<number | undefined> {
  const viewerData = scopes.pageDataSignal(PRODUCT_VIEWERS_DATA)
  return computedSignal(() => {
    const product = details.get()
    return product === null
      ? undefined
      : resolveOtherViewerCount(viewerData.get(), product.slug)
  })
}
