import { describe, expect, it, vi } from 'vitest'
import { Scope, ingest, type EntityRef } from '@hilos/core/base'

import { PAGE_ADMIN_CATEGORIES } from '../../../src/pages/keys.js'
import { resolveCategoryRow } from '../../../src/views/AdminCategories/adminCategoriesPage.js'
import { type CategoryRow } from '../../../src/views/AdminCategories/types/tables/CategoryRow.js'

// EcommerceShopTableContext::categories and CategoriesTable::ROW_SLOT.
const CATEGORIES_TABLE = 'categories'
const CATEGORY_SLOT = 'category'

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

const bouquets: CategoryRow = {
  id: 2,
  name: 'Bouquets',
  description: 'Fresh seasonal arrangements',
  position: 1,
  picture: '/pictures/bouquets.webp',
  pictureFileId: 12,
  picturePreview: '/files/12/catalog_sm',
  productCount: 14,
  first: true,
}

const pots: CategoryRow = {
  id: 7,
  name: 'Pots',
  description: 'Ceramic and clay planters',
  position: 2,
  picture: '/pictures/pots.webp',
  pictureFileId: 17,
  picturePreview: '/files/17/catalog_sm',
  productCount: 5,
  first: false,
}

describe('Categories table rows', () => {
  it('resolves a snapshot of two rows into every field of CategoryRow', () => {
    const scope = new Scope('page', PAGE_ADMIN_CATEGORIES)
    const fieldsOf = (ref: EntityRef) =>
      scope.entities.signal(ref).get()?.fields
    ingest(scope, {
      tables: {
        [CATEGORIES_TABLE]: {
          rows: [
            {
              rowKey: bouquets.id,
              slots: { [CATEGORY_SLOT]: { ...bouquets } },
            },
            {
              rowKey: pots.id,
              slots: { [CATEGORY_SLOT]: { ...pots } },
            },
          ],
        },
      },
    })

    const rows = scope.tables
      .signal(CATEGORIES_TABLE)
      .get()
      .map((row) => resolveCategoryRow(row, fieldsOf))

    expect(rows).toEqual([bouquets, pots])
    expect(rows[0]?.first).toBe(true)
    expect(rows[1]?.first).toBe(false)
  })

  it('follows an entity delta for the category name and productCount', () => {
    const scope = new Scope('page', PAGE_ADMIN_CATEGORIES)
    const fieldsOf = (ref: EntityRef) =>
      scope.entities.signal(ref).get()?.fields
    ingest(scope, {
      tables: {
        [CATEGORIES_TABLE]: {
          rows: [
            {
              rowKey: bouquets.id,
              slots: { [CATEGORY_SLOT]: { ...bouquets } },
            },
          ],
        },
      },
    })
    ingest(scope, {
      tables: {
        [CATEGORIES_TABLE]: {
          rows: [
            {
              rowKey: bouquets.id,
              slots: {
                [CATEGORY_SLOT]: {
                  id: bouquets.id,
                  name: 'Winter Bouquets',
                  productCount: 21,
                },
              },
            },
          ],
        },
      },
    })
    const [row] = scope.tables.signal(CATEGORIES_TABLE).get()

    expect(resolveCategoryRow(row!, fieldsOf)).toEqual({
      ...bouquets,
      name: 'Winter Bouquets',
      productCount: 21,
    })
  })

  it('uses the row key and empty fields without a category slot', () => {
    const scope = new Scope('page', PAGE_ADMIN_CATEGORIES)
    ingest(scope, {
      tables: {
        [CATEGORIES_TABLE]: {
          rows: [{ rowKey: 19, slots: {} }],
        },
      },
    })
    const [row] = scope.tables.signal(CATEGORIES_TABLE).get()

    expect(
      resolveCategoryRow(
        row!,
        (ref) => scope.entities.signal(ref).get()?.fields,
      ),
    ).toEqual({
      id: 19,
      name: '',
      description: '',
      position: 0,
      picture: '',
      pictureFileId: 0,
      picturePreview: '',
      productCount: 0,
      first: false,
    })
  })
})
