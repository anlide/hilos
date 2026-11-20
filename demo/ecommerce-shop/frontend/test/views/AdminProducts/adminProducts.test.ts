import { describe, expect, it } from 'vitest'
import { type ListItem } from '@hilos/core/base'

import {
  PRODUCTS_COLUMNS,
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
  it.each([unique, stock])('resolves the inline product $name', (product) => {
    expect(
      resolveProductRow({
        rowKey: String(product.id),
        slots: { product: { ...product } },
      }),
    ).toEqual(product)
  })

  it('falls back to the row key when the product id is absent', () => {
    expect(
      resolveProductRow({
        rowKey: '19',
        slots: { product: { ...unique, id: undefined } },
      }).id,
    ).toBe(19)
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
    const items: readonly ListItem[] = [
      {
        itemKey: '9',
        slots: { categories: { id: 9, name: 'Pots', position: 2 } },
      },
      {
        itemKey: '7',
        slots: { categories: { id: 7, name: 'Wreaths', position: 1 } },
      },
      {
        itemKey: '2',
        slots: { categories: { id: 2, name: 'Bouquets', position: 1 } },
      },
      {
        itemKey: '3',
        slots: { categories: { name: 'Missing id', position: 0 } },
      },
      {
        itemKey: '4',
        slots: { categories: { id: '4', name: 'String id', position: 0 } },
      },
      { itemKey: '5', slots: {} },
    ]
    expect(categoryOptions(items)).toEqual([
      { value: 2, label: 'Bouquets' },
      { value: 7, label: 'Wreaths' },
      { value: 9, label: 'Pots' },
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
