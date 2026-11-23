import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { SIGNAL_TYPE_PAGE_RESPONSE } from '@hilos/core/base'

import { scopes } from '../../../src/bootstrap/session.js'
import { PAGE_ADMIN_VOUCHERS } from '../../../src/pages/keys.js'
import { router } from '../../../src/pages/routes.js'
import {
  FILTER_ACTIVE,
  VOUCHERS_TABLE,
} from '../../../src/views/AdminVouchers/adminVouchers.js'
import {
  createVouchersTable,
  type VouchersTable,
} from '../../../src/views/AdminVouchers/adminVouchersPage.js'

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

const mounted: VouchersTable[] = []

beforeEach(() => {
  vi.clearAllMocks()
  scopes.openPage(PAGE_ADMIN_VOUCHERS)
})

afterEach(() => {
  for (const table of mounted.splice(0)) table.dispose()
  scopes.dropPage()
  wire.listeners.clear()
})

function mount(): VouchersTable {
  const table = createVouchersTable(vi.fn())
  mounted.push(table)
  table.start()
  return table
}

function deliver(rows: readonly unknown[]): void {
  for (const listener of wire.listeners.get('projectSignal') ?? []) {
    listener({
      type: SIGNAL_TYPE_PAGE_RESPONSE,
      data: {
        page: PAGE_ADMIN_VOUCHERS,
        payload: {
          windows: {
            [VOUCHERS_TABLE]: {
              rows,
              totalCount: rows.length,
              totalExact: true,
              firstAnchor: null,
              lastAnchor: null,
              limit: 25,
              sort: [{ field: 'code', direction: 'asc' }],
              filter: {},
            },
          },
        },
      },
    })
  }
}

describe('Vouchers table', () => {
  it('matches the vouchers route both ways and keeps it administrative', () => {
    expect(router.match('/hilos/app/vouchers')).toEqual({
      page: PAGE_ADMIN_VOUCHERS,
      params: {},
      admin: true,
    })
    expect(router.path(PAGE_ADMIN_VOUCHERS)).toBe('/hilos/app/vouchers')
    expect(router.icon?.(PAGE_ADMIN_VOUCHERS)).toBe('bi-ticket-perforated')
  })

  it('resolves an active and an inactive voucher from the window', () => {
    const table = mount()
    deliver([
      {
        rowKey: 4,
        slots: {
          voucher: {
            id: 4,
            code: 'SUMMER15',
            discountPercent: 15,
            active: true,
          },
        },
      },
      {
        rowKey: 9,
        slots: {
          voucher: {
            id: 9,
            code: 'QUIET40',
            discountPercent: 40,
            active: false,
          },
        },
      },
    ])

    expect(table.controller.rows.get().map((view) => view.row)).toEqual([
      { id: 4, code: 'SUMMER15', discountPercent: 15, active: true },
      { id: 9, code: 'QUIET40', discountPercent: 40, active: false },
    ])
  })

  it('sends search and Off on the vouchers window and stops after dispose', () => {
    const table = mount()
    expect(wire.connection.sendTableFacets).not.toHaveBeenCalled()
    deliver([])
    expect(wire.connection.sendTableFacets).toHaveBeenCalledExactlyOnceWith(
      PAGE_ADMIN_VOUCHERS,
      VOUCHERS_TABLE,
      { [FILTER_ACTIVE]: [true, false] },
    )

    table.controller.setSearch('SUMMER')
    expect(table.controller.filter.get()).toEqual({ search: 'SUMMER' })
    table.controller.setFilter(FILTER_ACTIVE, false)
    expect(table.controller.filter.get()).toEqual({
      search: 'SUMMER',
      [FILTER_ACTIVE]: false,
    })
    expect(table.controller.filter.get()[FILTER_ACTIVE]).toBe(false)
    table.controller.setFilter(FILTER_ACTIVE, null)
    expect(table.controller.filter.get()).toEqual({ search: 'SUMMER' })

    expect(wire.connection.sendTableViewport).toHaveBeenCalledTimes(3)
    for (const call of wire.connection.sendTableViewport.mock.calls) {
      expect(call[0]).toBe(PAGE_ADMIN_VOUCHERS)
      expect(call[1]).toBe(VOUCHERS_TABLE)
    }
    expect(wire.connection.sendTableViewport).toHaveBeenNthCalledWith(
      1,
      PAGE_ADMIN_VOUCHERS,
      VOUCHERS_TABLE,
      expect.objectContaining({ filter: { search: 'SUMMER' } }),
    )
    expect(wire.connection.sendTableViewport).toHaveBeenNthCalledWith(
      2,
      PAGE_ADMIN_VOUCHERS,
      VOUCHERS_TABLE,
      expect.objectContaining({
        filter: { search: 'SUMMER', [FILTER_ACTIVE]: false },
      }),
    )
    expect(wire.connection.sendTableViewport).toHaveBeenNthCalledWith(
      3,
      PAGE_ADMIN_VOUCHERS,
      VOUCHERS_TABLE,
      expect.objectContaining({ filter: { search: 'SUMMER' } }),
    )

    table.dispose()
    table.dispose()
    vi.clearAllMocks()
    table.start()
    deliver([])
    expect(wire.connection.registerTableWindow).not.toHaveBeenCalled()
    expect(wire.connection.sendTableFacets).not.toHaveBeenCalled()
    expect(wire.connection.sendTableViewport).not.toHaveBeenCalled()
    expect(wire.connection.sendTableRendered).not.toHaveBeenCalled()
    expect(wire.connection.sendTableRowFocus).not.toHaveBeenCalled()

    const replacement = mount()
    deliver([])
    expect(wire.connection.sendTableFacets).toHaveBeenCalledExactlyOnceWith(
      PAGE_ADMIN_VOUCHERS,
      VOUCHERS_TABLE,
      { [FILTER_ACTIVE]: [true, false] },
    )
    replacement.controller.setSearch('QUIET')
    expect(wire.connection.sendTableViewport).toHaveBeenCalledExactlyOnceWith(
      PAGE_ADMIN_VOUCHERS,
      VOUCHERS_TABLE,
      expect.objectContaining({ filter: { search: 'QUIET' } }),
    )
  })
})
