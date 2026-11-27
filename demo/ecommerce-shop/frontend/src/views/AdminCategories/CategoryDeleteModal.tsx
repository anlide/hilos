import { useLayoutEffect, useRef } from 'react'
import { type HilosRowEditSource } from '@hilos/core/base'
import {
  HilosActionError,
  HilosModal,
  LoadingButton,
  useLookOnly,
  useSignal,
  useTrackedAction,
} from '@hilos/react/base'

import { sendCategoryDelete } from './adminCategoriesActions.js'
import { CATEGORY_PRODUCTS_REASON } from './categoryDelete.js'
import { type CategoryRow } from './types/tables/CategoryRow.js'

const DELETE_PRODUCTS_REASON_ID = 'admin-categories-delete-products-reason'
const DELETE_GONE_ID = 'admin-categories-delete-gone'

/**
 * Park focus before this layer's Cancel or Close becomes natively disabled.
 *
 * @param cancel The active layer's Cancel button.
 */
function parkClosingControl(cancel: HTMLButtonElement | null): void {
  if (!cancel) return
  const dialog = cancel.closest<HTMLElement>('[role="dialog"]')
  const focused = cancel.ownerDocument.activeElement
  if (
    dialog &&
    focused?.closest('[role="dialog"]') === dialog &&
    (focused === cancel || focused.matches('[data-id="modal-close"]'))
  ) {
    dialog.focus({ preventScroll: true })
  }
}

/** Reserve the products explanation's space and announce it in the active modal. */
function CategoryProductsReason({
  id,
  visible,
  announce = visible,
}: {
  id: string
  visible: boolean
  announce?: boolean
}) {
  return (
    <>
      <div className={`mt-2 mb-0${visible ? '' : ' bg-body-tertiary rounded'}`}>
        <p
          id={id}
          className={`mb-0 small text-body-secondary${visible ? '' : ' invisible'}`}
          aria-hidden={!visible}
        >
          {CATEGORY_PRODUCTS_REASON}
        </p>
      </div>
      <span className="visually-hidden" role="status" aria-live="polite">
        {announce ? CATEGORY_PRODUCTS_REASON : ''}
      </span>
    </>
  )
}

/** Props for {@link CategoryDeleteModal}. */
export interface CategoryDeleteModalProps {
  readonly source: HilosRowEditSource<CategoryRow>
  readonly initialRow: CategoryRow
  readonly onClose: () => void
}

/**
 * One opening of Delete category over the focused row of the categories table.
 *
 * @param props Modal source, initial row snapshot, and close callback.
 */
export function CategoryDeleteModal({
  source,
  initialRow,
  onClose,
}: CategoryDeleteModalProps) {
  const live = useSignal(source.live)
  const deletion = useTrackedAction()
  const { locked } = useLookOnly()
  const deleteCancel = useRef<HTMLButtonElement>(null)
  const deleteConfirm = useRef<HTMLButtonElement>(null)
  const sending = useRef(false)

  const empty = live?.productCount === 0
  const hasProducts = live !== undefined && live.productCount > 0
  const gone = live === undefined && !deletion.busy
  const canConfirm = live !== undefined && empty
  const name = live?.name ?? initialRow.name

  // ARIA-disable the submit button so native focus survives the products count change.
  // Move it to Cancel before Tab can leave the top dialog from the old target.
  useLayoutEffect(() => {
    const cancel = deleteCancel.current
    if (
      !canConfirm &&
      !deletion.busy &&
      cancel &&
      cancel.ownerDocument.activeElement === deleteConfirm.current
    ) {
      cancel.focus({ preventScroll: true })
    }
  }, [canConfirm, deletion.busy])

  async function submitDelete(): Promise<void> {
    if (locked || sending.current || deletion.busy) return
    const row = source.live.get()
    if (!row || row.productCount !== 0) return

    sending.current = true
    parkClosingControl(deleteCancel.current)
    try {
      const deleted = await deletion.run(sendCategoryDelete(row.id))
      if (deleted) {
        onClose()
      }
    } finally {
      sending.current = false
    }
  }

  return (
    <HilosModal
      open
      title="Delete category?"
      busy={deletion.busy}
      initialFocus="dialog"
      onClose={onClose}
      actions={({ requestClose }) => (
        <>
          <button
            ref={deleteCancel}
            type="button"
            className="btn btn-secondary"
            disabled={deletion.busy}
            data-id="admin-categories-delete-cancel"
            onClick={requestClose}
          >
            Cancel
          </button>
          <LoadingButton
            className={`btn-danger${!canConfirm && !deletion.busy ? ' opacity-50' : ''}`}
            loading={deletion.busy}
            aria-disabled={!canConfirm || undefined}
            tabIndex={canConfirm ? undefined : -1}
            aria-describedby={
              hasProducts
                ? DELETE_PRODUCTS_REASON_ID
                : gone
                  ? DELETE_GONE_ID
                  : undefined
            }
            data-id="admin-categories-delete-confirm"
            onFocus={(event) => {
              deleteConfirm.current = event.currentTarget
            }}
            onClick={() => void submitDelete()}
          >
            Delete category
          </LoadingButton>
        </>
      )}
    >
      <div data-id="admin-categories-delete">
        <HilosActionError
          action={deletion}
          detailsTitle="Couldn't delete the category"
        />
        <p className="mb-0">
          Permanently delete <strong className="text-break">{name}</strong>?
        </p>
        <CategoryProductsReason
          id={DELETE_PRODUCTS_REASON_ID}
          visible={hasProducts}
        />
        {gone ? (
          <p
            id={DELETE_GONE_ID}
            data-id="admin-categories-delete-gone"
            className="mb-0 mt-2 text-body-secondary"
          >
            This category was already deleted elsewhere.
          </p>
        ) : null}
      </div>
    </HilosModal>
  )
}
