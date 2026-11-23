import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  SIGNAL_TYPE_PAGE_RESPONSE,
  createSignal,
  type ActionHandle,
} from '@hilos/core/base'

import { scopes } from '../../../src/bootstrap/session.js'
import { PAGE_ADMIN_PRODUCT_STOCK } from '../../../src/pages/keys.js'
import { PRODUCT_STOCK_SET_UNITS } from '../../../src/views/AdminProductStock/adminProductStockActions.js'
import { createProductStockTable } from '../../../src/views/AdminProductStock/adminProductStockPage.js'

const PRODUCT_ID = 12
const WAREHOUSE_ID = 1
const WAREHOUSE_NAME = 'Kyiv · Podil'
const STOCK_TABLE = 'productStock'

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
    actions: { dispatch: vi.fn() },
  }
})

vi.mock('../../../src/bootstrap/connection.js', () => ({
  connection: wire.connection,
  actions: wire.actions,
}))
vi.mock('../../../src/bootstrap/session.js', async () => {
  const { ScopeManager } = await import('@hilos/core/base')
  return { scopes: new ScopeManager() }
})

const handle: ActionHandle = {
  requestId: 'stock-set-units-1',
  loading: createSignal(false),
  done: Promise.resolve({}),
}

let table: ReturnType<typeof createProductStockTable>

beforeEach(() => {
  vi.clearAllMocks()
  wire.actions.dispatch.mockReturnValue(handle)
  scopes.openPage(PAGE_ADMIN_PRODUCT_STOCK)
  table = createProductStockTable(PRODUCT_ID)
  table.start()
})

afterEach(() => {
  table.dispose()
  scopes.dropPage()
  wire.listeners.clear()
})

function emit(name: string, signal: unknown): void {
  for (const listener of wire.listeners.get(name) ?? []) {
    listener(signal)
  }
}

function stockRow(units: number, warehouseName = WAREHOUSE_NAME) {
  return {
    rowKey: WAREHOUSE_ID,
    slots: { stock: { warehouseId: WAREHOUSE_ID, warehouseName, units } },
  }
}

function openEdit(units: number) {
  const anchor = { warehouseName: WAREHOUSE_NAME, warehouseId: WAREHOUSE_ID }
  emit('projectSignal', {
    type: SIGNAL_TYPE_PAGE_RESPONSE,
    data: {
      page: PAGE_ADMIN_PRODUCT_STOCK,
      payload: {
        windows: {
          [STOCK_TABLE]: {
            rows: [stockRow(units)],
            totalCount: 1,
            totalExact: true,
            firstAnchor: anchor,
            lastAnchor: anchor,
            limit: 25,
            sort: [{ field: 'warehouseName', direction: 'asc' }],
            filter: { product: PRODUCT_ID },
          },
        },
      },
    },
  })
  expect(table.edit.open(String(WAREHOUSE_ID))).toBe(true)
  return table.edit
}

function changeStock(units: number, warehouseName?: string): void {
  emit('tableViewportDelta', {
    data: {
      page: PAGE_ADMIN_PRODUCT_STOCK,
      tableKey: STOCK_TABLE,
      kind: 'row_updated',
      rowKey: WAREHOUSE_ID,
      row: stockRow(units, warehouseName),
    },
  })
}

