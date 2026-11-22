// The Edit warehouse dialog: the Add fields filled with the row the pencil
// opened, over the core row-edit session — the conflict with Keep mine / Take
// theirs, "Updated just now" and "Deleted" are the session's, and the window
// keeps no edit store of its own. The driver toasts the outcome; a refusal is
// also drawn above the fields, so the dialog stays open with what was typed.
// The parent mounts one window per opening, so an earlier refusal never carries
// into the next one. Bootstrap classes only (styling-rules.md).
import {
  ConflictActions,
  ConflictHeader,
  HilosActionError,
  HilosEditNotice,
  HilosModal,
  LoadingButton,
  useLookOnly,
  useSignal,
  useTrackedAction,
} from '@hilos/react/base'

import { type WarehouseEdit } from './warehouseEdit.js'
import { WarehouseFormFields } from './WarehouseFormFields.js'

const FORM_ID = 'admin-warehouses-edit-form'

/**
 * One opening of the Edit warehouse dialog; the session closes it on the
 * backend's success.
 *
 * @param edit The session over the warehouses table.
 */
export function WarehouseEditModal({ edit }: { edit: WarehouseEdit }) {
  const opened = useSignal(edit.opened)
  const form = useSignal(edit.form)
  const state = useSignal(edit.state)
  const noticeText = useSignal(edit.noticeText)
  const saving = useSignal(edit.saving)
  const saveAvailable = useSignal(edit.saveAvailable)
  const saveLabel = useSignal(edit.saveLabel)
  const action = useTrackedAction()
  const { locked } = useLookOnly()

  // Save and Enter go through the session's one door: it refuses, closes an
  // unchanged draft, or dispatches the tracked action and closes on its
  // `::success` reply; a failure stays open with what was typed.
  async function submit(): Promise<void> {
    if (locked || !edit.canSave.get()) return
    await edit.save(action.run)
  }

  return (
    <HilosModal
      open={opened}
      initialFocus="inner"
      onClose={edit.close}
      confirmOnClose={state.dirty}
      busy={saving}
      header={
        <ConflictHeader title="Edit warehouse" conflict={state.conflict} />
      }
      actions={({ requestClose }) => (
        <ConflictActions
          conflict={state.conflict}
          disableSave={!saveAvailable}
          saveLabel={saveLabel}
          onSave={() => void submit()}
          onAcceptMine={edit.keepMine}
          onAcceptTheirs={edit.takeTheirs}
          cancelButton={
            <button
              type="button"
              className="btn btn-secondary"
              disabled={saving}
              data-id="admin-warehouses-edit-cancel"
              onClick={requestClose}
            >
              Cancel
            </button>
          }
          // A submit button of the form: with two fields, Enter submits only
          // through one, and the click takes the same door.
          saveButton={({ disabled }) => (
            <LoadingButton
              className="btn-primary"
              type="submit"
              form={FORM_ID}
              loading={action.busy}
              disabled={disabled}
              data-id="admin-warehouses-edit-submit"
            >
              {saveLabel}
            </LoadingButton>
          )}
        />
      )}
    >
      <div data-id="admin-warehouses-edit">
        <div data-id="admin-warehouses-edit-error">
          <HilosActionError
            action={action}
            detailsTitle="Couldn't save the warehouse"
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
            idPrefix="admin-warehouses-edit"
            draft={form}
            onChange={edit.patchForm}
          />
          <HilosEditNotice
            kind={state.notice?.kind ?? null}
            text={noticeText}
            dataId="admin-warehouses-edit-notice"
          />
        </form>
      </div>
    </HilosModal>
  )
}
