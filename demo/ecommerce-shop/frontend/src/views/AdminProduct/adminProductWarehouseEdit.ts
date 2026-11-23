import {
  computedSignal,
  createHilosRowEdit,
  createSignal,
  hilosSignalRowEditSource,
  subscribeSignal,
  type HilosTrackedRowEdit,
  type ReadonlySignal,
} from '@hilos/core/base'

import { type AdminProduct } from '../../types/AdminProduct.js'
import { sendProductWarehousePlace } from './adminProductActions.js'
import { type ProductEditCard } from './adminProductEdit.js'

interface WarehouseDraft {
  readonly warehouseId: number | null
}

interface ProductWarehouseEditOptions {
  readonly productId: number
  readonly card: ReadonlySignal<ProductEditCard>
  readonly warehouses: ReadonlySignal<
    readonly { value: number; label: string }[]
  >
  readonly writable: ReadonlySignal<boolean>
}

/** The placement and move editor for the unique piece in the Overview address. */
export interface AdminProductWarehouseEdit extends HilosTrackedRowEdit<
  AdminProduct,
  WarehouseDraft
> {
  readonly canOpen: ReadonlySignal<boolean>
  readonly title: ReadonlySignal<string>
}

/**
 * Follow the piece's live warehouse inside one opening of the dialog.
 *
 * @param options The addressed product, available destinations, and write access.
 */
export function createAdminProductWarehouseEdit({
  productId,
  card,
  warehouses,
  writable,
}: ProductWarehouseEditOptions): AdminProductWarehouseEdit {
  const current = computedSignal(() => {
    const row = card.get()
    return row?.productId === productId && row.kind === 'unique'
      ? row
      : undefined
  })
  // Keep the merge anchored while the subscription owes a fresh card.
  const lastCard = createSignal<AdminProduct | undefined>(undefined)
  const source = hilosSignalRowEditSource(
    computedSignal(() =>
      card.get() === undefined ? lastCard.get() : current.get(),
    ),
  )
  let stopCard: (() => void) | undefined

  const edit = createHilosRowEdit<AdminProduct, WarehouseDraft>(source, {
    initial: { warehouseId: null },
    fields: (row) => ({ warehouseId: row.stock.warehouseId }),
    creating: (row) => row.stock.warehouseId === null,
    valid: ({ warehouseId }) =>
      warehouses.get().some(({ value }) => value === warehouseId),
    notice: {
      conflict: ({ fields }) => {
        const incoming = warehouses
          .get()
          .find(({ value }) => value === fields.warehouseId.incoming)
        return incoming === undefined
          ? 'Warehouse changed elsewhere.'
          : `Warehouse changed elsewhere to “${incoming.label}”.`
      },
    },
  })

  const canOpen = computedSignal(() => current.get() !== undefined)
  const title = computedSignal(() => {
    const opening = edit.row.get()
    if (opening === null) return ''
    return opening.stock.warehouseId === null
      ? `Put “${opening.name}” in a warehouse`
      : `Move “${opening.name}”`
  })
  const saveLabel = computedSignal(() => {
    if (edit.state.get().gone) return edit.saveLabel.get()
    return source.live.get()?.stock.warehouseId === null ? 'Put' : 'Move'
  })
  const saveAvailable = computedSignal(
    () => canOpen.get() && writable.get() && edit.saveAvailable.get(),
  )
  const canSave = computedSignal(
    () => saveAvailable.get() && !edit.saving.get(),
  )

  return {
    ...edit,
    canOpen,
    title,
    saveLabel,
    saveAvailable,
    canSave,
    start() {
      stopCard?.()
      lastCard.set(current.get())
      stopCard = subscribeSignal(card, (row) => {
        if (row !== undefined) lastCard.set(current.get())
      })
      edit.start()
    },
    dispose() {
      stopCard?.()
      stopCard = undefined
      edit.dispose()
    },
    open() {
      return canOpen.get() && !edit.opened.get() && edit.open()
    },
    async save(run) {
      if (!canSave.get()) return
      await edit.save(async ({ warehouseId }) => {
        // The core passes the opening row. The verdict read the live one,
        // whose warehouse includes any change the editor has accepted.
        const row = current.get()
        if (row === undefined || warehouseId === null) return false
        return run(
          sendProductWarehousePlace({
            productId: row.productId,
            warehouseId,
            expectedWarehouseId: row.stock.warehouseId,
          }),
        )
      })
    },
  }
}
