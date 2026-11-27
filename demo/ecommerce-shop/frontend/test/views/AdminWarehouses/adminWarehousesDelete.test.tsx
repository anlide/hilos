// @vitest-environment happy-dom

import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import {
  SIGNAL_TYPE_PAGE_RESPONSE,
  USER_ENTITY_TYPE,
  bindAdminAccess,
  bindPageScope,
  createHilosRouter,
  ingest,
  type HilosConnection,
} from '@hilos/core/base'
import { HilosRouterContext } from '@hilos/react/base'

import { connection } from '../../../src/bootstrap/connection.js'
import { scopes } from '../../../src/bootstrap/session.js'
import { PAGE_ADMIN_WAREHOUSES } from '../../../src/pages/keys.js'
import { router as routes } from '../../../src/pages/routes.js'
import AdminWarehouses from '../../../src/views/AdminWarehouses/AdminWarehouses.js'
import { type WarehouseRow } from '../../../src/views/AdminWarehouses/types/tables/WarehouseRow.js'
import { WAREHOUSE_STOCK_REASON } from '../../../src/views/AdminWarehouses/warehouseDelete.js'

const wire = vi.hoisted(() => {
  const listeners = new Map<string, Set<(signal: unknown) => void>>()
  return {
    connection: {
      state: 'connected',
      send: () => true,
      sendAction: vi.fn<HilosConnection['sendAction']>(() => true),
      forgetPageFrames: () => {},
      tableWindowDescriptors: () => ({}),
      registerTableWindow: () => {},
      unregisterTableWindow: () => {},
      sendTableViewport: () => {},
      sendTableRendered: () => {},
      sendTableRowFocus: () => {},
      sendTableFacets: () => {},
      on(event: string, listener: (signal: unknown) => void) {
        const members = listeners.get(event) ?? new Set()
        members.add(listener)
        listeners.set(event, members)
        return () => members.delete(listener)
      },
    },
    emit(event: string, signal: unknown) {
      for (const listener of listeners.get(event) ?? []) listener(signal)
    },
  }
})

vi.mock('../../../src/bootstrap/connection.js', async () => {
  const { ActionLifecycle } = await import('@hilos/core/base')
  return {
    connection: wire.connection,
    actions: new ActionLifecycle(wire.connection as unknown as HilosConnection),
  }
})

vi.mock('../../../src/bootstrap/session.js', async () => {
  const { ScopeManager } = await import('@hilos/core/base')
  return { scopes: new ScopeManager() }
})

const stockedWarehouse: WarehouseRow = {
  id: 1,
  name: 'Kyiv · Podil',
  address: 'Kyiv, 7 Kostiantynivska St',
  productCount: 19,
  unitCount: 38,
}

const emptyWarehouse: WarehouseRow = {
  id: 2,
  name: 'Lviv · Center',
  address: 'Lviv, 1 Rynok Sq',
  productCount: 0,
  unitCount: 0,
}

let root: Root | undefined
const release: Array<() => void> = []

beforeEach(() => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  wire.connection.sendAction.mockClear()
})

afterEach(async () => {
  await act(async () => {
    root?.unmount()
  })
  root = undefined
  for (const stop of release.splice(0).reverse()) stop()
  scopes.dropPage()
  document.body.replaceChildren()
  vi.unstubAllGlobals()
})

