import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  ActionError,
  createSignal,
  ingest,
  type ActionHandle,
  type PageError,
  type PageRouteMatch,
} from '@hilos/core/base'

import { scopes } from '../../../src/bootstrap/session.js'
import { ADMIN_PRODUCT_DATA } from '../../../src/catalog/adminProductCard.js'
import { PAGE_ADMIN_PRODUCT } from '../../../src/pages/keys.js'
import { type AdminProduct } from '../../../src/types/AdminProduct.js'
import { ADMIN_PRODUCT_WAREHOUSES_LIST } from '../../../src/views/AdminProduct/adminProduct.js'
import { PRODUCT_WAREHOUSE_PLACE } from '../../../src/views/AdminProduct/adminProductActions.js'
import { createAdminProductSignals } from '../../../src/views/AdminProduct/adminProductPage.js'
import { createAdminProductWarehouseEdit } from '../../../src/views/AdminProduct/adminProductWarehouseEdit.js'

const wire = vi.hoisted(() => ({ dispatch: vi.fn() }))

vi.mock('../../../src/bootstrap/connection.js', () => ({
  connection: { state: 'connected' },
  actions: { dispatch: wire.dispatch },
}))

const KYIV = { id: 5, name: 'Kyiv · Podil' }
const LVIV = { id: 8, name: 'Lviv · Sykhiv' }
const ODESA = { id: 13, name: 'Odesa · Peresyp' }
const PRODUCT: AdminProduct = {
  productId: 7,
  name: 'Blue vase',
  kind: 'unique',
  stock: { units: 0, warehouses: 0, warehouseId: null },
  price: 210000,
  categoryId: 2,
  description: 'Thrown by hand, one of a kind.',
  pictureUrl: '/pictures/vase-lg.webp',
  pictureFileId: 16,
  editVersion: 'a'.repeat(64),
}
const cleanup: (() => void)[] = []

/** Report the tracked action's outcome as the modal's runner does. */
async function run(handle: ActionHandle): Promise<boolean> {
  return handle.done.then(
    () => true,
    () => false,
  )
}

function pendingReply() {
  let answer!: (reply: Awaited<ActionHandle['done']>) => void
  const done = new Promise<Awaited<ActionHandle['done']>>((resolve) => {
    answer = resolve
  })
  wire.dispatch.mockReturnValueOnce({
    requestId: 'warehouse-placement-pending',
    loading: createSignal(false),
    done,
  })
  return () => answer({})
}

function openEdit(warehouseId: number | null = null) {
  const scope = scopes.openPage(PAGE_ADMIN_PRODUCT)
  const pageError = createSignal<PageError | null>(null)
  const pageLoading = createSignal(false)
  const currentRoute = createSignal<PageRouteMatch>({
    page: PAGE_ADMIN_PRODUCT,
    params: { productId: String(PRODUCT.productId) },
    admin: true,
  })
  const page = createAdminProductSignals({
    currentRoute,
    pageError,
    pageLoading,
  })
  const writable = createSignal(true)
  const edit = createAdminProductWarehouseEdit({
    productId: PRODUCT.productId,
    card: page.editCard,
    warehouses: page.warehouses,
    writable,
  })

  function placeElsewhere(warehouseId: number | null) {
    const occupied = warehouseId === null ? 0 : 1
    ingest(scope, {
      data: {
        [ADMIN_PRODUCT_DATA]: {
          ...PRODUCT,
          stock: { warehouseId, units: occupied, warehouses: occupied },
        },
      },
    })
  }

  function listWarehouses(...warehouses: (typeof KYIV)[]) {
    ingest(scope, {
      lists: {
        [ADMIN_PRODUCT_WAREHOUSES_LIST]: {
          cleared: true,
          items: warehouses.map((warehouse) => ({
            itemKey: warehouse.id,
            slots: { warehouses: warehouse },
          })),
        },
      },
    })
  }

  placeElsewhere(warehouseId)
  listWarehouses(KYIV, LVIV, ODESA)
  edit.start()
  cleanup.push(edit.dispose)
  expect(edit.open()).toBe(true)

  return {
    edit,
    page,
    pageError,
    pageLoading,
    writable,
    currentRoute,
    placeElsewhere,
    listWarehouses,
  }
}

beforeEach(() => {
  wire.dispatch.mockReset()
  wire.dispatch.mockReturnValue({
    requestId: 'warehouse-placement-saved',
    loading: createSignal(false),
    done: Promise.resolve({}),
  })
})

afterEach(() => {
  for (const dispose of cleanup.splice(0)) dispose()
  scopes.dropPage()
})

