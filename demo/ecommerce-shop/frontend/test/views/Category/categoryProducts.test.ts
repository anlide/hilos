import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  SIGNAL_TYPE_PAGE_RESPONSE,
  Scope,
  hilosTableViews,
  ingest,
  parseSignalValue,
  type EntityRef,
} from '@hilos/core/base'

import { scopes } from '../../../src/bootstrap/session.js'
import { kindText } from '../../../src/catalog/catalog.js'
import {
  availabilityText,
  availabilityTone,
} from '../../../src/catalog/productAvailability.js'
import {
  CATEGORY_PRODUCTS_COLUMNS,
  CATEGORY_PRODUCTS_FRAME,
  CATEGORY_PRODUCTS_TABLE,
  resolveCategoryProductRow,
} from '../../../src/views/Category/categoryProducts.js'
import {
  createCategoryProductsTable,
  type CategoryProductsTable,
} from '../../../src/views/Category/categoryProductsTable.js'
import { type CategoryProductRow } from '../../../src/views/Category/types/tables/CategoryProductRow.js'

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

const page = 'category-fixture'
const stock: CategoryProductRow = {
  id: 8,
  slug: 'ceramic-pot',
  name: 'Ceramic pot',
  pictureUrl: '/pictures/pot.webp',
  pictureAlt: '',
  kind: 'stock',
  price: 41000,
  units: 42,
  availability: 'in_stock',
}
const unique: CategoryProductRow = {
  ...stock,
  pictureAlt: 'Blue glaze with white stripes',
  kind: 'unique',
  units: 1,
  availability: 'free',
}

function emit(name: string, signal: unknown): void {
  for (const listener of wire.listeners.get(name) ?? []) listener(signal)
}

function firstWindow(categoryId: number, product: CategoryProductRow): void {
  emit('projectSignal', {
    type: SIGNAL_TYPE_PAGE_RESPONSE,
    data: {
      page,
      payload: {
        windows: {
          [CATEGORY_PRODUCTS_TABLE]: {
            rows: [{ rowKey: product.id, slots: { product } }],
            totalCount: 1,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
            limit: 25,
            sort: [{ field: 'price', direction: 'asc' }],
            filter: { category: categoryId },
          },
        },
      },
    },
  })
}

describe('category products declaration', () => {
  it.each([stock, unique])(
    'projects the full inline product slot for $kind',
    (product) => {
      expect(
        resolveCategoryProductRow(
          { rowKey: String(product.id), slots: { product } },
          () => undefined,
        ),
      ).toEqual(product)
    },
  )

  it('resolves a normalized window row and follows committed product changes', () => {
    const scope = new Scope('page', page)
    const fieldsOf = (ref: EntityRef) =>
      scope.entities.signal(ref).get()?.fields
    ingest(scope, {
      tables: {
        [CATEGORY_PRODUCTS_TABLE]: {
          rows: [{ rowKey: stock.id, slots: { product: stock } }],
        },
      },
    })
    const [row] = scope.tables.signal(CATEGORY_PRODUCTS_TABLE).get()
    expect(row!.slots.product).toEqual({ type: 'product', id: stock.id })
    expect(resolveCategoryProductRow(row!, fieldsOf)).toEqual(stock)

    const changes = {
      slug: 'blue-ceramic-pot',
      pictureUrl: '/pictures/blue-pot.webp',
      units: 3,
      availability: 'low',
    }
    ingest(scope, {
      tables: {
        [CATEGORY_PRODUCTS_TABLE]: {
          rows: [
            {
              rowKey: stock.id,
              slots: { product: { id: stock.id, ...changes } },
            },
          ],
        },
      },
    })

    expect(resolveCategoryProductRow(row!, fieldsOf)).toEqual({
      ...stock,
      ...changes,
    })
  })

  it.each([
    ['unique', 'Unique piece'],
    ['stock', 'Stock item'],
  ])('names the %s kind', (kind, label) => {
    expect(kindText(kind)).toBe(label)
  })

  it.each([
    ['free', 1, 'One of a kind', 'success'],
    ['in_stock', 42, 'In stock: 42', 'success'],
    ['low', 3, 'Only 3 left', 'warning'],
    ['out_of_stock', 0, 'Out of stock', 'secondary'],
  ])('describes %s availability', (availability, units, label, tone) => {
    expect(availabilityText(availability, units)).toBe(label)
    expect(availabilityTone(availability)).toBe(tone)
  })

  it('opens in Tiles and offers Table with the same product fields', () => {
    expect(
      hilosTableViews(CATEGORY_PRODUCTS_FRAME.views, CATEGORY_PRODUCTS_COLUMNS),
    ).toEqual({
      tableKey: 'categoryProducts',
      order: ['tiles', 'table'],
      imageColumn: 'pictureUrl',
    })
    expect(
      CATEGORY_PRODUCTS_COLUMNS.filter((column) => column.sortable).map(
        (column) => column.key,
      ),
    ).toEqual(['price'])
  })

  it('offers the storefront kind and availability choices', () => {
    expect(
      CATEGORY_PRODUCTS_FRAME.filters.map((filter) => [
        filter.key,
        filter.options(),
      ]),
    ).toEqual([
      [
        'kind',
        [
          { value: 'unique', label: 'Unique piece' },
          { value: 'stock', label: 'Stock item' },
        ],
      ],
      [
        'availability',
        [
          { value: 'available', label: 'In stock' },
          { value: 'out_of_stock', label: 'Out of stock' },
        ],
      ],
    ])
  })
})

