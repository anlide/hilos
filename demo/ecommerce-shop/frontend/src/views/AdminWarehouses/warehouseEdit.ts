// The Edit warehouse session over the warehouses table: the core row-edit
// session on the row the pencil opened, held in the table's focus while the
// window is open, so the server follows the row for the tab. The conflict, the
// "Updated just now" and the "Deleted" come from the core merge; the window
// names only its own fields. No React: the view mirrors these signals.
import {
  createHilosRowEdit,
  hilosTableRowEditSource,
  type HilosTrackedRowEdit,
  type ReadonlySignal,
  type TableViewportController,
} from '@hilos/core/base'

import { sendWarehouseUpdate } from './adminWarehousesActions.js'
import { type WarehouseRow } from './types/tables/WarehouseRow.js'
import {
  EMPTY_WAREHOUSE_DRAFT,
  isWarehouseDraftComplete,
  type WarehouseDraft,
} from './warehouseDraft.js'

/** One Edit warehouse window over one row of the warehouses table. */
export type WarehouseEdit = HilosTrackedRowEdit<
  WarehouseRow,
  WarehouseDraft
> & {
  /** The focused row as it stands now, including stock. */
  readonly live: ReadonlySignal<WarehouseRow | undefined>
}

// The words the conflict line names a field by, as the form labels it.
const FIELD_LABELS: Readonly<Record<keyof WarehouseDraft, string>> = {
  name: 'Name',
  address: 'Address',
}

/**
 * The Edit warehouse session over the warehouses table. Save sends the draft
 * with the values the live row holds as it is pressed.
 *
 * @param controller The warehouses table whose row the window edits.
 */
export function createWarehouseEdit(
  controller: TableViewportController<WarehouseRow>,
): WarehouseEdit {
  const source = hilosTableRowEditSource(controller, (row) => String(row.id))
  const edit = createHilosRowEdit<WarehouseRow, WarehouseDraft>(source, {
    initial: EMPTY_WAREHOUSE_DRAFT,
    fields: (row) => ({ name: row.name, address: row.address }),
    valid: isWarehouseDraftComplete,
    notice: {
      conflict: (state) =>
        (state.notice?.fields ?? [])
          .map(
            (field) =>
              `${FIELD_LABELS[field]} changed elsewhere to "${state.fields[field].incoming}".`,
          )
          .join(' '),
    },
  })

  return {
    ...edit,
    live: source.live,
    save: (run) =>
      edit.save((draft, row) => {
        // The session's row argument is the opening row. Concurrency uses the
        // live row on which it just checked the verdict, including any choice.
        const live = source.live.get() ?? row

        return run(
          sendWarehouseUpdate(row.id, draft, {
            name: live.name,
            address: live.address,
          }),
        )
      }),
  }
}
