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
  isWarehouseDraftComplete,
  isWarehouseDraftDirty,
  type WarehouseDraft,
} from './warehouseDraft.js'
import { WarehouseFormFields } from './WarehouseFormFields.js'

const FORM_ID = 'admin-warehouses-add-form'

/**
 * One opening of the Add warehouse dialog; it closes itself on the backend's
 * success.
 *
 * @param onClose Close the dialog — on Cancel, on dismissal and after success.
 */
export function WarehouseAddModal({ onClose }: { onClose: () => void }) {
  const [draft, setDraft] = useState<WarehouseDraft>(EMPTY_WAREHOUSE_DRAFT)
  const action = useTrackedAction()
  const { locked } = useLookOnly()

  async function submit(): Promise<void> {
    if (locked || !isWarehouseDraftComplete(draft)) return
    if (await action.run(sendWarehouseCreate(draft))) {
      onClose()
    }
  }

  return (
    <HilosModal
      open
      title="Add warehouse"
      initialFocus="inner"
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
            disabled={!isWarehouseDraftComplete(draft)}
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
          <WarehouseFormFields
            idPrefix="admin-warehouses-add"
            draft={draft}
            onChange={(patch) => setDraft({ ...draft, ...patch })}
          />
        </form>
      </div>
    </HilosModal>
  )
}
