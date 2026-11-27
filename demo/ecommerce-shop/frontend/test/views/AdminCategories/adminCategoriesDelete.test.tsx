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
import { PAGE_ADMIN_CATEGORIES } from '../../../src/pages/keys.js'
import { router as routes } from '../../../src/pages/routes.js'
import AdminCategories from '../../../src/views/AdminCategories/AdminCategories.js'
import { CATEGORY_PRODUCTS_REASON } from '../../../src/views/AdminCategories/categoryDelete.js'
import { type CategoryRow } from '../../../src/views/AdminCategories/types/tables/CategoryRow.js'

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

const stockedCategory: CategoryRow = {
  id: 1,
  name: 'Bouquets',
  description: 'Fresh seasonal arrangements',
  position: 1,
  picture: '/files/1/catalog_sm',
  pictureFileId: 1,
  picturePreview: '/files/1/catalog_preview',
  productCount: 19,
  first: true,
}

const emptyCategory: CategoryRow = {
  id: 2,
  name: 'Dried flowers',
  description: 'Long-lasting natural stems',
  position: 2,
  picture: '/files/2/catalog_sm',
  pictureFileId: 2,
  picturePreview: '/files/2/catalog_preview',
  productCount: 0,
  first: false,
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
  rows: CategoryRow[] = [stockedCategory, emptyCategory],
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
    pathname: () => routes.path(PAGE_ADMIN_CATEGORIES)!,
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
        <AdminCategories />
      </HilosRouterContext.Provider>,
    )
  })

  await act(async () => {
    wire.emit('projectSignal', {
      type: SIGNAL_TYPE_PAGE_RESPONSE,
      data: {
        page: PAGE_ADMIN_CATEGORIES,
        payload: {
          windows: {
            categories: {
              rows: rows.map((row) => ({
                rowKey: row.id,
                slots: { category: row },
              })),
              totalCount: rows.length,
              totalExact: true,
              firstAnchor: rows[0]
                ? { position: rows[0].position, id: rows[0].id }
                : null,
              lastAnchor: rows[rows.length - 1]
                ? {
                    position: rows[rows.length - 1].position,
                    id: rows[rows.length - 1].id,
                  }
                : null,
              limit: 25,
              sort: [{ field: 'position', direction: 'asc' }],
              filter: {},
            },
          },
        },
      },
    })
  })
}

it('disables delete for category with products with reason description, and enables it for empty category', async () => {
  await mountPage()

  const stockedBtn = document.querySelector<HTMLButtonElement>(
    '[data-id="admin-categories-delete-open"][aria-label="Delete · Bouquets"]',
  )
  expect(stockedBtn).not.toBeNull()
  expect(stockedBtn?.disabled).toBe(true)
  expect(stockedBtn?.getAttribute('aria-label')).toBe('Delete · Bouquets')
  const stockedDescId = stockedBtn?.getAttribute('aria-describedby')
  expect(stockedDescId).toBeTruthy()
  const stockedDescEl = document.getElementById(stockedDescId!)
  expect(stockedDescEl?.textContent).toBe(CATEGORY_PRODUCTS_REASON)

  const emptyBtn = document.querySelector<HTMLButtonElement>(
    '[data-id="admin-categories-delete-open"][aria-label="Delete · Dried flowers"]',
  )
  expect(emptyBtn).not.toBeNull()
  expect(emptyBtn?.disabled).toBe(false)
  expect(emptyBtn?.getAttribute('aria-label')).toBe('Delete · Dried flowers')
  expect(emptyBtn?.getAttribute('aria-describedby')).toBeNull()
})

it('opens delete confirmation with the category name and sends category_delete on confirm', async () => {
  await mountPage()

  const emptyBtn = document.querySelector<HTMLButtonElement>(
    '[data-id="admin-categories-delete-open"][aria-label="Delete · Dried flowers"]',
  )
  await act(async () => {
    emptyBtn?.click()
  })

  const modal = document.querySelector('[role="dialog"]')
  expect(modal).not.toBeNull()
  expect(modal?.textContent).toContain('Delete category?')
  expect(modal?.textContent).toContain('Permanently delete Dried flowers?')

  const confirmBtn = document.querySelector<HTMLButtonElement>(
    '[data-id="admin-categories-delete-confirm"]',
  )
  expect(confirmBtn).not.toBeNull()
  await act(async () => {
    confirmBtn?.click()
  })

  expect(wire.connection.sendAction).toHaveBeenCalledWith(
    'category_delete',
    { id: emptyCategory.id },
    expect.any(String),
  )
})