describe('category products window', () => {
  const mounted: CategoryProductsTable[] = []

  beforeEach(() => {
    vi.clearAllMocks()
    scopes.openPage(page)
  })

  afterEach(() => {
    for (const table of mounted.splice(0)) table.dispose()
    scopes.dropPage()
    wire.listeners.clear()
  })

  function mount(categoryId: number): CategoryProductsTable {
    const table = createCategoryProductsTable(page, categoryId)
    mounted.push(table)
    table.start()
    return table
  }

  it('declares only the two selects as facets and keeps the category on reset', () => {
    const table = mount(2)
    expect(wire.connection.sendTableFacets).not.toHaveBeenCalled()
    firstWindow(2, stock)

    expect(table.controller.rows.get().map(({ row }) => row)).toEqual([stock])
    expect(wire.connection.sendTableViewport).not.toHaveBeenCalled()
    expect(wire.connection.sendTableFacets).toHaveBeenCalledExactlyOnceWith(
      page,
      CATEGORY_PRODUCTS_TABLE,
      {
        kind: ['unique', 'stock'],
        availability: ['available', 'out_of_stock'],
      },
    )
    expect(wire.connection.sendTableRendered).toHaveBeenCalledExactlyOnceWith(
      page,
      CATEGORY_PRODUCTS_TABLE,
      expect.arrayContaining(['pictureUrl', 'pictureAlt', 'slug', 'units']),
    )

    table.controller.setFilter('kind', 'stock')
    table.controller.resetFilters()

    expect(table.controller.filter.get()).toEqual({ category: 2 })
    expect(wire.connection.sendTableViewport).toHaveBeenLastCalledWith(
      page,
      CATEGORY_PRODUCTS_TABLE,
      expect.objectContaining({ filter: { category: 2 } }),
    )
  })

  it('refreshes the picture text in the open window on a description-only delta', () => {
    const table = mount(2)
    firstWindow(2, unique)
    expect(table.controller.rows.get().map(({ row }) => row)).toEqual([unique])

    const pictureAlt = 'Blue glaze with raised white stripes'
    const rowKey = String(unique.id)
    const parsed = parseSignalValue({
      type: 'table_viewport_delta',
      data: {
        page,
        tableKey: CATEGORY_PRODUCTS_TABLE,
        kind: 'row_updated',
        rowKey,
        row: { rowKey, slots: { product: { id: unique.id, pictureAlt } } },
      },
    })
    if (!parsed.ok) throw new Error('Could not parse the viewport delta frame')
    emit(parsed.signal.kind, parsed.signal)

    expect(table.controller.rows.get().map(({ row }) => row)).toEqual([
      { ...unique, pictureAlt },
    ])
  })

  it('makes exact server counts available beside each select option', () => {
    const table = mount(2)
    firstWindow(2, stock)
    const exact = (count: number) => ({ count, exact: true })
    const parsed = parseSignalValue({
      type: 'table_facet_counts',
      data: {
        page,
        tableKey: CATEGORY_PRODUCTS_TABLE,
        facets: {
          kind: {
            any: exact(1),
            options: { unique: exact(0), stock: exact(1) },
          },
          availability: {
            any: exact(1),
            options: { available: exact(1), out_of_stock: exact(0) },
          },
        },
      },
    })
    if (!parsed.ok) throw new Error('Could not parse the facet counts frame')
    emit(parsed.signal.kind, parsed.signal)

    expect(
      table.controller.frame.filters.get().map(({ filter, facets }) => {
        if (filter.kind !== 'select') throw new Error('Expected a select')
        return filter
          .options()
          .map(({ value }) => [value, facets?.options.get(String(value))])
      }),
    ).toEqual([
      [
        ['unique', exact(0)],
        ['stock', exact(1)],
      ],
      [
        ['available', exact(1)],
        ['out_of_stock', exact(0)],
      ],
    ])
  })

  it('releases every binding on cleanup and opens a fresh window on each setup', () => {
    for (const categoryId of [2, 2, 7]) {
      const table = mount(categoryId)
      table.start()
      const product = { ...stock, id: categoryId, slug: `pot-${categoryId}` }
      expect(wire.listeners.get('projectSignal')?.size).toBe(1)
      expect(
        wire.connection.registerTableWindow,
      ).toHaveBeenCalledExactlyOnceWith(
        CATEGORY_PRODUCTS_TABLE,
        table.controller,
      )
      firstWindow(categoryId, product)
      expect(table.controller.rows.get().map(({ row }) => row)).toEqual([
        product,
      ])
      expect(table.controller.filter.get()).toEqual({ category: categoryId })
      expect(wire.connection.sendTableFacets).toHaveBeenCalledTimes(1)

      table.dispose()
      table.dispose()
      table.start()
      expect(
        wire.connection.unregisterTableWindow,
      ).toHaveBeenCalledExactlyOnceWith(
        CATEGORY_PRODUCTS_TABLE,
        table.controller,
      )
      expect([...wire.listeners.values()].flatMap((set) => [...set])).toEqual(
        [],
      )
      vi.clearAllMocks()
    }
  })
})