describe('the unique product warehouse editor', () => {
  it('opens without a destination and sends the explicit null on first placement', async () => {
    const { edit } = openEdit()
    expect(edit.title.get()).toBe('Put “Blue vase” in a warehouse')
    expect(edit.saveLabel.get()).toBe('Put')
    expect(edit.form.get().warehouseId).toBeNull()
    expect(edit.canSave.get()).toBe(false)
    await edit.save(run)
    expect(wire.dispatch).not.toHaveBeenCalled()

    edit.patchForm({ warehouseId: LVIV.id })
    await edit.save(run)
    expect(wire.dispatch).toHaveBeenCalledExactlyOnceWith(
      PRODUCT_WAREHOUSE_PLACE,
      {
        productId: PRODUCT.productId,
        warehouseId: LVIV.id,
        expectedWarehouseId: null,
      },
    )
  })

  it('prefills the current warehouse and moves from it with write access', async () => {
    const { edit, writable } = openEdit(KYIV.id)
    expect(edit.title.get()).toBe('Move “Blue vase”')
    expect(edit.saveLabel.get()).toBe('Move')
    expect(edit.form.get().warehouseId).toBe(KYIV.id)
    expect(edit.canSave.get()).toBe(false)
    await edit.save(run)

    writable.set(false)
    edit.patchForm({ warehouseId: ODESA.id })
    expect(edit.canSave.get()).toBe(false)
    await edit.save(run)
    expect(edit.form.get().warehouseId).toBe(ODESA.id)
    expect(wire.dispatch).not.toHaveBeenCalled()

    writable.set(true)
    await edit.save(run)
    expect(wire.dispatch).toHaveBeenCalledExactlyOnceWith(
      PRODUCT_WAREHOUSE_PLACE,
      {
        productId: PRODUCT.productId,
        warehouseId: ODESA.id,
        expectedWarehouseId: KYIV.id,
      },
    )
  })

  it('takes untouched updates, then saves against the live warehouse after a conflict choice', async () => {
    const { edit, page, placeElsewhere, listWarehouses } = openEdit()
    placeElsewhere(KYIV.id)
    expect(edit.form.get().warehouseId).toBe(KYIV.id)
    expect(edit.state.get().notice?.kind).toBe('updated')
    expect(edit.title.get()).toBe('Put “Blue vase” in a warehouse')
    expect(edit.saveLabel.get()).toBe('Move')

    edit.patchForm({ warehouseId: ODESA.id })
    placeElsewhere(LVIV.id)
    listWarehouses(KYIV, { ...LVIV, name: 'Lviv · Rynok' }, ODESA)
    expect(page.warehouseName.get()).toBe('Lviv · Rynok')
    expect(edit.noticeText.get()).toBe(
      'Warehouse changed elsewhere to “Lviv · Rynok”.',
    )
    expect(edit.form.get().warehouseId).toBe(ODESA.id)
    expect(edit.canSave.get()).toBe(false)
    await edit.save(run)
    expect(wire.dispatch).not.toHaveBeenCalled()

    edit.takeTheirs()
    expect(edit.form.get().warehouseId).toBe(LVIV.id)
    expect(edit.canSave.get()).toBe(false)
    edit.patchForm({ warehouseId: ODESA.id })
    placeElsewhere(KYIV.id)
    edit.keepMine()
    expect(edit.canSave.get()).toBe(true)
    await edit.save(run)
    expect(wire.dispatch).toHaveBeenCalledExactlyOnceWith(
      PRODUCT_WAREHOUSE_PLACE,
      {
        productId: PRODUCT.productId,
        warehouseId: ODESA.id,
        expectedWarehouseId: KYIV.id,
      },
    )
  })

  it('keeps its draft through missing choices, a temporarily unavailable card and deletion', async () => {
    const { edit, pageError, pageLoading, listWarehouses } = openEdit(KYIV.id)
    edit.patchForm({ warehouseId: LVIV.id })
    listWarehouses()
    expect(edit.form.get().warehouseId).toBe(LVIV.id)
    expect(edit.canSave.get()).toBe(false)
    await edit.save(run)

    listWarehouses(KYIV, ODESA)
    expect(edit.canSave.get()).toBe(false)
    edit.patchForm({ warehouseId: ODESA.id })
    expect(edit.canSave.get()).toBe(true)
    pageLoading.set(true)
    expect(edit.saveLabel.get()).toBe('Move')
    expect(edit.canSave.get()).toBe(false)
    await edit.save(run)

    pageError.set({
      httpCode: 404,
      errorCode: 'not_found',
      title: 'Page Not Found',
      message: 'This product no longer exists.',
    })
    expect(edit.saveLabel.get()).toBe('Deleted')
    expect(edit.opened.get()).toBe(true)
    expect(edit.form.get().warehouseId).toBe(ODESA.id)
    expect(edit.canSave.get()).toBe(false)
    await edit.save(run)
    expect(wire.dispatch).not.toHaveBeenCalled()
  })

  it('keeps the draft on refusal, waits for confirmation, and discards a late reply on dispose', async () => {
    const { edit, page, currentRoute, placeElsewhere } = openEdit(KYIV.id)
    edit.patchForm({ warehouseId: LVIV.id })
    const refusal = new ActionError(
      PRODUCT_WAREHOUSE_PLACE,
      'fail',
      'This warehouse no longer exists',
    )
    wire.dispatch.mockReturnValueOnce({
      requestId: 'warehouse-placement-refused',
      loading: createSignal(false),
      done: Promise.reject(refusal),
    })
    await edit.save(async (handle) => {
      await expect(handle.done).rejects.toBe(refusal)
      return false
    })
    expect(edit.opened.get()).toBe(true)
    expect(edit.form.get().warehouseId).toBe(LVIV.id)
    expect(edit.canSave.get()).toBe(true)

    const answer = pendingReply()
    const saving = edit.save(run)
    expect(edit.canSave.get()).toBe(false)
    await edit.save(run)
    expect(wire.dispatch).toHaveBeenCalledTimes(2)
    expect(page.product.get()?.stock.warehouseId).toBe(KYIV.id)
    placeElsewhere(LVIV.id)
    expect(edit.opened.get()).toBe(true)
    answer()
    await saving
    expect(edit.opened.get()).toBe(false)

    expect(edit.open()).toBe(true)
    edit.patchForm({ warehouseId: ODESA.id })
    const answerLate = pendingReply()
    const pending = edit.save(run)
    currentRoute.set({ ...currentRoute.get(), params: { productId: '21' } })
    edit.dispose()
    answerLate()
    await pending
    expect(edit.opened.get()).toBe(false)
  })
})
