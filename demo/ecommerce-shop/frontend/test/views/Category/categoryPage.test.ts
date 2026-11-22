import { describe, expect, it } from 'vitest'

import { router } from '../../../src/pages/routes.js'
import { resolveCategoryDetails } from '../../../src/views/Category/categoryPage.js'

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