async function mountPage(
  rows: WarehouseRow[] = [stockedWarehouse, emptyWarehouse],
) {
  ingest(
    scopes.session,
    {
      data: { adminViewMode: true },
      entities: { currentUser: { id: 1, admin: true } },
    },
    { entityTypes: { currentUser: USER_ENTITY_TYPE } },
  )
  release.push(bindAdminAccess(scopes))

  const pages = bindPageScope(connection, scopes)
  const router = createHilosRouter(routes, pages, {
    pathname: () => routes.path(PAGE_ADMIN_WAREHOUSES)!,
    pushState: () => {},
    replaceState: () => {},
    onPopState: () => () => {},
  })
  router.start()
  release.push(() => router.stop())
  pages.releaseOnSession()

  const mounted = createRoot(
    document.body.appendChild(document.createElement('div')),
  )
  root = mounted
  await act(async () => {
    mounted.render(
      <HilosRouterContext.Provider value={router}>
        <AdminWarehouses />
      </HilosRouterContext.Provider>,
    )
  })

  await act(async () => {
    wire.emit('projectSignal', {
      type: SIGNAL_TYPE_PAGE_RESPONSE,
      data: {
        page: PAGE_ADMIN_WAREHOUSES,
        payload: {
          windows: {
            warehouses: {
              rows: rows.map((row) => ({
                rowKey: row.id,
                slots: { warehouse: row },
              })),
              totalCount: rows.length,
              totalExact: true,
              firstAnchor: rows[0]
                ? { name: rows[0].name, id: rows[0].id }
                : null,
              lastAnchor: rows[rows.length - 1]
                ? {
                    name: rows[rows.length - 1].name,
                    id: rows[rows.length - 1].id,
                  }
                : null,
              limit: 25,
              sort: [{ field: 'name', direction: 'asc' }],
              filter: {},
            },
          },
        },
      },
    })
  })
}

it('disables delete for stocked warehouse with reason description, and enables it for empty warehouse', async () => {
  await mountPage()

  const stockedBtn = document.querySelector<HTMLButtonElement>(
    '[data-id="admin-warehouses-delete-open"][aria-label="Delete · Kyiv · Podil"]',
  )
  expect(stockedBtn).not.toBeNull()
  expect(stockedBtn?.disabled).toBe(true)
  expect(stockedBtn?.getAttribute('aria-label')).toBe('Delete · Kyiv · Podil')
  const stockedDescId = stockedBtn?.getAttribute('aria-describedby')
  expect(stockedDescId).toBeTruthy()
  const stockedDescEl = document.getElementById(stockedDescId!)
  expect(stockedDescEl?.textContent).toBe(WAREHOUSE_STOCK_REASON)

  const emptyBtn = document.querySelector<HTMLButtonElement>(
    '[data-id="admin-warehouses-delete-open"][aria-label="Delete · Lviv · Center"]',
  )
  expect(emptyBtn).not.toBeNull()
  expect(emptyBtn?.disabled).toBe(false)
  expect(emptyBtn?.getAttribute('aria-label')).toBe('Delete · Lviv · Center')
  expect(emptyBtn?.getAttribute('aria-describedby')).toBeNull()
})

it('opens delete confirmation with the warehouse name and sends warehouse_delete on confirm', async () => {
  await mountPage()

  const emptyBtn = document.querySelector<HTMLButtonElement>(
    '[data-id="admin-warehouses-delete-open"][aria-label="Delete · Lviv · Center"]',
  )
  await act(async () => {
    emptyBtn?.click()
  })

  const modal = document.querySelector('[role="dialog"]')
  expect(modal).not.toBeNull()
  expect(modal?.textContent).toContain('Delete warehouse?')
  expect(modal?.textContent).toContain('Permanently delete Lviv · Center?')

  const confirmBtn = document.querySelector<HTMLButtonElement>(
    '[data-id="admin-warehouses-delete-confirm"]',
  )
  expect(confirmBtn).not.toBeNull()
  await act(async () => {
    confirmBtn?.click()
  })

  expect(wire.connection.sendAction).toHaveBeenCalledWith(
    'warehouse_delete',
    { id: emptyWarehouse.id },
    expect.any(String),
  )
})

