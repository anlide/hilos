import {
  createHilosRowEdit,
  hilosTableRowEditSource,
  type HilosTrackedRowEdit,
  type ReadonlySignal,
  type TableViewportController,
} from '@hilos/core/base'

import { actions } from '../../bootstrap/connection.js'
import { sendProductStockSetUnits } from './adminProductStockActions.js'
import { type ProductStockRow } from './types/tables/ProductStockRow.js'

interface StockDraft {
  readonly units: string
}

/** One Set stock window over a warehouse row for the current product. */
export type ProductStockEdit = HilosTrackedRowEdit<
  ProductStockRow,
  StockDraft
> & {
  /** The focused warehouse as it stands now. */
  readonly live: ReadonlySignal<ProductStockRow | undefined>
}

/**
 * Compare saved counts by value while retaining the raw text in the form.
 * A nonmatching draft, including invalid input, still goes to the server.
 *
 * @param draft The units as entered.
 */
function comparableUnits(draft: string): string {
  const match = /^[ \t\r\n\v\0]*(-?[0-9]+)[ \t\r\n\v\0]*$/.exec(draft)
  return match ? String(Number(match[1])) : draft
}

/**
 * Create the edit session that holds the warehouse in the stock table's focus.
 *
 * @param controller The current product's stock table.
 * @param productId The product addressed by the table.
 */
export function createProductStockEdit(
  controller: TableViewportController<ProductStockRow>,
  productId: number,
): ProductStockEdit {
  const source = hilosTableRowEditSource(controller, (row) =>
    String(row.warehouseId),
  )
  const edit = createHilosRowEdit<ProductStockRow, StockDraft>(source, {
    initial: { units: '' },
    fields: (row) => ({ units: String(row.units) }),
    draft: ({ units }) => ({ units: comparableUnits(units) }),
    valid: ({ units }) => units !== '',
    notice: {
      conflict: (state) =>
        `Units changed elsewhere to ${state.fields.units.incoming}.`,
    },
  })

  return {
    ...edit,
    live: source.live,
    keepMine: () => {
      if (!edit.saving.get()) edit.keepMine()
    },
    takeTheirs: () => {
      if (!edit.saving.get()) edit.takeTheirs()
    },
    save: (run) =>
      edit.save(() => {
        // Use the live count checked by the session, including after Keep mine.
        const live = source.live.get()
        if (live === undefined) return Promise.resolve(false)

        return run(
          sendProductStockSetUnits(
            actions,
            productId,
            live.warehouseId,
            edit.form.get().units,
            live.units,
          ),
        )
      }),
  }
}
