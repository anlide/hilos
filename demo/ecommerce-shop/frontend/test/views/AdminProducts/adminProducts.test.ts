import { describe, expect, it } from 'vitest'
import {
  HILOS_TABLE_ACTIONS_KEY,
  Scope,
  ingest,
  type EntityRef,
} from '@hilos/core/base'

import { PAGE_ADMIN_PRODUCTS } from '../../../src/pages/keys.js'
import {
  PRODUCTS_COLUMNS,
  PRODUCTS_TABLE,
  PRODUCT_SLOT,
  availabilityText,
  availabilityTone,
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

  it('only offers the sort fields accepted by the products table', () => {
    expect(
      PRODUCTS_COLUMNS.filter((column) => column.sortable).map(
        (column) => column.key,
      ),
    ).toEqual(['name', 'categoryName', 'price', 'changedAt'])
  })

  it('ends with the actions column, which reads only the row key', () => {
    expect(PRODUCTS_COLUMNS.at(-1)).toMatchObject({
      key: HILOS_TABLE_ACTIONS_KEY,
      reads: [],
    })
  })
})
