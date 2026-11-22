import { describe, expect, it } from 'vitest'
import { Scope, ingest, type EntityRef } from '@hilos/core/base'

import { PAGE_ADMIN_PRODUCT } from '../../../src/pages/keys.js'
import {
  ADMIN_PRODUCT_WAREHOUSES_LIST,
  resolveAdminProduct,
  stockParts,
  warehouseNameOf,
} from '../../../src/views/AdminProduct/adminProduct.js'
import { type AdminProduct } from '../../../src/views/AdminProduct/types/AdminProduct.js'

function warehousesScope(): Scope {
  const scope = new Scope('page', PAGE_ADMIN_PRODUCT)
  ingest(scope, {
    lists: {
      [ADMIN_PRODUCT_WAREHOUSES_LIST]: {
        items: [
          {
            itemKey: 5,
            slots: { warehouses: { id: 5, name: 'Lviv · Rynok' } },
          },
          {
            itemKey: 6,
            slots: { warehouses: { id: 6, name: 'Kyiv · Podil' } },
          },
        ],
      },
    },
  })
  return scope
}

const pot: AdminProduct = {
  productId: 8,
  name: 'Ceramic pot',
  kind: 'stock',
  price: 41000,
  categoryId: 3,
  description: 'A glazed pot for a small palm.',
  pictureUrl: '/pictures/pot-lg.webp',
  stock: { units: 42, warehouses: 2, warehouseId: null },
}

const vase: AdminProduct = {
  productId: 7,
  name: 'Blue vase',
  kind: 'unique',
  price: 210000,
  categoryId: 2,
  description: 'Thrown by hand, one of a kind.',
  pictureUrl: '/pictures/vase-lg.webp',
  stock: { units: 1, warehouses: 1, warehouseId: 5 },
}

describe('one product admin page', () => {
  it('resolves the whole card', () => {
    expect(resolveAdminProduct(structuredClone(vase))).toEqual(vase)
  })

  it('resolves no card without a product id', () => {
    expect(resolveAdminProduct({ ...pot, productId: undefined })).toBeNull()
  })

  it.each([
    [
      { units: 42, warehouses: 2 },
      { lead: '', strong: '42', tail: ' units in 2 warehouses' },
    ],
    [
      { units: 1, warehouses: 1 },
      { lead: '', strong: '1', tail: ' unit in 1 warehouse' },
    ],
    [
      { units: 0, warehouses: 0 },
      { lead: 'Out of stock', strong: null, tail: '' },
    ],
  ])('counts a stock item holding %j', (held, parts) => {
    expect(
      stockParts({ ...pot, stock: { ...pot.stock, ...held } }, null),
    ).toEqual(parts)
  })

  it('names the warehouse keeping a unique piece, accenting the name', () => {
    expect(stockParts(vase, 'Lviv · Rynok')).toEqual({
      lead: 'One piece, kept in ',
      strong: 'Lviv · Rynok',
      tail: '',
    })
  })

  it('says one piece while the warehouse is not in the list yet', () => {
    expect(stockParts(vase, null)).toEqual({
      lead: 'One piece',
      strong: null,
      tail: '',
    })
  })

  it('says a unique piece is not placed when no warehouse keeps it', () => {
    expect(
      stockParts(
        { ...vase, stock: { ...vase.stock, warehouseId: null } },
        null,
      ),
    ).toEqual({ lead: 'Not in a warehouse yet', strong: null, tail: '' })
  })

  it('names a warehouse by its number, and none the list does not hold', () => {
    const scope = warehousesScope()
    const items = scope.lists.signal(ADMIN_PRODUCT_WAREHOUSES_LIST).get()
    const fieldsOf = (ref: EntityRef) =>
      scope.entities.signal(ref).get()?.fields

    expect([6, 404].map((id) => warehouseNameOf(items, id, fieldsOf))).toEqual([
      'Kyiv · Podil',
      null,
    ])
  })

  it('follows a warehouse entity rename', () => {
    const scope = warehousesScope()
    const fieldsOf = (ref: EntityRef) =>
      scope.entities.signal(ref).get()?.fields
    ingest(scope, {
      lists: {
        [ADMIN_PRODUCT_WAREHOUSES_LIST]: {
          items: [
            {
              itemKey: 6,
              slots: { warehouses: { id: 6, name: 'Kyiv · Obolon' } },
            },
          ],
        },
      },
    })

    expect(
      warehouseNameOf(
        scope.lists.signal(ADMIN_PRODUCT_WAREHOUSES_LIST).get(),
        6,
        fieldsOf,
      ),
    ).toBe('Kyiv · Obolon')
  })

  it('names no warehouse whose entity the scope does not hold', () => {
    const scope = warehousesScope()

    expect(
      warehouseNameOf(
        scope.lists.signal(ADMIN_PRODUCT_WAREHOUSES_LIST).get(),
        6,
        () => undefined,
      ),
    ).toBeNull()
  })
})
