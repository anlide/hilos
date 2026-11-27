import { useId } from 'react'
import { HilosActionReason } from '@hilos/react/base'

import { type WarehouseRow } from './types/tables/WarehouseRow.js'
import { warehouseRowDelete } from './warehouseDelete.js'

/** Props for {@link WarehouseRowActions}. */
export interface WarehouseRowActionsProps {
  readonly row: WarehouseRow
  readonly onEdit: () => void
  readonly onDelete: () => void
}

/** The actions cell of a warehouse table row: edit pencil and delete trash can. */
export function WarehouseRowActions({
  row,
  onEdit,
  onDelete,
}: WarehouseRowActionsProps) {
  const verdict = warehouseRowDelete(row)
  const reason = verdict.disabled ? verdict.reason : null
  const reasonId = useId()

  return (
    <span className="d-inline-flex gap-1">
      <button
        type="button"
        className="btn btn-sm btn-outline-secondary"
        aria-label={`Edit · ${row.name}`}
        data-id="admin-warehouses-edit-open"
        onClick={onEdit}
      >
        <i className="bi bi-pencil" aria-hidden="true" />
      </button>
      <HilosActionReason reason={reason} />
      <button
        type="button"
        className={`btn btn-sm ${verdict.disabled ? 'btn-outline-secondary' : 'btn-outline-danger'}`}
        disabled={verdict.disabled}
        aria-label={`Delete · ${row.name}`}
        aria-describedby={reason ? reasonId : undefined}
        data-id="admin-warehouses-delete-open"
        onClick={onDelete}
      >
        <i className="bi bi-trash" aria-hidden="true" />
      </button>
      {reason !== null ? (
        <span id={reasonId} className="visually-hidden">
          {reason}
        </span>
      ) : null}
    </span>
  )
}
