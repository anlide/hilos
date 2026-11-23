import { useRef, useState } from 'react'
import {
  HilosActionError,
  HilosModal,
  LoadingButton,
  useLookOnly,
  useTrackedAction,
} from '@hilos/react/base'

import { sendVoucherCreate } from './adminVouchersActions.js'
import { isVoucherDraftComplete, type VoucherDraft } from './voucherEdit.js'
import { VoucherFormFields } from './VoucherFormFields.js'

const FORM_ID = 'admin-vouchers-add-form'

/**
 * One opening of Add voucher with a fresh draft and its tracked action.
 *
 * @param onClose Unmount the dialog on dismissal or after the server confirms.
 */
export function VoucherAddModal({ onClose }: { onClose: () => void }) {
  const [draft, setDraft] = useState<VoucherDraft>({
    code: '',
    discountPercent: '',
    active: true,
  })
  const submitting = useRef(false)
  const action = useTrackedAction()
  const { locked } = useLookOnly()
  const complete = isVoucherDraftComplete(draft)
  const dirty =
    draft.code !== '' || draft.discountPercent !== '' || !draft.active

  async function submit(): Promise<void> {
    if (!complete || locked || action.busy || submitting.current) return

    // Dispatch sends synchronously, before the tracked runner accepts a handle.
    submitting.current = true
    const { code, discountPercent, active } = draft
    try {
      if (await action.run(sendVoucherCreate(code, discountPercent, active))) {
        onClose()
      }
    } finally {
      submitting.current = false
    }
  }

  return (
    <HilosModal
      open
      title="Add voucher"
      initialFocus="inner"
      onClose={onClose}
      confirmOnClose={dirty}
      busy={action.busy}
      actions={({ requestClose }) => (
        <>
          <button
            type="button"
            className="btn btn-secondary"
            disabled={action.busy}
            data-id="admin-vouchers-add-cancel"
            onClick={requestClose}
          >
            Cancel
          </button>
          <LoadingButton
            className="btn-primary"
            type="submit"
            form={FORM_ID}
            loading={action.busy}
            disabled={!complete}
            data-id="admin-vouchers-add-submit"
          >
            Add
          </LoadingButton>
        </>
      )}
    >
      <div data-id="admin-vouchers-add">
        <div data-id="admin-vouchers-add-error">
          <HilosActionError
            action={action}
            detailsTitle="Couldn't add the voucher"
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
            mode="add"
            draft={draft}
            onChange={(patch) =>
              setDraft((current) => ({ ...current, ...patch }))
            }
          />
        </form>
      </div>
    </HilosModal>
  )
}
