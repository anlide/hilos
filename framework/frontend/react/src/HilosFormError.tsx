// HilosFormError — the refusal of a form, drawn in room that was taken before
// the refusal existed. The block is exactly one line tall and never changes
// height: with no refusal an invisible twin of the very same markup holds the
// room, so showing the refusal moves nothing the person is reading. The room is
// held by that twin and not by a height of our own, because the twin is as tall
// as the row turns out to be at this width and this font, and because a
// declaration of our own is not ours to write (styling-rules.md); the trick is
// LoadingButton's. One line at any length: the text is truncated with an
// ellipsis and the whole of it lives behind the details button, which stands at
// every refusal and is always the same width — a button that came and went would
// change the row from refusal to refusal, and a truncated text would have
// nowhere to open. Truncation is visual only, so the node's text stays whole.
// This row is the one refusal row of the SDK: HilosActionError draws a tracked
// action's refusal by mounting it, and what that needs beyond a form's sentence
// — the class name beside the details icon, the original text under an
// "Exception" caption, the Copy button, a live region on the slot — lives here
// as props that are off by default, one behavior rather than a second copy of
// the row.
// The row carries no role at all. A form's voice is the surface's own permanent
// live region, kept apart from the sight of it (accessibility.md) — a live
// region living inside a form that swaps its steps would die with its step;
// only a surface that stays put under the row makes the slot itself the region
// (`announce`).
import { useEffect, useState } from 'react'

import { HilosLongText } from './HilosLongText.js'
import { HilosModal } from './HilosModal.js'

/** Props for {@link HilosFormError}. */
export interface HilosFormErrorProps {
  /** The refusal to draw; an empty string means the same as null — no refusal. */
  message: string | null
  /** The data-id of the visible row; the slot and the idle twin derive theirs from it. */
  dataId: string
  /** Short class name of what failed; drawn beside the details icon and above the original text. */
  errorType?: string | null
  /** The failure's original text; when not empty, the details panel shows it under "Exception". */
  errorDetail?: string | null
  /** What the details panel's Copy button copies; empty means no Copy button. */
  copyText?: string
  /**
   * Make the slot itself the live region (role=alert, aria-live=assertive).
   * Only for a surface that does not change under the row, such as an admin
   * modal; a form that swaps its steps leaves it off and keeps its voice on the
   * surface, because a region inside a step would die with the step
   * (accessibility.md, "The room belongs to the block, the voice to the
   * surface").
   */
  announce?: boolean
}

/**
 * The row and its idle twin, to the character — only `invisible` differs. The
 * room equals the true height of the row exactly while the markup matches.
 */
const ROW_CLASS =
  'alert alert-danger small py-1 px-2 my-2 d-flex align-items-center gap-2'

/** The details button and the inert copy of it the twin holds the room for. */
const DETAILS_CLASS =
  'btn btn-link btn-sm p-0 lh-1 flex-shrink-0 text-decoration-none text-nowrap'

/**
 * Draw a form's refusal in room that is held whether or not there is one.
 *
 * @param props The refusal to draw, the name of its visible row, and what an
 *   action's refusal adds to it.
 */
export function HilosFormError({
  message,
  dataId,
  errorType = null,
  errorDetail = null,
  copyText = '',
  announce = false,
}: HilosFormErrorProps) {
  const [detailOpen, setDetailOpen] = useState(false)
  // An empty string is the absence of a refusal, the same as null: one meaning,
  // one behavior — otherwise an empty string would draw a red row about nothing.
  const shown = (message ?? '') === '' ? null : message
  // An original text that was not sent is not a shorter block but no block.
  const hasDetail = (errorDetail ?? '') !== ''
  // The class name heads the original text, so what is read — and copied —
  // names what failed.
  const detailText = errorType
    ? `${errorType}\n${errorDetail ?? ''}`
    : (errorDetail ?? '')

  // A cleared refusal takes the panel with it: the form re-arms on the next
  // attempt, and a panel left open would be showing the previous one's text.
  useEffect(() => {
    if (shown === null) {
      setDetailOpen(false)
    }
  }, [shown])

  return (
    <>
      <div
        data-id={`${dataId}-slot`}
        role={announce ? 'alert' : undefined}
        aria-live={announce ? 'assertive' : undefined}
      >
        {shown === null ? (
          <div
            className={`${ROW_CLASS} invisible`}
            aria-hidden="true"
            data-id={`${dataId}-idle`}
          >
            <i
              className="bi bi-exclamation-circle flex-shrink-0"
              aria-hidden="true"
            ></i>
            <span className="flex-grow-1 text-truncate">&nbsp;</span>
            {/* A span, not a button: the twin holds room, it does not take focus. */}
            <span className={DETAILS_CLASS}>
              <i className="bi bi-info-circle" aria-hidden="true"></i>
            </span>
          </div>
        ) : (
          <div className={ROW_CLASS} data-id={dataId}>
            <i
              className="bi bi-exclamation-circle flex-shrink-0"
              aria-hidden="true"
            ></i>
            <span className="flex-grow-1 text-truncate">{shown}</span>
            <button
              type="button"
              className={DETAILS_CLASS}
              aria-label="Show error details"
              title="Show error details"
              data-id={`${dataId}-details`}
              onClick={() => setDetailOpen(true)}
            >
              <i className="bi bi-info-circle" aria-hidden="true"></i>
              {errorType && (
                <span
                  className="d-none d-sm-inline ms-1"
                  data-id={`${dataId}-type`}
                >
                  {errorType}
                </span>
              )}
            </button>
          </div>
        )}
      </div>

      <HilosModal
        open={detailOpen}
        title="Error details"
        copyText={copyText}
        initialFocus="dialog"
        onClose={() => setDetailOpen(false)}
        actions={({ requestClose }) => (
          <button
            type="button"
            className="btn btn-secondary"
            data-id={`${dataId}-close`}
            onClick={requestClose}
          >
            Close
          </button>
        )}
      >
        <div className="d-flex flex-column gap-3">
          <HilosLongText
            kind="prose"
            text={shown ?? ''}
            dataId={`${dataId}-full`}
          />
          {hasDetail && (
            <div>
              <div className="small text-body-secondary mb-1">Exception</div>
              <HilosLongText
                kind="output"
                text={detailText}
                dataId={`${dataId}-detail`}
              />
            </div>
          )}
        </div>
      </HilosModal>
    </>
  )
}
