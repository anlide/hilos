import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  SIGNAL_TYPE_PAGE_RESPONSE,
  createSignal,
  type ActionHandle,
} from '@hilos/core/base'

import { scopes } from '../../../src/bootstrap/session.js'
import { PAGE_ADMIN_WAREHOUSES } from '../../../src/pages/keys.js'
import { WAREHOUSE_UPDATE } from '../../../src/views/AdminWarehouses/adminWarehousesActions.js'
import {
  createWarehousesTable,
  type WarehousesTable,
} from '../../../src/views/AdminWarehouses/adminWarehousesPage.js'
import {
  createWarehouseEdit,
  type WarehouseEdit,
} from '../../../src/views/AdminWarehouses/warehouseEdit.js'

// EcommerceShopTableContext::warehouses and WarehousesTable::ROW_SLOT.
const WAREHOUSES_TABLE = 'warehouses'
const WAREHOUSE_SLOT = 'warehouse'

const OPENING_NAME = 'Kyiv · Podil'
const OPENING_ADDRESS = 'Kyiv, 7 Kostiantynivska St'

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

/** The handle every dispatch answers with: the test's runner settles the save. */
const handle: ActionHandle = {
  requestId: 'warehouse-update-1',
  loading: createSignal(false),
  done: Promise.resolve({}),
}

const mounted: Array<{ table: WarehousesTable; edit: WarehouseEdit }> = []

beforeEach(() => {
  vi.clearAllMocks()
  wire.actions.dispatch.mockReturnValue(handle)
  scopes.openPage(PAGE_ADMIN_WAREHOUSES)
})

afterEach(() => {
  for (const { table, edit } of mounted.splice(0)) {
    edit.dispose()
    table.dispose()
  }
  scopes.dropPage()
  wire.listeners.clear()
})

function emit(name: string, signal: unknown): void {
  for (const listener of wire.listeners.get(name) ?? []) {
    listener(signal)
  }
}

/** The warehouses table with its edit session, bound the way the page binds them. */
function mount(): WarehouseEdit {
  const table = createWarehousesTable(() => undefined)
  const edit = createWarehouseEdit(table.controller)
  mounted.push({ table, edit })
  table.start()
  edit.start()
  return edit
}

/** The warehouse row's slot, as the backend sends it. */
function warehouseSlot(
  name: string,
  address: string,
  productCount = 19,
): Record<string, unknown> {
  return { id: 1, name, address, productCount, unitCount: productCount * 2 }
}

/** The page's own answer, carrying the first window with one warehouse row. */
function firstWindow(productCount?: number): void {
  emit('projectSignal', {
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
                  [WAREHOUSE_SLOT]: warehouseSlot(
                    OPENING_NAME,
                    OPENING_ADDRESS,
                    productCount,
                  ),
                },
              },
            ],
            totalCount: 1,
            totalExact: true,
            firstAnchor: { name: OPENING_NAME, id: 1 },
            lastAnchor: { name: OPENING_NAME, id: 1 },
            limit: 25,
            sort: [{ field: 'name', direction: 'asc' }],
            filter: {},
          },
        },
      },
    },
  })
}

/** Another tab saved the warehouse: the row changed where it stood. */
function changedElsewhere(
  name: string,
  address: string,
  productCount?: number,
): void {
  emit('tableViewportDelta', {
    data: {
      page: PAGE_ADMIN_WAREHOUSES,
      tableKey: WAREHOUSES_TABLE,
      kind: 'row_updated',
      rowKey: '1',
      row: {
        rowKey: 1,
        slots: { [WAREHOUSE_SLOT]: warehouseSlot(name, address, productCount) },
      },
    },
  })
}

/** Another tab deleted the warehouse. */
function deletedElsewhere(): void {
  emit('tableViewportDelta', {
    data: {
      page: PAGE_ADMIN_WAREHOUSES,
      tableKey: WAREHOUSES_TABLE,
      kind: 'row_removed',
      rowKey: '1',
      reason: 'deleted',
    },
  })
}