it('disables confirmation and moves focus to Cancel when products arrive while the modal is open', async () => {
  await mountPage()

  const emptyBtn = document.querySelector<HTMLButtonElement>(
    '[data-id="admin-categories-delete-open"][aria-label="Delete · Dried flowers"]',
  )
  await act(async () => {
    emptyBtn?.click()
  })

  const confirmBtn = document.querySelector<HTMLButtonElement>(
    '[data-id="admin-categories-delete-confirm"]',
  )
  const cancelBtn = document.querySelector<HTMLButtonElement>(
    '[data-id="admin-categories-delete-cancel"]',
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
        page: PAGE_ADMIN_CATEGORIES,
        tableKey: 'categories',
        kind: 'row_updated',
        rowKey: String(emptyCategory.id),
        row: {
          rowKey: emptyCategory.id,
          slots: {
            category: {
              ...emptyCategory,
              productCount: 5,
            },
          },
        },
      },
    })
  })

  expect(confirmBtn?.getAttribute('aria-disabled')).toBe('true')
  const descId = confirmBtn?.getAttribute('aria-describedby')
  expect(descId).toBe('admin-categories-delete-products-reason')
  const descEl = document.getElementById(descId!)
  expect(descEl?.textContent).toBe(CATEGORY_PRODUCTS_REASON)
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
    '[data-id="admin-categories-delete-open"][aria-label="Delete · Dried flowers"]',
  )
  await act(async () => {
    emptyBtn?.click()
  })

  const confirmBtn = document.querySelector<HTMLButtonElement>(
    '[data-id="admin-categories-delete-confirm"]',
  )
  expect(confirmBtn).not.toBeNull()

  await act(async () => {
    confirmBtn?.focus()
  })
  expect(document.activeElement).toBe(confirmBtn)

  await act(async () => {
    wire.emit('tableViewportDelta', {
      data: {
        page: PAGE_ADMIN_CATEGORIES,
        tableKey: 'categories',
        kind: 'row_removed',
        rowKey: String(emptyCategory.id),
        reason: 'deleted',
      },
    })
  })

  const goneEl = document.querySelector(
    '[data-id="admin-categories-delete-gone"]',
  )
  expect(goneEl).not.toBeNull()
  expect(goneEl?.textContent).toBe(
    'This category was already deleted elsewhere.',
  )
  expect(confirmBtn?.getAttribute('aria-disabled')).toBe('true')
  expect(confirmBtn?.getAttribute('aria-describedby')).toBe(
    'admin-categories-delete-gone',
  )
  const modal = document.querySelector('[role="dialog"]')
  expect(modal?.contains(document.activeElement)).toBe(true)

  wire.connection.sendAction.mockClear()
  await act(async () => {
    confirmBtn?.click()
  })
  expect(wire.connection.sendAction).not.toHaveBeenCalled()
})

it('has no delete button or delete confirmation modal inside Edit category', async () => {
  await mountPage()

  const editBtn = document.querySelector<HTMLButtonElement>(
    '[data-id="admin-categories-edit-open"][aria-label="Edit · Dried flowers"]',
  )
  await act(async () => {
    editBtn?.click()
  })

  const editModal = document.querySelector('[data-id="admin-categories-edit"]')
  expect(editModal).not.toBeNull()

  const editDeleteBtn = document.querySelector(
    '[data-id="admin-categories-edit-delete"]',
  )
  expect(editDeleteBtn).toBeNull()

  const deleteDialog = document.querySelector(
    '[role="dialog"][aria-label="Delete category?"]',
  )
  expect(deleteDialog).toBeNull()
})
