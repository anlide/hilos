import { useId, useLayoutEffect, useRef, useState } from 'react'
import { useTrackedAction } from '@hilos/react/base'

import {
  sendCartStepQuantity,
  type CartOwnerKind,
  type CartQuantityStep,
} from './cartActions.js'
import { type CartLine } from './types/Cart.js'

/**
 * Quantity controls for a saved stock line.
 *
 * @param props The confirmed line, its owner kind, and the exact-quantity opener.
 */
export function CartQuantityControls({
  line,
  ownerKind,
  onEdit,
}: {
  line: CartLine
  ownerKind: CartOwnerKind
  onEdit: () => void
}) {
  const action = useTrackedAction()
  const quantityId = useId()
  const pending = useRef(false)
  const group = useRef<HTMLDivElement>(null)
  const lessButton = useRef<HTMLButtonElement>(null)
  const moreButton = useRef<HTMLButtonElement>(null)
  const lessDisabled = line.quantity === 1
  const moreDisabled = line.quantity >= line.availableQuantity
  const [previousLimits, setPreviousLimits] = useState({
    less: lessDisabled,
    more: moreDisabled,
  })

  // Enable new targets immediately and hand off focus before disabling its owner.
  useLayoutEffect(() => {
    const active = group.current?.ownerDocument.activeElement
    if (
      (active === lessButton.current && lessDisabled) ||
      (active === moreButton.current && moreDisabled)
    ) {
      const next = !lessDisabled
        ? lessButton.current
        : !moreDisabled
          ? moreButton.current
          : group.current
      next?.focus()
    }
    setPreviousLimits({ less: lessDisabled, more: moreDisabled })
  }, [lessDisabled, moreDisabled])

  async function changeQuantity(step: CartQuantityStep): Promise<void> {
    if (pending.current || (step === -1 ? lessDisabled : moreDisabled)) return

    // Dispatch sends immediately, before the tracked action can guard its handle.
    pending.current = true
    try {
      await action.run(sendCartStepQuantity(line.lineId, ownerKind, step))
    } finally {
      pending.current = false
    }
  }

  const buttonClass = `btn btn-outline-secondary${action.busy ? ' disabled' : ''}`

  return (
    <div
      className="input-group input-group-sm flex-nowrap w-auto"
      role="group"
      aria-label={`Quantity of ${line.name}`}
      aria-busy={action.busy || undefined}
      tabIndex={-1}
      ref={group}
    >
      <button
        type="button"
        className={buttonClass}
        aria-label="One less"
        aria-disabled={action.busy || undefined}
        disabled={lessDisabled && previousLimits.less}
        onClick={() => void changeQuantity(-1)}
        ref={lessButton}
      >
        <i className="bi bi-dash" aria-hidden="true" />
      </button>
      <button
        type="button"
        className="btn btn-outline-secondary px-3"
        aria-label="Set quantity"
        aria-describedby={quantityId}
        data-id="shop-cart-quantity"
        onClick={(event) => {
          event.currentTarget.focus()
          onEdit()
        }}
      >
        <span id={quantityId}>
          <span className="visually-hidden">Quantity: </span>
          {line.quantity}
        </span>
      </button>
      <button
        type="button"
        className={buttonClass}
        aria-label="One more"
        aria-disabled={action.busy || undefined}
        disabled={moreDisabled && previousLimits.more}
        onClick={() => void changeQuantity(1)}
        ref={moreButton}
      >
        <i className="bi bi-plus" aria-hidden="true" />
      </button>
    </div>
  )
}
