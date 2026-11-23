import { describe, expect, it } from 'vitest'
import { createSignal, ScopeManager, type PageError } from '@hilos/core/base'

import { PAGE_CATEGORY } from '../../../src/pages/keys.js'
import { router } from '../../../src/pages/routes.js'
import {
  createCategoryPage,
  resolveCategoryDetails,
} from '../../../src/views/Category/categoryPage.js'

const category = {
  id: 7,
  slug: 'кімнатні-рослини',
  name: 'Houseplants',
  description: 'Plants for a sunny windowsill.',
}

describe('resolveCategoryDetails', () => {
  it('reads the snapshot for a decoded non-ASCII route slug', () => {
    const route = router.match(`/category/${encodeURIComponent(category.slug)}`)

    expect(resolveCategoryDetails(category, route.params.slug)).toEqual(
      category,
    )
  })

  it('rejects a snapshot for a different slug', () => {
    expect(resolveCategoryDetails(category, 'bouquets')).toBeNull()
  })

  it('rejects a malformed snapshot or an invalid category id', () => {
    const invalidSnapshots = [
      undefined,
      [],
      { ...category, id: 0 },
      { ...category, id: -1 },
      { ...category, id: 1.5 },
      { ...category, id: '7' },
      { ...category, slug: undefined },
      { ...category, name: false },
      { ...category, description: undefined },
    ]

    for (const snapshot of invalidSnapshots) {
      expect(resolveCategoryDetails(snapshot, category.slug)).toBeNull()
    }
  })
})

describe('createCategoryPage', () => {
  it('keeps the accepted card during a move and drops it on departure or refusal', () => {
    const scopes = new ScopeManager()
    const opening = scopes.openPage(PAGE_CATEGORY)
    opening.data.set('category', category)
    const originalRoute = router.match(
      `/category/${encodeURIComponent(category.slug)}`,
    )
    const currentRoute = createSignal(originalRoute)
    const pageError = createSignal<PageError | null>(null)
    const leaveListeners = new Set<() => void>()
    const { details, watchDetails } = createCategoryPage(scopes, {
      currentRoute,
      pageError,
      pageLoading: createSignal(false),
      onLeave(listener) {
        leaveListeners.add(listener)
        return () => leaveListeners.delete(listener)
      },
    })
    const stop = watchDetails()

    try {
      currentRoute.set(router.match('/category/renamed'))
      expect(details.get()).toEqual(category)

      // An ordinary revisit to the former address must wait for its own answer.
      for (const listener of leaveListeners) listener()
      currentRoute.set(originalRoute)
      expect(details.get()).toBeNull()

      const next = scopes.openPage(PAGE_CATEGORY)
      next.data.set('category', category)
      expect(details.get()).toEqual(category)
      currentRoute.set(router.match('/category/renamed'))
      const renamed = { ...category, name: 'Renamed', slug: 'renamed' }
      next.data.set('category', renamed)
      expect(details.get()).toEqual(renamed)

      pageError.set({
        httpCode: 404,
        errorCode: 'not_found',
        title: 'Page Not Found',
        message: 'The page you are looking for does not exist.',
      })
      expect(details.get()).toBeNull()
    } finally {
      stop()
    }
  })
})
