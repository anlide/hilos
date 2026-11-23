import {
  computedSignal,
  createSignal,
  readNumber,
  readStringOrNull,
  subscribeSignal,
  type HilosRouter,
  type Scope,
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
 * Keep the last matching category while its subscription follows a move.
 *
 * @param scopes The application's page scope manager.
 * @param router The navigator that distinguishes leaving from a same-page move.
 */
export function createCategoryPage(
  scopes: ScopeManager,
  router: Pick<
    HilosRouter,
    'currentRoute' | 'pageLoading' | 'pageError' | 'onLeave'
  >,
) {
  const details = createSignal<CategoryDetails | null>(null)
  const current = computedSignal(() => {
    const route = router.currentRoute.get()
    const scope = scopes.page()
    if (
      route.page !== PAGE_CATEGORY ||
      scope?.key !== PAGE_CATEGORY ||
      router.pageLoading.get() ||
      router.pageError.get() !== null
    ) {
      return null
    }
    return {
      scope,
      raw: scope.data.signal('category').get(),
      slug: route.params.slug,
    }
  })

  return {
    details,
    watchDetails() {
      let held: { scope: Scope; raw: unknown } | undefined
      let departedScope: Scope | undefined
      const sync = (next: ReturnType<typeof current.get>) => {
        if (next !== null && next.scope !== departedScope) {
          const category = resolveCategoryDetails(next.raw, next.slug)
          if (category !== null) {
            held = { scope: next.scope, raw: next.raw }
            details.set(category)
            return
          }
          // page_moved publishes the new slug before the repeated subscription
          // answers. It keeps the page scope and the exact snapshot in it.
          if (held?.scope === next.scope && held.raw === next.raw) return
        }
        held = undefined
        details.set(null)
      }

      // Ordinary navigation announces departure before publishing its route.
      const offLeave = router.onLeave(() => {
        departedScope = scopes.page()
        sync(null)
      })
      const offCurrent = subscribeSignal(current, sync)
      sync(current.get())
      return () => {
        offCurrent()
        offLeave()
      }
    },
  }
}
