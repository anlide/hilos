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

import { sendWarehouseDelete } from './adminWarehousesActions.js'
import { type WarehouseRow } from './types/tables/WarehouseRow.js'
import { WAREHOUSE_STOCK_REASON } from './warehouseDelete.js'

const DELETE_STOCK_REASON_ID = 'admin-warehouses-delete-stock-reason'
const DELETE_GONE_ID = 'admin-warehouses-delete-gone'

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

/** Reserve the stock explanation's space and announce it in the active modal. */
function WarehouseStockReason({
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
          {WAREHOUSE_STOCK_REASON}
        </p>
      </div>
      <span className="visually-hidden" role="status" aria-live="polite">
        {announce ? WAREHOUSE_STOCK_REASON : ''}
      </span>
    </>
  )
}

/** Props for {@link WarehouseDeleteModal}. */
export interface WarehouseDeleteModalProps {
  readonly source: HilosRowEditSource<WarehouseRow>
  readonly initialRow: WarehouseRow
  readonly onClose: () => void
}

/**
 * One opening of Delete warehouse over the focused row of the warehouses table.
 *
 * @param props Modal source, initial row snapshot, and close callback.
 */
export function WarehouseDeleteModal({
  source,
  initialRow,
  onClose,
}: WarehouseDeleteModalProps) {
  const live = useSignal(source.live)
  const action = useTrackedAction()
  const { locked } = useLookOnly()
  const deleteCancel = useRef<HTMLButtonElement>(null)
  const deleteConfirm = useRef<HTMLButtonElement>(null)
  const sending = useRef(false)

  const empty = live?.productCount === 0
  const hasStock = live !== undefined && live.productCount > 0
  const gone = live === undefined && !action.busy
  const canConfirm = live !== undefined && empty
  const name = live?.name ?? initialRow.name

  // ARIA-disable the submit button so native focus survives the stock change.
  // Move it to Cancel before Tab can leave the top dialog from the old target.
  useLayoutEffect(() => {
    const cancel = deleteCancel.current
    if (
      !canConfirm &&
      !action.busy &&
      cancel &&
      cancel.ownerDocument.activeElement === deleteConfirm.current
    ) {
      cancel.focus({ preventScroll: true })
    }
  }, [canConfirm, action.busy])

  async function submitDelete(): Promise<void> {
    if (locked || sending.current || action.busy) return
    const row = source.live.get()
    if (!row || row.productCount !== 0) return

    sending.current = true
    parkClosingControl(deleteCancel.current)
    try {
      const deleted = await action.run(sendWarehouseDelete(row.id))
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
      title="Delete warehouse?"
      busy={action.busy}
      initialFocus="dialog"
      onClose={onClose}
      actions={({ requestClose }) => (
        <>
          <button
            ref={deleteCancel}
            type="button"
            className="btn btn-secondary"
            disabled={action.busy}
            data-id="admin-warehouses-delete-cancel"
            onClick={requestClose}
          >
            Cancel
          </button>
          <LoadingButton
            className={`btn-danger${!canConfirm && !action.busy ? ' opacity-50' : ''}`}
            loading={action.busy}
            aria-disabled={!canConfirm || undefined}
            tabIndex={canConfirm ? undefined : -1}
            aria-describedby={
              hasStock
                ? DELETE_STOCK_REASON_ID
                : gone
                  ? DELETE_GONE_ID
                  : undefined
            }
            data-id="admin-warehouses-delete-confirm"
            onFocus={(event) => {
              deleteConfirm.current = event.currentTarget
            }}
            onClick={() => void submitDelete()}
          >
            Delete warehouse
          </LoadingButton>
        </>
      )}
    >
      <div data-id="admin-warehouses-delete">
        <HilosActionError
          action={action}
          detailsTitle="Couldn't delete the warehouse"
        />
        <p className="mb-0">
          Permanently delete <strong className="text-break">{name}</strong>?
        </p>
        <WarehouseStockReason id={DELETE_STOCK_REASON_ID} visible={hasStock} />
        {gone ? (
          <p
            id={DELETE_GONE_ID}
            data-id="admin-warehouses-delete-gone"
            className="mb-0 mt-2 text-body-secondary"
          >
            This warehouse was already deleted elsewhere.
          </p>
        ) : null}
      </div>
    </HilosModal>
  )
}