it('disables confirmation and moves focus to Cancel when stock arrives while the modal is open', async () => {
  await mountPage()

  const emptyBtn = document.querySelector<HTMLButtonElement>(
    '[data-id="admin-warehouses-delete-open"][aria-label="Delete · Lviv · Center"]',
  )
  await act(async () => {
    emptyBtn?.click()
  })

  const confirmBtn = document.querySelector<HTMLButtonElement>(
    '[data-id="admin-warehouses-delete-confirm"]',
  )
  const cancelBtn = document.querySelector<HTMLButtonElement>(
    '[data-id="admin-warehouses-delete-cancel"]',
  )
  expect(confirmBtn).not.toBeNull()
  expect(cancelBtn).not.toBeNull()

  await act(async () => {
    confirmBtn?.focus()
  })
  expect(document.activeElement).toBe(confirmBtn)

  await act(async () => {
    wire.emit('tableViewportDelta', {
      data: {
        page: PAGE_ADMIN_WAREHOUSES,
        tableKey: 'warehouses',
        kind: 'row_updated',
        rowKey: String(emptyWarehouse.id),
        row: {
          rowKey: emptyWarehouse.id,
          slots: {
            warehouse: {
              ...emptyWarehouse,
              productCount: 5,
              unitCount: 10,
            },
          },
        },
      },
    })
  })

  expect(confirmBtn?.getAttribute('aria-disabled')).toBe('true')
  const descId = confirmBtn?.getAttribute('aria-describedby')
  expect(descId).toBe('admin-warehouses-delete-stock-reason')
  const descEl = document.getElementById(descId!)
  expect(descEl?.textContent).toBe(WAREHOUSE_STOCK_REASON)
  expect(descEl?.classList.contains('invisible')).toBe(false)
  expect(document.activeElement).toBe(cancelBtn)

  wire.connection.sendAction.mockClear()
  await act(async () => {
    confirmBtn?.click()
  })
  expect(wire.connection.sendAction).not.toHaveBeenCalled()
})

it('disables confirmation and displays gone message when row is removed elsewhere while modal is open', async () => {
  await mountPage()

  const emptyBtn = document.querySelector<HTMLButtonElement>(
    '[data-id="admin-warehouses-delete-open"][aria-label="Delete · Lviv · Center"]',
  )
  await act(async () => {
    emptyBtn?.click()
  })

  const confirmBtn = document.querySelector<HTMLButtonElement>(
    '[data-id="admin-warehouses-delete-confirm"]',
  )
  expect(confirmBtn).not.toBeNull()

  await act(async () => {
    confirmBtn?.focus()
  })
  expect(document.activeElement).toBe(confirmBtn)

  await act(async () => {
    wire.emit('tableViewportDelta', {
      data: {
        page: PAGE_ADMIN_WAREHOUSES,
        tableKey: 'warehouses',
        kind: 'row_removed',
        rowKey: String(emptyWarehouse.id),
        reason: 'deleted',
      },
    })
  })

  const goneEl = document.querySelector(
    '[data-id="admin-warehouses-delete-gone"]',
  )
  expect(goneEl).not.toBeNull()
  expect(goneEl?.textContent).toBe(
    'This warehouse was already deleted elsewhere.',
  )
  expect(confirmBtn?.getAttribute('aria-disabled')).toBe('true')
  expect(confirmBtn?.getAttribute('aria-describedby')).toBe(
    'admin-warehouses-delete-gone',
  )
  const modal = document.querySelector('[role="dialog"]')
  expect(modal?.contains(document.activeElement)).toBe(true)

  wire.connection.sendAction.mockClear()
  await act(async () => {
    confirmBtn?.click()
  })
  expect(wire.connection.sendAction).not.toHaveBeenCalled()
})

it('has no delete button or delete confirmation modal inside Edit warehouse', async () => {
  await mountPage()

  const editBtn = document.querySelector<HTMLButtonElement>(
    '[data-id="admin-warehouses-edit-open"][aria-label="Edit · Lviv · Center"]',
  )
  await act(async () => {
    editBtn?.click()
  })

  const editModal = document.querySelector('[data-id="admin-warehouses-edit"]')
  expect(editModal).not.toBeNull()

  const editDeleteBtn = document.querySelector(
    '[data-id="admin-warehouses-edit-delete"]',
  )
  expect(editDeleteBtn).toBeNull()

  const deleteDialog = document.querySelector(
    '[role="dialog"][aria-label="Delete warehouse?"]',
  )
  expect(deleteDialog).toBeNull()
})