/** The edit opened on the first window's row. */
function opened(productCount?: number): WarehouseEdit {
  const edit = mount()
  firstWindow(productCount)
  expect(edit.open('1')).toBe(true)
  return edit
}

describe('warehouse edit', () => {
  it('opens on the row, holds it in focus and has nothing to save yet', () => {
    const edit = opened()

    expect(edit.form.get()).toEqual({
      name: OPENING_NAME,
      address: OPENING_ADDRESS,
    })
    expect(wire.connection.sendTableRowFocus).toHaveBeenCalledWith(
      PAGE_ADMIN_WAREHOUSES,
      WAREHOUSES_TABLE,
      '1',
    )
    expect(edit.saveAvailable.get()).toBe(false)
  })

  it('takes an address changed elsewhere into an untouched field', () => {
    const edit = opened()

    changedElsewhere(OPENING_NAME, 'Kyiv, 3 Test Street')

    expect(edit.form.get().address).toBe('Kyiv, 3 Test Street')
    expect(edit.state.get().notice?.kind).toBe('updated')
  })

  it('stops on a name both sides changed until the person keeps theirs', () => {
    const edit = opened()

    edit.patchForm({ name: 'Kyiv · Left Bank' })
    changedElsewhere('Kyiv · Obolon', OPENING_ADDRESS)

    expect(edit.state.get().conflict).toBe(true)
    expect(edit.noticeText.get()).toBe(
      'Name changed elsewhere to "Kyiv · Obolon".',
    )
    expect(edit.canSave.get()).toBe(false)

    edit.keepMine()

    expect(edit.canSave.get()).toBe(true)
  })

  it('sends what the live row holds as Save is pressed for the expected values', async () => {
    const edit = opened()
    const sent: ActionHandle[] = []

    changedElsewhere(OPENING_NAME, 'Kyiv, 3 Test Street')
    edit.patchForm({ name: 'Kyiv · Left Bank' })
    await edit.save((action) => {
      sent.push(action)
      return Promise.resolve(true)
    })

    expect(wire.actions.dispatch).toHaveBeenCalledWith(WAREHOUSE_UPDATE, {
      id: 1,
      name: 'Kyiv · Left Bank',
      address: 'Kyiv, 3 Test Street',
      expectedName: OPENING_NAME,
      expectedAddress: 'Kyiv, 3 Test Street',
    })
    expect(sent).toEqual([handle])
  })

  it('has nothing to save while a field is empty', () => {
    const edit = opened()

    edit.patchForm({ address: '' })

    expect(edit.saveAvailable.get()).toBe(false)
  })

  it('says Deleted and blocks Save once the row is deleted elsewhere', () => {
    const edit = opened()

    edit.patchForm({ name: 'Kyiv · Left Bank' })
    deletedElsewhere()

    expect(edit.saveLabel.get()).toBe('Deleted')
    expect(edit.canSave.get()).toBe(false)
  })

  it('keeps the draft while the warehouse changes elsewhere', () => {
    const edit = opened(0)
    edit.patchForm({ name: 'Kyiv · Left Bank' })

    changedElsewhere('Kyiv · Obolon', OPENING_ADDRESS, 3)

    expect(edit.opened.get()).toBe(true)
    expect(edit.form.get().name).toBe('Kyiv · Left Bank')

    changedElsewhere('Kyiv · Obolon', OPENING_ADDRESS, 0)

    expect(edit.opened.get()).toBe(true)
    expect(edit.form.get().name).toBe('Kyiv · Left Bank')
  })

  it('keeps the draft of a warehouse deleted elsewhere', () => {
    const edit = opened(0)
    edit.patchForm({ address: 'Kyiv, 3 Test Street' })

    deletedElsewhere()

    expect(edit.opened.get()).toBe(true)
    expect(edit.form.get()).toEqual({
      name: OPENING_NAME,
      address: 'Kyiv, 3 Test Street',
    })
  })
})
