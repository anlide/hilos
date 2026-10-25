import { useState } from 'react'

import { HilosModal } from './HilosModal.js'

/** Props for {@link HilosActionReason}. */
export interface HilosActionReasonProps {
  /** The current reason; `null` hides only the opener. */
  reason: string | null
  /** Accessible name of the native explanation button. */
  label?: string
  /** Visible and accessible name of the explanation dialog. */
  title?: string
}

/** Let a reader open and dismiss the full reason an action is unavailable. */
export function HilosActionReason({
  reason,
  label = 'Why this action is unavailable',
  title = 'Action unavailable',
}: HilosActionReasonProps) {
  const [open, setOpen] = useState(false)
  const [explanation, setExplanation] = useState('')

  /** Read the current reason once for this visit to the dialog. */
  function showReason(): void {
    if (reason === null) return
    setExplanation(reason)
    setOpen(true)
  }

  return (
    <>
      {reason !== null && (
        <button
          type="button"
          className="btn btn-sm btn-outline-secondary"
          title={reason}
          aria-label={label}
          data-id="hilos-action-reason"
          onClick={showReason}
        >
          <i className="bi bi-question-circle" aria-hidden="true" />
        </button>
      )}
      <HilosModal
        open={open}
        title={title}
        initialFocus="dialog"
        onClose={() => setOpen(false)}
        actions={({ requestClose }) => (
          <button
            type="button"
            className="btn btn-secondary"
            data-id="hilos-action-reason-close"
            onClick={requestClose}
          >
            Close
          </button>
        )}
      >
        <p className="mb-0 text-break" data-id="hilos-action-reason-body">
          {explanation}
        </p>
      </HilosModal>
    </>
  )
}
