import {
  HilosActionError,
  HilosModal,
  LoadingButton,
  useLookOnly,
  useTrackedAction,
} from '@hilos/react/base'
import { useId, useRef, useState } from 'react'

import { sendCartApplyVoucher, type CartOwnerKind } from './cartActions.js'

interface CartVoucherApplyModalProps {
  readonly readOwnerKind: () => CartOwnerKind | null
  readonly onClose: () => void
}

/**
 * Ask for a voucher code for one opening of the cart's apply dialog.
 *
 * @param props Read the fresh owner kind and close this opening.
 */
export function CartVoucherApplyModal({
  readOwnerKind,
  onClose,
}: CartVoucherApplyModalProps) {
  const formId = useId()
  const codeId = useId()
  const helpId = useId()
  const [code, setCode] = useState('')
  const submitting = useRef(false)
  const action = useTrackedAction({ toast: false })
  const { locked } = useLookOnly()
  const complete = code.trim() !== ''

  async function submit(): Promise<void> {
    const ownerKind = readOwnerKind()
    if (!complete || ownerKind === null || locked || submitting.current) return

    // Dispatch sends synchronously, before the tracked runner accepts a handle.
    submitting.current = true
    try {
      if (await action.run(sendCartApplyVoucher(code, ownerKind))) onClose()
    } finally {
      submitting.current = false
    }
  }

  return (
    <HilosModal
      open
      title="Apply voucher"
      initialFocus="inner"
      busy={action.busy}
      confirmOnClose={code !== ''}
      onClose={() => {
        if (!submitting.current) onClose()
      }}
      actions={({ requestClose }) => (
        <>
          <button
            type="button"
            className="btn btn-secondary"
            disabled={action.busy}
            onClick={requestClose}
          >
            Cancel
          </button>
          <LoadingButton
            className="btn-primary"
            type="submit"
            form={formId}
            loading={action.busy}
            disabled={!complete || readOwnerKind() === null}
            data-id="cart-voucher-apply-submit"
          >
            Apply
          </LoadingButton>
        </>
      )}
    >
      <div data-id="cart-voucher-apply">
        <div data-id="cart-voucher-apply-error">
          <HilosActionError
            action={action}
            detailsTitle="Couldn't apply the voucher"
          />
        </div>
        <form
          id={formId}
          data-id="cart-voucher-apply-form"
          onSubmit={(event) => {
            event.preventDefault()
            void submit()
          }}
        >
          <label className="form-label" htmlFor={codeId}>
            Voucher code
          </label>
          <input
            id={codeId}
            type="text"
            className="form-control"
            aria-describedby={helpId}
            required
            data-autofocus
            data-id="cart-voucher-apply-code"
            value={code}
            onChange={(event) => setCode(event.currentTarget.value)}
          />
          <div className="form-text" id={helpId}>
            Codes are not case-sensitive.
          </div>
          <p className="small text-body-secondary mt-3 mb-0">
            One voucher per order. A new one replaces the one already applied.
          </p>
        </form>
      </div>
    </HilosModal>
  )
}
