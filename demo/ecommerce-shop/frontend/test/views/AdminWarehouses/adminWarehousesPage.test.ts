import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { SIGNAL_TYPE_PAGE_RESPONSE } from '@hilos/core/base'

import { scopes } from '../../../src/bootstrap/session.js'
import { PAGE_ADMIN_WAREHOUSES } from '../../../src/pages/keys.js'
import {
  createWarehousesTable,
  type WarehousesTable,
} from '../../../src/views/AdminWarehouses/adminWarehousesPage.js'

// EcommerceShopTableContext::warehouses and WarehousesTable::ROW_SLOT.
const WAREHOUSES_TABLE = 'warehouses'
const WAREHOUSE_SLOT = 'warehouse'

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

const mounted: WarehousesTable[] = []

beforeEach(() => {
  vi.clearAllMocks()
  scopes.openPage(PAGE_ADMIN_WAREHOUSES)
})

afterEach(() => {
  for (const table of mounted.splice(0)) table.dispose()
  scopes.dropPage()
  wire.listeners.clear()
})

function mount(): WarehousesTable {
  const table = createWarehousesTable(() => undefined)
  mounted.push(table)
  table.start()
  return table
}

/** The page's own answer, carrying the first window with one warehouse row. */
function firstWindow(): void {
  for (const listener of wire.listeners.get('projectSignal') ?? []) {
    listener({
      type: SIGNAL_TYPE_PAGE_RESPONSE,
      data: {
        page: PAGE_ADMIN_WAREHOUSES,
        payload: {
          windows: {
            [WAREHOUSES_TABLE]: {
              rows: [
                {
                  rowKey: 1,
                  slots: {
                    [WAREHOUSE_SLOT]: {
                      id: 1,
                      name: 'Kyiv · Podil',
                      address: 'Kyiv, 7 Kostiantynivska St',
                      productCount: 19,
                      unitCount: 546,
                    },
                  },
                },
              ],
              totalCount: 1,
              totalExact: true,
              firstAnchor: { name: 'Kyiv · Podil', id: 1 },
              lastAnchor: { name: 'Kyiv · Podil', id: 1 },
              limit: 25,
              sort: [{ field: 'name', direction: 'asc' }],
              filter: {},
            },
          },
        },
      },
    })
  }
}

describe('Warehouses table rows', () => {
  // The slot bears an id, so binding files it as an entity and the row keeps
  // only a reference: a resolver reading the slot as a record drew blank cells.
  it('resolves a window row into every field of its warehouse', () => {
    const table = mount()
    firstWindow()

    expect(table.controller.rows.get().map((shown) => shown.row)).toEqual([
      {
        id: 1,
        name: 'Kyiv · Podil',
        address: 'Kyiv, 7 Kostiantynivska St',
        productCount: 19,
        unitCount: 546,
      },
    ])
  })
})
