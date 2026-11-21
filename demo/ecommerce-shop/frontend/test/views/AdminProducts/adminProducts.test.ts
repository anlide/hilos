import { describe, expect, it } from 'vitest'
import { Scope, ingest, type EntityRef } from '@hilos/core/base'

import { PAGE_ADMIN_PRODUCTS } from '../../../src/pages/keys.js'
import {
  ADMIN_PRODUCT_CATEGORIES_LIST,
  PRODUCTS_COLUMNS,
  PRODUCTS_TABLE,
  PRODUCT_SLOT,
  availabilityText,
  availabilityTone,
  categoryOptions,
  kindText,
  resolveProductRow,
  stockText,
} from '../../../src/views/AdminProducts/adminProducts.js'
import { type ProductRow } from '../../../src/views/AdminProducts/types/tables/ProductRow.js'

const unique: ProductRow = {
  id: 7,
  name: 'Autumn fire',
  pictureUrl: '/pictures/autumn.webp',
  categoryId: 2,
  categoryName: 'Bouquets',
  kind: 'unique',
  price: 210000,
  units: 1,
  warehouseName: 'Kyiv · Podil',
  availability: 'free',
  changedAt: 1795161600000,
}

const stock: ProductRow = {
  id: 8,
  name: 'Ceramic pot',
  pictureUrl: '/pictures/pot.webp',
  categoryId: 3,
  categoryName: 'Pots',
  kind: 'stock',
  price: 41000,
  units: 42,
  warehouseName: null,
  availability: 'in_stock',
  changedAt: 1795161660000,
}

describe('products admin table', () => {
  it.each([unique, stock])('resolves the product entity $name', (product) => {
    const scope = new Scope('page', PAGE_ADMIN_PRODUCTS)
    ingest(scope, {
      tables: {
        [PRODUCTS_TABLE]: {
          rows: [
            {
              rowKey: product.id,
              slots: { [PRODUCT_SLOT]: { ...product } },
            },
          ],
        },
      },
    })
    const [row] = scope.tables.signal(PRODUCTS_TABLE).get()

    expect(
      resolveProductRow(
        row!,
        (ref) => scope.entities.signal(ref).get()?.fields,
      ),
    ).toEqual(product)
  })

  it('follows an entity delta for the same product row', () => {
    const scope = new Scope('page', PAGE_ADMIN_PRODUCTS)
    const fieldsOf = (ref: EntityRef) =>
      scope.entities.signal(ref).get()?.fields
    ingest(scope, {
      tables: {
        [PRODUCTS_TABLE]: {
          rows: [
            {
              rowKey: unique.id,
              slots: { [PRODUCT_SLOT]: { ...unique } },
            },
          ],
        },
      },
    })
    ingest(scope, {
      tables: {
        [PRODUCTS_TABLE]: {
          rows: [
            {
              rowKey: unique.id,
              slots: {
                [PRODUCT_SLOT]: {
                  id: unique.id,
                  name: 'Winter frost',
                  price: 250000,
                },
              },
            },
          ],
        },
      },
    })
    const [row] = scope.tables.signal(PRODUCTS_TABLE).get()

    expect(resolveProductRow(row!, fieldsOf)).toEqual({
      ...unique,
      name: 'Winter frost',
      price: 250000,
    })
  })

  it('uses the row key and empty fields without a product slot', () => {
    const scope = new Scope('page', PAGE_ADMIN_PRODUCTS)
    ingest(scope, {
      tables: {
        [PRODUCTS_TABLE]: {
          rows: [{ rowKey: 19, slots: {} }],
        },
      },
    })
    const [row] = scope.tables.signal(PRODUCTS_TABLE).get()

    expect(
      resolveProductRow(
        row!,
        (ref) => scope.entities.signal(ref).get()?.fields,
      ),
    ).toEqual({
      id: 19,
      name: '',
      pictureUrl: '',
      categoryId: 0,
      categoryName: '',
      kind: '',
      price: 0,
      units: 0,
      warehouseName: null,
      availability: '',
      changedAt: 0,
    })
  })

  it.each([
    ['unique', 'Unique piece'],
    ['stock', 'Stock item'],
    ['unknown', ''],
  ])('names kind %s', (kind, text) => {
    expect(kindText(kind)).toBe(text)
  })

  it.each([
    [stock, '42'],
    [unique, '1 · Kyiv · Podil'],
    [{ ...unique, warehouseName: null }, '—'],
  ] as const)('prints stock for %j', (product, text) => {
    expect(stockText(product)).toBe(text)
  })

  it.each([
    ['free', 1, 'One of a kind', 'success'],
    ['in_stock', 42, 'In stock: 42', 'success'],
    ['low', 3, 'Only 3 left', 'warning'],
    ['out_of_stock', 0, 'Out of stock', 'secondary'],
    ['not_placed', 0, 'Not in a warehouse yet', 'secondary'],
    ['unknown', 0, '', 'secondary'],
  ] as const)(
    'describes availability %s',
    (availability, units, text, tone) => {
      expect(availabilityText({ ...stock, availability, units })).toBe(text)
      expect(availabilityTone(availability)).toBe(tone)
    },
  )

  it('orders category options by position then id, keeping numeric values', () => {
    const scope = new Scope('page', PAGE_ADMIN_PRODUCTS)
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
    const scope = new Scope('page', PAGE_ADMIN_PRODUCTS)
    const fieldsOf = (ref: EntityRef) =>
      scope.entities.signal(ref).get()?.fields
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
          ],
        },
      },
    })
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

  it('only offers the sort fields accepted by the products table', () => {
    expect(
      PRODUCTS_COLUMNS.filter((column) => column.sortable).map(
        (column) => column.key,
      ),
    ).toEqual(['name', 'categoryName', 'price', 'changedAt'])
  })
})
