// HilosActionError — the refusal of a tracked action, drawn where the person
// acted. The "server refused" plate of the modal mockup: an alert with an icon
// and the sentence, at the top of the modal body and above the fields, where a
// toast alone would fly away from the form it belongs to (toasts.md).
// Two layers, and they never collapse into one. The slot is permanent and
// carries no styling class at all: it is the live region — a node carrying
// aria-live that is inserted together with its own text announces nothing
// (accessibility.md) — and while there is nothing to say it looks like nothing.
// Inside it stands either the visible plate, which carries no role of its own or
// the reader says the same sentence twice, or an invisible twin of that very
// plate. The twin is what holds the room, so what stands below the plate never
// jumps when a refusal arrives; it is the plate itself made `invisible` rather
// than a guessed `min-height`, because it is then exactly as tall as the real
// thing whatever the real thing becomes (LoadingButton holds the room for the
// text under its spinner the same way), and a declaration of our own outside the
// Sass layer is not ours to write (styling-rules.md).
// The plate is one line at any length: the sentence truncates and is read whole
// in the detail modal behind the button on the right. That button is there for
// every refusal, because it is the only way to the full text; on an admin
// surface it also carries the class name of what actually failed, which is the
// sign the framework held something back (HIL-779). The name hides on a narrow
// screen and the icon does not: the name is longer than the narrowest plate and
// would eat the sentence the plate exists for. Inside the modal the sentence
// comes first and the original text below it, both drawn by HilosLongText and
// copied by the modal's own copyText, so neither the wrapping of a long line nor
// the Copy button is written here a second time (rules-and-violations.md,
// section E).
// `suppressed` says "this same action is answering somewhere else right now":
// the room stays, the voice goes. It has to be the room that stays — a page that
// drops the plate to keep it out of its own confirmation moves everything under
// the backdrop and hands it back shifted.
// Bootstrap classes only.
import { useEffect, useState } from 'react'

import { HilosLongText } from './HilosLongText.js'
import { HilosModal } from './HilosModal.js'
import type { TrackedAction } from './useTrackedAction.js'

/** Props for {@link HilosActionError}. */
export interface HilosActionErrorProps {
  /** The tracked action whose latest failure this draws; the room is held either way. */
  action: TrackedAction
  /** Hold the room and stay silent — this action is answering somewhere else. */
  suppressed?: boolean
}

/** The details button, and the inert copy of it the twin holds the room for. */
const BADGE_CLASS =
  'badge rounded-pill bg-danger-subtle text-danger-emphasis border border-danger-subtle d-inline-flex align-items-center gap-1 flex-shrink-0'

/** The classes the plate and its invisible twin share, to the character. */
const PLATE_CLASS = 'alert alert-danger d-flex align-items-center gap-2 py-2'

/**
 * Draw a tracked action's refusal, in room that is held whether it failed or not.
 *
 * @param props The tracked action to draw, and whether it is answering elsewhere.
 */
export function HilosActionError({
  action,
  suppressed = false,
}: HilosActionErrorProps) {
  const [detailOpen, setDetailOpen] = useState(false)
  const message = action.error
  const errorType = action.failure?.errorType
  const errorDetail = action.failure?.errorDetail
  // An original text the backend did not send is not a shorter panel but no
  // panel: what is drawn then is the sentence alone.
  const hasDetail = (errorDetail ?? '') !== ''
  // Copy carries the original text when there is one and the sentence when
  // there is not: it is copied to be pasted into a ticket, and what is pasted
  // is the long half.
  const copyText = hasDetail ? (errorDetail ?? '') : (message ?? '')

  // Clearing the message takes the panel with it: the screen re-arms on the
  // next attempt, and a panel left open would be showing the previous one's
  // text. The guard watches the message and not the failure, because the
  // failure is null for everything thrown as something other than an
  // ActionError — and the panel now opens for those too.
  useEffect(() => {
    if (message === null) {
      setDetailOpen(false)
    }
  }, [message])

  return (
    <>
      <div data-id="hilos-action-error-slot" role="alert" aria-live="assertive">
        {message !== null && !suppressed ? (
          <div className={PLATE_CLASS} data-id="hilos-action-error">
            <i
              className="bi bi-exclamation-circle flex-shrink-0"
              aria-hidden="true"
            ></i>
            <span className="flex-grow-1 text-truncate">{message}</span>
            <button
              type="button"
              className={BADGE_CLASS}
              aria-label="Show error details"
              title="Show error details"
              data-id="hilos-action-error-details"
              onClick={() => setDetailOpen(true)}
            >
              <i className="bi bi-info-circle" aria-hidden="true"></i>
              {errorType !== undefined && (
                <span
                  className="d-none d-sm-inline"
                  data-id="hilos-action-error-type"
                >
                  {errorType}
                </span>
              )}
            </button>
          </div>
        ) : (
          <div
            className={`${PLATE_CLASS} invisible`}
            aria-hidden="true"
            data-id="hilos-action-error-idle"
          >
            <i
              className="bi bi-exclamation-circle flex-shrink-0"
              aria-hidden="true"
            ></i>
            <span className="flex-grow-1 text-truncate">&nbsp;</span>
            <span className={BADGE_CLASS}>
              <i className="bi bi-info-circle" aria-hidden="true"></i>
            </span>
          </div>
        )}
      </div>

      <HilosModal
        open={detailOpen}
        title={errorType ?? 'Error details'}
        copyText={copyText}
        onClose={() => setDetailOpen(false)}
        actions={({ requestClose }) => (
          <button
            type="button"
            className="btn btn-secondary"
            data-id="hilos-action-error-close"
            onClick={requestClose}
          >
            Close
          </button>
        )}
      >
        <div className="d-flex flex-column gap-3">
          <HilosLongText
            kind="prose"
            text={message ?? ''}
            dataId="hilos-action-error-message"
          />
          {hasDetail && (
            <HilosLongText
              kind="output"
              text={errorDetail ?? ''}
              dataId="hilos-action-error-detail"
            />
          )}
        </div>
      </HilosModal>
    </>
  )
}
