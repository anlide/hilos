import { describe, expect, it } from 'vitest'
import { Scope, ingest, type EntityRef } from '@hilos/core/base'

import {
  ADMIN_PRODUCT_CATEGORIES_LIST,
  categoryNameOf,
  categoryOptions,
  kindText,
} from '../../src/catalog/catalog.js'
import { PAGE_ADMIN_PRODUCT } from '../../src/pages/keys.js'

function categoriesScope(): Scope {
  const scope = new Scope('page', PAGE_ADMIN_PRODUCT)
  ingest(scope, {
    lists: {
      [ADMIN_PRODUCT_CATEGORIES_LIST]: {
        items: [
          {
            itemKey: 9,
            slots: { categories: { id: 9, name: 'Pots', position: 2 } },
          },
          {
            itemKey: 7,
            slots: { categories: { id: 7, name: 'Wreaths', position: 1 } },
          },
          {
            itemKey: 2,
            slots: { categories: { id: 2, name: 'Bouquets', position: 1 } },
          },
          {
            itemKey: 3,
            slots: { categories: { name: 'Missing id', position: 0 } },
          },
          {
            itemKey: 4,
            slots: {
              categories: { id: '4', name: 'String id', position: 0 },
            },
          },
          { itemKey: 5, slots: {} },
        ],
      },
    },
  })
  return scope
}

describe('shop catalog words', () => {
  it.each([
    ['unique', 'Unique piece'],
    ['stock', 'Stock item'],
    ['unknown', ''],
  ])('names kind %s', (kind, text) => {
    expect(kindText(kind)).toBe(text)
  })

  it('orders category options by position then id, keeping numeric values', () => {
    const scope = categoriesScope()

    expect(
      categoryOptions(
        scope.lists.signal(ADMIN_PRODUCT_CATEGORIES_LIST).get(),
        (ref) => scope.entities.signal(ref).get()?.fields,
      ),
    ).toEqual([
      { value: 2, label: 'Bouquets' },
      { value: 7, label: 'Wreaths' },
      { value: 9, label: 'Pots' },
    ])
  })

  it('follows category entity updates and deletions', () => {
    const scope = categoriesScope()
    const fieldsOf = (ref: EntityRef) =>
      scope.entities.signal(ref).get()?.fields
    ingest(scope, {
      lists: {
        [ADMIN_PRODUCT_CATEGORIES_LIST]: {
          items: [
            {
              itemKey: 9,
              slots: { categories: { id: 9, name: 'Vases', position: 0 } },
            },
          ],
          deleted: [7],
        },
      },
    })

    expect(
      categoryOptions(
        scope.lists.signal(ADMIN_PRODUCT_CATEGORIES_LIST).get(),
        fieldsOf,
      ),
    ).toEqual([
      { value: 9, label: 'Vases' },
      { value: 2, label: 'Bouquets' },
    ])
  })

  it('names a category by its number, and none the list does not hold', () => {
    const scope = categoriesScope()
    const items = scope.lists.signal(ADMIN_PRODUCT_CATEGORIES_LIST).get()
    const fieldsOf = (ref: EntityRef) =>
      scope.entities.signal(ref).get()?.fields

    expect(
      [7, 4, 404].map((id) => categoryNameOf(items, id, fieldsOf)),
    ).toEqual(['Wreaths', null, null])
  })

  it('names no category whose entity the scope does not hold', () => {
    const scope = categoriesScope()

    expect(
      categoryNameOf(
        scope.lists.signal(ADMIN_PRODUCT_CATEGORIES_LIST).get(),
        7,
        () => undefined,
      ),
    ).toBeNull()
  })
})
