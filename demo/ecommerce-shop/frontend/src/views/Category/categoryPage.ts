import {
  computedSignal,
  readNumber,
  readStringOrNull,
  type PageRouteMatch,
  type ReadonlySignal,
  type ScopeManager,
} from '@hilos/core/base'

import { PAGE_CATEGORY } from '../../pages/keys.js'
import { type CategoryDetails } from './types/CategoryDetails.js'

/**
 * Read the category snapshot only for the slug in the current address.
 *
 * @param raw The inline category record from the page response.
 * @param currentSlug The decoded slug from the current route.
 */
export function resolveCategoryDetails(
  raw: unknown,
  currentSlug: string | undefined,
): CategoryDetails | null {
  if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) {
    return null
  }

  const fields = raw as Readonly<Record<string, unknown>>
  const id = readNumber(fields, 'id')
  const slug = readStringOrNull(fields, 'slug')
  const name = readStringOrNull(fields, 'name')
  const description = readStringOrNull(fields, 'description')
  if (
    !Number.isInteger(id) ||
    id <= 0 ||
    !slug ||
    slug !== currentSlug ||
    name === null ||
    description === null
  ) {
    return null
  }

  return { id, slug, name, description }
}

/**
 * Follow the category snapshot as the current route and page scope change.
 *
 * @param scopes The application's page scope manager.
 * @param currentRoute The navigator's current route.
 */
export function createCategoryDetailsSignal(
  scopes: ScopeManager,
  currentRoute: ReadonlySignal<PageRouteMatch>,
): ReadonlySignal<CategoryDetails | null> {
  const categoryData = scopes.pageDataSignal('category')
  return computedSignal(() => {
    const route = currentRoute.get()
    return route.page === PAGE_CATEGORY
      ? resolveCategoryDetails(categoryData.get(), route.params.slug)
      : null
  })
}
