// HilosActionError — the refusal of a tracked action, drawn where the person
// acted. The "server refused" plate of the modal mockup: an alert with an icon
// and the sentence, at the top of the modal body and above the fields, where a
// toast alone would fly away from the form it belongs to (toasts.md). An admin
// surface additionally gets the two fields the backend only sends there — the
// class name of what actually failed, as a badge, and its original text behind
// that badge in a detail panel with a Copy button (HIL-779). Their presence is
// the sign the framework held something back: a refusal written for a person is
// already shown in full, so it carries no detail. The panel is a HilosModal over
// the modal the action was sent from; stacking is the portal DOM order, not a
// hand-set z-index. Bootstrap classes only, save the one pre-wrap declaration
// the mockup calls for because Bootstrap has no utility for it.
import { useEffect, useState } from 'react'
import { copyToClipboard, isClipboardAvailable } from '@hilos/core'

import { HilosModal } from './HilosModal.js'
import type { TrackedAction } from './useTrackedAction.js'

/** Props for {@link HilosActionError}. */
export interface HilosActionErrorProps {
  /** The tracked action whose latest failure this draws; nothing renders while it is clear. */
  action: TrackedAction
}

/** The type badge, in both the clickable and the inert form. */
const BADGE_CLASS =
  'badge rounded-pill bg-danger-subtle text-danger-emphasis border border-danger-subtle d-inline-flex align-items-center gap-1 flex-shrink-0'

/**
 * Draw a tracked action's refusal, with the admin-only detail behind its badge.
 *
 * @param props The tracked action to draw.
 */
export function HilosActionError({ action }: HilosActionErrorProps) {
  const [detailOpen, setDetailOpen] = useState(false)
  const errorType = action.failure?.errorType
  const errorDetail = action.failure?.errorDetail
  // An empty message leaves nothing to open the panel with, and the type is then
  // all that is known — so the badge is still drawn, just not as a button.
  const hasDetail = (errorDetail ?? '') !== ''

  // Clearing the failure takes the panel with it: the screen re-arms on the next
  // attempt, and a panel left open would be showing the previous one's text.
  useEffect(() => {
    if (action.failure === null) {
      setDetailOpen(false)
    }
  }, [action.failure])

  if (action.error === null) {
    return null
  }

  const badge = (
    <>
      <i className="bi bi-info-circle" aria-hidden="true"></i>
      <span>{errorType}</span>
    </>
  )

  return (
    <>
      <div
        className="alert alert-danger d-flex align-items-center gap-2 py-2"
        role="alert"
        data-id="hilos-action-error"
      >
        <i
          className="bi bi-exclamation-circle flex-shrink-0"
          aria-hidden="true"
        ></i>
        <span className="flex-grow-1">{action.error}</span>
        {errorType !== undefined &&
          (hasDetail ? (
            <button
              type="button"
              className={BADGE_CLASS}
              title={`Show the original ${errorType} message`}
              data-id="hilos-action-error-type"
              onClick={() => setDetailOpen(true)}
            >
              {badge}
            </button>
          ) : (
            <span className={BADGE_CLASS} data-id="hilos-action-error-type">
              {badge}
            </span>
          ))}
      </div>

      <HilosModal
        open={detailOpen}
        title={errorType}
        onClose={() => setDetailOpen(false)}
        actions={({ requestClose }) => (
          <>
            {isClipboardAvailable() && (
              <button
                type="button"
                className="btn btn-outline-secondary"
                data-id="hilos-action-error-copy"
                onClick={() => void copyToClipboard(errorDetail ?? '')}
              >
                <i className="bi bi-clipboard me-1" aria-hidden="true"></i>Copy
              </button>
            )}
            <button
              type="button"
              className="btn btn-secondary"
              data-id="hilos-action-error-close"
              onClick={requestClose}
            >
              Close
            </button>
          </>
        )}
      >
        <pre
          className="mb-0 small text-break"
          style={{
            whiteSpace: 'pre-wrap',
            maxHeight: '60vh',
            overflowY: 'auto',
          }}
          data-id="hilos-action-error-detail"
        >
          {errorDetail}
        </pre>
      </HilosModal>
    </>
  )
}
