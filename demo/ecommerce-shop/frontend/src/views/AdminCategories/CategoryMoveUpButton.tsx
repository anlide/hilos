// Action button to move a category one position up in the catalog.
import { LoadingButton, useTrackedAction } from '@hilos/react/base'

import { sendCategoryMoveUp } from './adminCategoriesActions.js'
import type { CategoryRow } from './types/tables/CategoryRow.js'

/** Props for {@link CategoryMoveUpButton}. */
export interface CategoryMoveUpButtonProps {
  /** Category row rendered by the table. */
  readonly row: CategoryRow
}

/** Button moving a category one place up in the catalog. */
export function CategoryMoveUpButton({ row }: CategoryMoveUpButtonProps) {
  const move = useTrackedAction()

  return (
    <LoadingButton
      type="button"
      className="btn btn-sm btn-outline-secondary"
      aria-label={`Move up · ${row.name}`}
      data-id={`admin-categories-move-up-${row.id}`}
      loading={move.busy}
      disabled={row.first}
      onClick={() => void move.run(sendCategoryMoveUp(row.id))}
    >
      <i className="bi bi-arrow-up" aria-hidden="true" />
    </LoadingButton>
  )
}
