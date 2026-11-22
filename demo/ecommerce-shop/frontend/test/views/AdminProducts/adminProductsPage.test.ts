import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { SIGNAL_TYPE_PAGE_RESPONSE, ingest } from '@hilos/core/base'

import { scopes } from '../../../src/bootstrap/session.js'
import { ADMIN_PRODUCT_CATEGORIES_LIST } from '../../../src/catalog/catalog.js'
import { PAGE_ADMIN_PRODUCTS } from '../../../src/pages/keys.js'
import {
  FILTER_CATEGORY,
  PRODUCTS_TABLE,
} from '../../../src/views/AdminProducts/adminProducts.js'
import {
  createProductsTable,
  type ProductsTable,
} from '../../../src/views/AdminProducts/adminProductsPage.js'

const wire = vi.hoisted(() => {
  const listeners = new Map<string, Set<(signal: unknown) => void>>()
  return {
    listeners,
    connection: {
      on: vi.fn((name: string, listener: (signal: unknown) => void) => {
        const current = listeners.get(name) ?? new Set()
        current.add(listener)
        listeners.set(name, current)
        return () => current.delete(listener)
      }),
      registerTableWindow: vi.fn(),
      unregisterTableWindow: vi.fn(),
      sendTableViewport: vi.fn(),
      sendTableRendered: vi.fn(),
      sendTableRowFocus: vi.fn(),
      sendTableFacets: vi.fn(),
    },
  }
})

vi.mock('../../../src/bootstrap/connection.js', () => ({
  connection: wire.connection,
}))
vi.mock('../../../src/bootstrap/session.js', async () => {
  const { ScopeManager } = await import('@hilos/core/base')
  return { scopes: new ScopeManager() }
})

const mounted: ProductsTable[] = []

beforeEach(() => {
  vi.clearAllMocks()
  scopes.openPage(PAGE_ADMIN_PRODUCTS)
  category(2, 'Bouquets', 1)
})

afterEach(() => {
  for (const table of mounted.splice(0)) table.dispose()
  scopes.dropPage()
  wire.listeners.clear()
})

function category(id: number, name: string, position: number): void {
  ingest(scopes.page()!, {
    lists: {
      [ADMIN_PRODUCT_CATEGORIES_LIST]: {
        items: [{ itemKey: id, slots: { categories: { id, name, position } } }],
      },
    },
  })
}

function mount(): ProductsTable {
  const table = createProductsTable()
  mounted.push(table)
  table.start()
  return table
}

function firstWindow(): void {
  for (const listener of wire.listeners.get('projectSignal') ?? []) {
    listener({
      type: SIGNAL_TYPE_PAGE_RESPONSE,
      data: {
        page: PAGE_ADMIN_PRODUCTS,
        payload: {
          windows: {
            [PRODUCTS_TABLE]: {
              rows: [],
              totalCount: 0,
              totalExact: true,
              firstAnchor: null,
              lastAnchor: null,
              limit: 25,
              sort: [{ field: 'changedAt', direction: 'desc' }],
              filter: {},
            },
          },
        },
      },
    })
  }
}

function categoryChoices(table: ProductsTable) {
  const filter = table.controller.frame.filters
    .get()
    .find(
      (view) =>
        view.filter.kind === 'select' && view.filter.key === FILTER_CATEGORY,
    )?.filter
  if (filter?.kind !== 'select') throw new Error('Category filter missing')
  return filter.options()
}

describe('Products table connection lifecycle', () => {
  it('declares all facets after the first window and follows live categories', () => {
    const table = mount()
    expect(wire.connection.sendTableFacets).not.toHaveBeenCalled()
    firstWindow()
    expect(wire.connection.sendTableFacets).toHaveBeenCalledExactlyOnceWith(
      PAGE_ADMIN_PRODUCTS,
      PRODUCTS_TABLE,
      {
        category: [2],
        kind: ['unique', 'stock'],
        availability: ['in_stock', 'low', 'out_of_stock', 'free', 'not_placed'],
      },
    )

    wire.connection.sendTableFacets.mockClear()
    category(7, 'Pots', 2)
    expect(wire.connection.sendTableFacets).toHaveBeenCalledExactlyOnceWith(
      PAGE_ADMIN_PRODUCTS,
      PRODUCTS_TABLE,
      expect.objectContaining({ category: [2, 7] }),
    )
    expect(categoryChoices(table)).toEqual([
      { value: 2, label: 'Bouquets' },
      { value: 7, label: 'Pots' },
    ])

    wire.connection.sendTableFacets.mockClear()
    category(7, 'Vases', 2)
    expect(wire.connection.sendTableFacets).toHaveBeenCalledTimes(2)
    expect(wire.connection.sendTableFacets).toHaveBeenNthCalledWith(
      1,
      PAGE_ADMIN_PRODUCTS,
      PRODUCTS_TABLE,
      expect.objectContaining({ category: [2, 7] }),
    )
    expect(wire.connection.sendTableFacets).toHaveBeenNthCalledWith(
      2,
      PAGE_ADMIN_PRODUCTS,
      PRODUCTS_TABLE,
      expect.objectContaining({ category: [2, 7] }),
    )
    expect(categoryChoices(table)).toEqual([
      { value: 2, label: 'Bouquets' },
      { value: 7, label: 'Vases' },
    ])
  })

  it('drops deleted categories from choices and facets', () => {
    category(7, 'Pots', 2)
    const table = mount()
    firstWindow()
    wire.connection.sendTableFacets.mockClear()

    ingest(scopes.page()!, {
      lists: {
        [ADMIN_PRODUCT_CATEGORIES_LIST]: {
          deleted: [7],
        },
      },
    })

    expect(wire.connection.sendTableFacets).toHaveBeenCalledExactlyOnceWith(
      PAGE_ADMIN_PRODUCTS,
      PRODUCTS_TABLE,
      expect.objectContaining({ category: [2] }),
    )
    expect(categoryChoices(table)).toEqual([{ value: 2, label: 'Bouquets' }])
  })

  it('stops on disposal and gives a replacement mount live subscriptions', () => {
    const table = mount()
    table.start()
    expect(wire.connection.registerTableWindow).toHaveBeenCalledTimes(1)
    firstWindow()
    table.dispose()
    table.dispose()
    vi.clearAllMocks()

    table.start()
    firstWindow()
    category(7, 'Pots', 2)
    expect(wire.connection.registerTableWindow).not.toHaveBeenCalled()
    expect(wire.connection.sendTableFacets).not.toHaveBeenCalled()
    expect(wire.connection.sendTableViewport).not.toHaveBeenCalled()
    expect(wire.connection.sendTableRendered).not.toHaveBeenCalled()
    expect(wire.connection.sendTableRowFocus).not.toHaveBeenCalled()

    const replacement = mount()
    firstWindow()
    wire.connection.sendTableFacets.mockClear()
    category(7, 'Vases', 2)
    expect(wire.connection.sendTableFacets).toHaveBeenCalledTimes(2)
    expect(wire.connection.sendTableFacets).toHaveBeenNthCalledWith(
      1,
      PAGE_ADMIN_PRODUCTS,
      PRODUCTS_TABLE,
      expect.objectContaining({ category: [2, 7] }),
    )
    expect(wire.connection.sendTableFacets).toHaveBeenNthCalledWith(
      2,
      PAGE_ADMIN_PRODUCTS,
      PRODUCTS_TABLE,
      expect.objectContaining({ category: [2, 7] }),
    )
    expect(categoryChoices(replacement)).toEqual([
      { value: 2, label: 'Bouquets' },
      { value: 7, label: 'Vases' },
    ])
  })
})
