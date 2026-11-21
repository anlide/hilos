// The Add warehouse dialog: a name and an address, sent as one tracked action.
// The driver toasts the outcome; a refusal is also drawn above the fields, so
// the dialog stays open with what was typed. The parent mounts one window per
// opening, so an earlier refusal never carries into the next one.
// Bootstrap classes only (styling-rules.md).
import { useState } from 'react'
import {
  HilosActionError,
  HilosModal,
  LoadingButton,
  useLookOnly,
  useTrackedAction,
} from '@hilos/react/base'

import { sendWarehouseCreate } from './adminWarehousesActions.js'
import {
  EMPTY_WAREHOUSE_DRAFT,
  canAddWarehouse,
  isWarehouseDraftDirty,
  type WarehouseAddDraft,
} from './warehouseAddDraft.js'

const FORM_ID = 'admin-warehouses-add-form'
const NAME_ID = 'admin-warehouses-add-name'
const NAME_HINT_ID = 'admin-warehouses-add-name-hint'
const ADDRESS_ID = 'admin-warehouses-add-address'

/**
 * One opening of the Add warehouse dialog; it closes itself on the backend's
 * success.
 *
 * @param onClose Close the dialog — on Cancel, on dismissal and after success.
 */
export function WarehouseAddModal({ onClose }: { onClose: () => void }) {
  const [draft, setDraft] = useState<WarehouseAddDraft>(EMPTY_WAREHOUSE_DRAFT)
  const action = useTrackedAction()
  const { locked } = useLookOnly()

  async function submit(): Promise<void> {
    if (locked || !canAddWarehouse(draft)) return
    if (await action.run(sendWarehouseCreate(draft))) {
      onClose()
    }
  }

  return (
    <HilosModal
      open
      title="Add warehouse"
      onClose={onClose}
      confirmOnClose={isWarehouseDraftDirty(draft)}
      busy={action.busy}
      actions={({ requestClose }) => (
        <>
          <button
            type="button"
            className="btn btn-secondary"
            disabled={action.busy}
            data-id="admin-warehouses-add-cancel"
            onClick={requestClose}
          >
            Cancel
          </button>
          <LoadingButton
            className="btn-primary"
            type="submit"
            form={FORM_ID}
            loading={action.busy}
            disabled={!canAddWarehouse(draft)}
            data-id="admin-warehouses-add-submit"
          >
            Add
          </LoadingButton>
        </>
      )}
    >
      <div data-id="admin-warehouses-add">
        <div data-id="admin-warehouses-add-error">
          <HilosActionError
            action={action}
            detailsTitle="Couldn't add the warehouse"
          />
        </div>
        <form
          id={FORM_ID}
          onSubmit={(event) => {
            event.preventDefault()
            void submit()
          }}
        >
          <div className="mb-3">
            <label className="form-label" htmlFor={NAME_ID}>
              Name
            </label>
            <input
              id={NAME_ID}
              type="text"
              className="form-control"
              value={draft.name}
              aria-describedby={NAME_HINT_ID}
              data-id="admin-warehouses-add-name"
              data-autofocus
              onChange={(event) =>
                setDraft({ ...draft, name: event.target.value })
              }
            />
            <div id={NAME_HINT_ID} className="form-text">
              Buyers never see it; admins pick it when setting stock.
            </div>
          </div>
          <div>
            <label className="form-label" htmlFor={ADDRESS_ID}>
              Address
            </label>
            <input
              id={ADDRESS_ID}
              type="text"
              className="form-control"
              value={draft.address}
              data-id="admin-warehouses-add-address"
              onChange={(event) =>
                setDraft({ ...draft, address: event.target.value })
              }
            />
          </div>
        </form>
      </div>
    </HilosModal>
  )
}
