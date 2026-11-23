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

import { type VoucherEdit } from './voucherEdit.js'
import { VoucherFormFields } from './VoucherFormFields.js'

const FORM_ID = 'admin-vouchers-edit-form'
const TITLE = 'Edit voucher'

/**
 * One opening of Edit voucher with its own tracked action.
 *
 * @param props The session over the focused voucher row.
 */
export function VoucherEditModal({ edit }: { edit: VoucherEdit }) {
  const draft = useSignal(edit.form)
  const state = useSignal(edit.state)
  const noticeText = useSignal(edit.noticeText)
  const saving = useSignal(edit.saving)
  const saveAvailable = useSignal(edit.saveAvailable)
  const saveLabel = useSignal(edit.saveLabel)
  const action = useTrackedAction()
  const { locked } = useLookOnly()

  async function submit(): Promise<void> {
    if (locked || !edit.canSave.get()) return
    await edit.save(action.run)
  }

  return (
    <HilosModal
      open
      title={TITLE}
      initialFocus="inner"
      header={<ConflictHeader title={TITLE} conflict={state.conflict} />}
      onClose={() => {
        if (!edit.saving.get()) edit.close()
      }}
      confirmOnClose={state.dirty}
      busy={saving}
      actions={({ requestClose }) => (
        <ConflictActions
          conflict={state.conflict && !saving}
          disableSave={!saveAvailable}
          onAcceptMine={edit.keepMine}
          onAcceptTheirs={edit.takeTheirs}
          cancelButton={
            <button
              type="button"
              className="btn btn-secondary"
              disabled={saving}
              data-id="admin-vouchers-edit-cancel"
              onClick={requestClose}
            >
              Cancel
            </button>
          }
          saveButton={({ disabled }) => (
            <LoadingButton
              className="btn-primary"
              type="submit"
              form={FORM_ID}
              loading={saving}
              disabled={disabled}
              data-id="admin-vouchers-edit-submit"
            >
              {saveLabel}
            </LoadingButton>
          )}
        />
      )}
    >
      <div data-id="admin-vouchers-edit">
        <div data-id="admin-vouchers-edit-error">
          <HilosActionError
            action={action}
            detailsTitle="Couldn't save the voucher"
          />
        </div>
        <form
          id={FORM_ID}
          onSubmit={(event) => {
            event.preventDefault()
            void submit()
          }}
        >
          <VoucherFormFields
            mode="edit"
            draft={draft}
            onChange={edit.patchForm}
          />
          <HilosEditNotice
            kind={state.notice?.kind ?? null}
            text={noticeText}
            dataId="admin-vouchers-edit-notice"
          />
        </form>
      </div>
    </HilosModal>
  )
}
