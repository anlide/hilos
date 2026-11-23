import { useRef, useState } from 'react'
import {
  HilosActionError,
  HilosModal,
  LoadingButton,
  useLookOnly,
  useTrackedAction,
} from '@hilos/react/base'

import { sendVoucherCreate } from './adminVouchersActions.js'

const FORM_ID = 'admin-vouchers-add-form'

/**
 * One opening of Add voucher with a fresh draft and its tracked action.
 *
 * @param onClose Unmount the dialog on dismissal or after the server confirms.
 */
export function VoucherAddModal({ onClose }: { onClose: () => void }) {
  const [code, setCode] = useState('')
  const [discountPercent, setDiscountPercent] = useState('')
  const [active, setActive] = useState(true)
  const submitting = useRef(false)
  const action = useTrackedAction()
  const { locked } = useLookOnly()
  const complete = code !== '' && discountPercent !== ''
  const dirty = code !== '' || discountPercent !== '' || !active

  async function submit(): Promise<void> {
    if (!complete || locked || action.busy || submitting.current) return

    // Dispatch sends synchronously, before the tracked runner accepts a handle.
    submitting.current = true
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
          <div className="mb-3">
            <label className="form-label" htmlFor="admin-vouchers-add-code">
              Code
            </label>
            <input
              id="admin-vouchers-add-code"
              type="text"
              className="form-control"
              value={code}
              aria-describedby="admin-vouchers-add-code-hint"
              data-id="admin-vouchers-add-code"
              data-autofocus
              onChange={(event) => setCode(event.target.value)}
            />
            <div id="admin-vouchers-add-code-hint" className="form-text">
              Buyers type it in the cart. Letters and digits; case does not
              matter.
            </div>
          </div>
          <div className="mb-3">
            <label
              className="form-label"
              htmlFor="admin-vouchers-add-discount-percent"
            >
              Discount, %
            </label>
            <input
              id="admin-vouchers-add-discount-percent"
              type="text"
              inputMode="numeric"
              className="form-control"
              value={discountPercent}
              data-id="admin-vouchers-add-discount-percent"
              onChange={(event) => setDiscountPercent(event.target.value)}
            />
          </div>
          <div className="form-check form-switch">
            <input
              id="admin-vouchers-add-active"
              type="checkbox"
              role="switch"
              className="form-check-input"
              checked={active}
              aria-describedby="admin-vouchers-add-active-hint"
              data-id="admin-vouchers-add-active"
              onChange={(event) => setActive(event.target.checked)}
            />
            <label
              className="form-check-label"
              htmlFor="admin-vouchers-add-active"
            >
              Active
            </label>
            <div id="admin-vouchers-add-active-hint" className="form-text">
              A switched-off code is refused in the cart.
            </div>
          </div>
        </form>
      </div>
    </HilosModal>
  )
}
