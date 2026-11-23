import {
  computedSignal,
  type PageRouteMatch,
  type ReadonlySignal,
  type ScopeManager,
  type ScopePayload,
} from '@hilos/core/base'

import { PAGE_PRODUCT } from '../../pages/keys.js'
import { type ProductDetails } from './types/ProductDetails.js'

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
 * Accept a complete product snapshot for the exact slug being subscribed.
 *
 * @param params The current product subscription's decoded route params.
 * @param payload The incoming product page payload.
 */
export function matchesProductPageResponse(
  params: Readonly<Record<string, unknown>>,
  payload: Readonly<ScopePayload>,
): boolean {
  const slug = typeof params.slug === 'string' ? params.slug : undefined
  return resolveProductDetails(payload.data?.product, slug) !== null
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
  const productData = scopes.pageDataSignal('product')
  return computedSignal(() => {
    const route = currentRoute.get()
    return route.page === PAGE_PRODUCT
      ? resolveProductDetails(productData.get(), route.params.slug)
      : null
  })
}
