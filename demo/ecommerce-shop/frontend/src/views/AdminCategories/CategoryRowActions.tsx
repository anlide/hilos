import { useId } from 'react'
import { HilosActionReason } from '@hilos/react/base'

import { categoryRowDelete } from './categoryDelete.js'
import { CategoryMoveUpButton } from './CategoryMoveUpButton.js'
import { type CategoryRow } from './types/tables/CategoryRow.js'

/** Props for {@link CategoryRowActions}. */
export interface CategoryRowActionsProps {
  readonly row: CategoryRow
  readonly onEdit: () => void
  readonly onDelete: () => void
}

/** The actions cell of a category table row: move up, edit pencil and delete trash can. */
export function CategoryRowActions({
  row,
  onEdit,
  onDelete,
}: CategoryRowActionsProps) {
  const verdict = categoryRowDelete(row)
  const reason = verdict.disabled ? verdict.reason : null
  const reasonId = useId()

  return (
    <div className="d-inline-flex gap-2">
      <span className="d-none d-md-inline-flex">
        <CategoryMoveUpButton row={row} />
      </span>
      <button
        type="button"
        className="btn btn-sm btn-outline-secondary"
        aria-label={`Edit · ${row.name}`}
        data-id="admin-categories-edit-open"
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
        data-id="admin-categories-delete-open"
        onClick={onDelete}
      >
        <i className="bi bi-trash" aria-hidden="true" />
      </button>
      {reason !== null ? (
        <span id={reasonId} className="visually-hidden">
          {reason}
        </span>
      ) : null}
    </div>
  )
}
