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
// The row carries no role at all: what a screen reader hears is the surface's
// own permanent live region, kept apart from the sight of it (accessibility.md)
// — a live region living inside a form that swaps its steps would die with its
// step.
import { useEffect, useState } from 'react'

import { HilosModal } from './HilosModal.js'

/** Props for {@link HilosFormError}. */
export interface HilosFormErrorProps {
  /** The refusal to draw; an empty string means the same as null — no refusal. */
  message: string | null
  /** The data-id of the visible row; the slot and the idle twin derive theirs from it. */
  dataId: string
}

/**
 * The row and its idle twin, to the character — only `invisible` differs. The
 * room equals the true height of the row exactly while the markup matches.
 */
const ROW_CLASS =
  'alert alert-danger small py-1 px-2 my-2 d-flex align-items-center gap-2'

/**
 * Draw a form's refusal in room that is held whether or not there is one.
 *
 * @param props The refusal to draw and the name of its visible row.
 */
export function HilosFormError({ message, dataId }: HilosFormErrorProps) {
  const [detailOpen, setDetailOpen] = useState(false)
  // An empty string is the absence of a refusal, the same as null: one meaning,
  // one behavior — otherwise an empty string would draw a red row about nothing.
  const shown = (message ?? '') === '' ? null : message

  // A cleared refusal takes the panel with it: the form re-arms on the next
  // attempt, and a panel left open would be showing the previous one's text.
  useEffect(() => {
    if (shown === null) {
      setDetailOpen(false)
    }
  }, [shown])

  return (
    <>
      <div data-id={`${dataId}-slot`}>
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
            <span className="btn btn-link btn-sm p-0 lh-1 flex-shrink-0">
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
              className="btn btn-link btn-sm p-0 lh-1 flex-shrink-0"
              aria-label="Show the full message"
              title="Show the full message"
              data-id={`${dataId}-details`}
              onClick={() => setDetailOpen(true)}
            >
              <i className="bi bi-info-circle" aria-hidden="true"></i>
            </button>
          </div>
        )}
      </div>

      <HilosModal
        open={detailOpen}
        title="Error details"
        initialFocus="dialog"
        onClose={() => setDetailOpen(false)}
      >
        <p className="mb-0" data-id={`${dataId}-full`}>
          {shown}
        </p>
      </HilosModal>
    </>
  )
}
