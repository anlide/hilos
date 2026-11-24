import { LoadingButton, useTrackedAction } from '@hilos/react/base'
import { useRef } from 'react'

import { sendCartRemoveLine, type CartOwnerKind } from './cartActions.js'

interface CartRemoveButtonProps {
  readonly lineId: number
  readonly ownerKind: CartOwnerKind
  readonly name: string
}

/** Remove one saved line and let the live cart answer redraw the page. */
export function CartRemoveButton({
  lineId,
  ownerKind,
  name,
}: CartRemoveButtonProps) {
  const remove = useTrackedAction()
  const submitting = useRef(false)

  async function submit(): Promise<void> {
    if (submitting.current) return

    // Dispatch sends synchronously, before the tracked runner accepts a handle.
    submitting.current = true
    try {
      await remove.run(sendCartRemoveLine(lineId, ownerKind))
    } finally {
      submitting.current = false
    }
  }

  return (
    <LoadingButton
      type="button"
      className="btn-sm btn-outline-danger"
      aria-label={`Remove ${name}`}
      data-id="shop-cart-remove-line"
      loading={remove.busy}
      onClick={() => void submit()}
    >
      <i className="bi bi-trash" aria-hidden="true" />
    </LoadingButton>
  )
}
