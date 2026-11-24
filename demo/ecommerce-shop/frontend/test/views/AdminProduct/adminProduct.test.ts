import { describe, expect, it } from 'vitest'
import {
  Scope,
  ScopeManager,
  createSignal,
  ingest,
  type EntityRef,
  type PageRouteMatch,
} from '@hilos/core/base'

import {
  ADMIN_PRODUCT_DATA,
  createAdminProductCardSignals,
  resolveAdminProduct,
} from '../../../src/catalog/adminProductCard.js'
import {
  PAGE_ADMIN_PRODUCT,
  PAGE_ADMIN_PRODUCT_STOCK,
} from '../../../src/pages/keys.js'
import { type AdminProduct } from '../../../src/types/AdminProduct.js'
import {
  ADMIN_PRODUCT_WAREHOUSES_LIST,
  stockParts,
  warehouseNameOf,
} from '../../../src/views/AdminProduct/adminProduct.js'

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
  slug: 'ceramic-pot',
  kind: 'stock',
  price: 41000,
  categoryId: 3,
  description: 'A glazed pot for a small palm.',
  pictureUrl: '/pictures/pot-lg.webp',
  pictureFileId: 18,
  editVersion: 'a'.repeat(64),
  stock: { units: 42, warehouses: 2, warehouseId: null },
}

const vase: AdminProduct = {
  productId: 7,
  name: 'Blue vase',
  slug: 'blue-vase',
  kind: 'unique',
  price: 210000,
  categoryId: 2,
  description: 'Thrown by hand, one of a kind.',
  pictureUrl: '/pictures/vase-lg.webp',
  pictureFileId: 16,
  editVersion: 'b'.repeat(64),
  stock: { units: 1, warehouses: 1, warehouseId: 5 },
}

describe('one product admin page', () => {
  it('resolves the whole card', () => {
    expect(resolveAdminProduct(structuredClone(vase))).toEqual(vase)
  })

  it('reads the canonical slug and keeps the card valid when absent or not string', () => {
    expect(resolveAdminProduct(structuredClone(vase))?.slug).toBe('blue-vase')

    const withoutSlug = { ...structuredClone(vase) }
    delete (withoutSlug as Record<string, unknown>).slug
    expect(resolveAdminProduct(withoutSlug)).toEqual({ ...vase, slug: '' })

    const withNonStringSlug = { ...structuredClone(vase), slug: 123 }
    expect(resolveAdminProduct(withNonStringSlug)).toEqual({
      ...vase,
      slug: '',
    })
  })

  it('resolves no card without a product id', () => {
    expect(resolveAdminProduct({ ...pot, productId: undefined })).toBeNull()
  })

  it('reads the live viewer total from the same card and accepts a missing total', () => {
    const scopes = new ScopeManager()
    const route = createSignal<PageRouteMatch>({
      page: PAGE_ADMIN_PRODUCT,
      params: { productId: String(pot.productId) },
      admin: true,
    })
    const overview = createAdminProductCardSignals(
      scopes,
      route,
      PAGE_ADMIN_PRODUCT,
    )
    const scope = scopes.openPage(PAGE_ADMIN_PRODUCT)

    for (const viewerCount of [3, 0]) {
      const card = { ...pot, viewerCount }
      ingest(scope, { data: { [ADMIN_PRODUCT_DATA]: card } })
      expect(overview.product.get()).toEqual(card)
    }

    ingest(scope, { data: { [ADMIN_PRODUCT_DATA]: pot } })
    expect(overview.product.get()).toEqual(pot)
    for (const viewerCount of [-1, 1.5, '3']) {
      ingest(scope, { data: { [ADMIN_PRODUCT_DATA]: { ...pot, viewerCount } } })
      expect(overview.product.get()).toEqual(pot)
    }
  })

  it('shares the card only when its scope, page and product address match', () => {
    const scopes = new ScopeManager()
    const route = createSignal<PageRouteMatch>({
      page: PAGE_ADMIN_PRODUCT,
      params: { productId: String(pot.productId) },
      admin: true,
    })
    const overview = createAdminProductCardSignals(
      scopes,
      route,
      PAGE_ADMIN_PRODUCT,
    )
    const stock = createAdminProductCardSignals(
      scopes,
      route,
      PAGE_ADMIN_PRODUCT_STOCK,
    )
    const answer = { data: { [ADMIN_PRODUCT_DATA]: pot } }

    ingest(scopes.openPage(PAGE_ADMIN_PRODUCT), answer)
    expect(overview.product.get()).toEqual(pot)
    expect(stock.name.get()).toBeUndefined()

    route.set({
      page: PAGE_ADMIN_PRODUCT_STOCK,
      params: { productId: `+0${pot.productId}` },
      admin: true,
    })
    expect(overview.name.get()).toBeUndefined()
    expect(stock.product.get()).toBeNull()

    ingest(scopes.openPage(PAGE_ADMIN_PRODUCT_STOCK), answer)
    expect(stock.product.get()).toEqual(pot)
    expect(stock.name.get()).toBe(pot.name)
    expect(overview.product.get()).toBeNull()

    route.set({
      ...route.get(),
      params: { productId: String(vase.productId) },
    })
    expect(stock.product.get()).toBeNull()
    expect(stock.name.get()).toBeUndefined()
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