describe('product stock edit', () => {
  it('opens an empty warehouse at zero and sends the raw edit with a zero baseline', async () => {
    const edit = openEdit(0)
    const run = vi.fn(() => Promise.resolve(true))

    expect(wire.connection.sendTableRowFocus).toHaveBeenCalledWith(
      PAGE_ADMIN_PRODUCT_STOCK,
      STOCK_TABLE,
      String(WAREHOUSE_ID),
    )
    expect(edit.form.get().units).toBe('0')
    expect(edit.canSave.get()).toBe(false)
    edit.patchForm({ units: '' })
    expect(edit.canSave.get()).toBe(false)

    edit.patchForm({ units: '007' })
    await edit.save(run)

    expect(wire.actions.dispatch).toHaveBeenCalledExactlyOnceWith(
      PRODUCT_STOCK_SET_UNITS,
      {
        productId: PRODUCT_ID,
        warehouseId: WAREHOUSE_ID,
        units: '007',
        expectedUnits: 0,
      },
    )
    expect(run).toHaveBeenCalledExactlyOnceWith(handle)
    expect(edit.opened.get()).toBe(false)
    expect(table.controller.focusedRow.get()).toBeUndefined()
    expect(wire.connection.sendTableRowFocus).toHaveBeenLastCalledWith(
      PAGE_ADMIN_PRODUCT_STOCK,
      STOCK_TABLE,
      '',
    )
  })

  it('uses the advanced baseline after Keep mine and keeps the draft on refusal', async () => {
    const edit = openEdit(7)
    edit.patchForm({ units: '007' })
    expect(edit.state.get().dirty).toBe(false)
    expect(edit.canSave.get()).toBe(false)

    changeStock(0, 'Kyiv · Obolon')
    expect(edit.form.get().units).toBe('0')
    expect(edit.live.get()?.warehouseName).toBe('Kyiv · Obolon')

    edit.patchForm({ units: '009' })
    changeStock(2, 'Kyiv · Obolon')
    const run = vi.fn(() => Promise.resolve(false))
    expect(edit.state.get().conflict).toBe(true)
    expect(edit.canSave.get()).toBe(false)
    await edit.save(run)
    expect(run).not.toHaveBeenCalled()

    edit.keepMine()
    expect(edit.canSave.get()).toBe(true)
    await edit.save(run)

    expect(wire.actions.dispatch).toHaveBeenCalledExactlyOnceWith(
      PRODUCT_STOCK_SET_UNITS,
      {
        productId: PRODUCT_ID,
        warehouseId: WAREHOUSE_ID,
        units: '009',
        expectedUnits: 2,
      },
    )
    expect(edit.opened.get()).toBe(true)
    expect(edit.form.get().units).toBe('009')
  })

  it.each([
    ['keepMine', '009', true],
    ['takeTheirs', '2', false],
  ] as const)(
    'blocks %s during save and allows it again after refusal',
    async (choose, expectedDraft, canSave) => {
      const edit = openEdit(0)
      edit.patchForm({ units: '009' })
      let refuse!: () => void
      const save = edit.save(
        () =>
          new Promise((resolve) => {
            refuse = () => resolve(false)
          }),
      )

      changeStock(2)
      expect(edit.saving.get()).toBe(true)
      expect(edit.state.get().conflict).toBe(true)
      edit[choose]()
      expect(edit.state.get().conflict).toBe(true)
      expect(edit.form.get().units).toBe('009')

      refuse()
      await save
      expect(edit.saving.get()).toBe(false)
      expect(edit.opened.get()).toBe(true)
      expect(edit.state.get().conflict).toBe(true)
      expect(edit.form.get().units).toBe('009')

      edit[choose]()
      expect(edit.state.get().conflict).toBe(false)
      expect(edit.form.get().units).toBe(expectedDraft)
      expect(edit.canSave.get()).toBe(canSave)
    },
  )

  it('keeps a draft visible and refuses to dispatch after its warehouse is deleted', async () => {
    const edit = openEdit(0)
    edit.patchForm({ units: '17' })
    emit('tableViewportDelta', {
      data: {
        page: PAGE_ADMIN_PRODUCT_STOCK,
        tableKey: STOCK_TABLE,
        kind: 'row_removed',
        rowKey: WAREHOUSE_ID,
        reason: 'deleted',
      },
    })

    expect(edit.opened.get()).toBe(true)
    expect(edit.form.get().units).toBe('17')
    expect(edit.saveLabel.get()).toBe('Deleted')
    expect(edit.canSave.get()).toBe(false)
    await edit.save(vi.fn())
    expect(wire.actions.dispatch).not.toHaveBeenCalled()
  })
})
