// HilosEditNotice — the one message of an edit modal about the other side:
// the row was deleted under the modal, a field conflicts, or a field took a
// value that arrived elsewhere ("Updated just now"). It is drawn in room that
// was taken before there was anything to say: the block is exactly one line
// tall and never changes height, because while there is no message an
// invisible twin of the very same markup holds the room, so no message moves
// the fields the person is editing (styling-rules.md, "The room a live message
// takes"). One line at any length: the text is truncated with an ellipsis and
// the whole of it lives behind the details button, which stands at every
// message and is always the same width — a button that came and went would
// change the row from message to message. Truncation is visual only, so the
// node's text stays whole.
// The mechanics are HilosFormError's, but this is not that row with a tone:
// that one is the refusal of a form (HIL-957), its details carry a class name,
// an original text and a Copy button, and a word about a change made elsewhere
// is not a refusal.
// The slot is the modal's live region for this message — role=status, polite.
// An edit modal does not swap its steps under the row, so the permanent node
// can be the slot itself, the same reasoning as `announce` on HilosFormError;
// the row carries no role of its own, or the reader would say it twice
// (accessibility.md, "Live regions").
import { useEffect, useState } from 'react'
import type { RowEditNoticeKind } from '@hilos/core'

import { HilosLongText } from './HilosLongText.js'
import { HilosModal } from './HilosModal.js'

/** Props for {@link HilosEditNotice}. */
export interface HilosEditNoticeProps {
  /** Which message to draw; null draws none, and the twin holds the room. */
  kind: RowEditNoticeKind | null
  /** The message, whole: truncated on the row, entire in the details panel. */
  text: string
  /** The data-id of the visible row; the slot, the twin, the button and the full text derive theirs from it. */
  dataId: string
}

/**
 * The row and its idle twin, to the character — only the tone and `invisible`
 * differ, and the tone changes no height. The room equals the true height of
 * the row exactly while the markup matches.
 */
const ROW_CLASS = 'alert small py-1 px-2 my-2 d-flex align-items-center gap-2'

/** The details button and the inert copy of it the twin holds the room for. */
const DETAILS_CLASS =
  'btn btn-link btn-sm p-0 lh-1 flex-shrink-0 text-decoration-none text-nowrap'

/**
 * The tone of each message: a warning where a choice is needed or saving is
 * over, a quiet note where the form took a value on its own.
 */
const TONES: Record<RowEditNoticeKind, { row: string; icon: string }> = {
  deleted: { row: 'alert-warning', icon: 'bi bi-exclamation-triangle' },
  conflict: { row: 'alert-warning', icon: 'bi bi-exclamation-triangle' },
  updated: { row: 'alert-secondary', icon: 'bi bi-arrow-repeat' },
}

/** The shape the twin takes: any of the three is as tall as the others. */
const IDLE_TONE = TONES.conflict

/**
 * Draw an edit modal's message about the other side in room that is held
 * whether or not there is one.
 *
 * @param props Which message to draw, its text, and the name of its row.
 */
export function HilosEditNotice({ kind, text, dataId }: HilosEditNoticeProps) {
  const [detailOpen, setDetailOpen] = useState(false)

  // A message that went takes the panel with it: a panel left open would be
  // showing a text the row no longer says.
  useEffect(() => {
    if (kind === null) {
      setDetailOpen(false)
    }
  }, [kind])

  return (
    <>
      <div data-id={`${dataId}-slot`} role="status" aria-live="polite">
        {kind === null ? (
          <div
            className={`${ROW_CLASS} ${IDLE_TONE.row} invisible`}
            aria-hidden="true"
            data-id={`${dataId}-idle`}
          >
            <i
              className={`${IDLE_TONE.icon} flex-shrink-0`}
              aria-hidden="true"
            />
            <span className="flex-grow-1 text-truncate">&nbsp;</span>
            {/* A span, not a button: the twin holds room, it does not take focus. */}
            <span className={DETAILS_CLASS}>
              <i className="bi bi-info-circle" aria-hidden="true" />
            </span>
          </div>
        ) : (
          <div className={`${ROW_CLASS} ${TONES[kind].row}`} data-id={dataId}>
            <i
              className={`${TONES[kind].icon} flex-shrink-0`}
              aria-hidden="true"
            />
            <span className="flex-grow-1 text-truncate">{text}</span>
            <button
              type="button"
              className={DETAILS_CLASS}
              aria-label="Show details"
              title="Show details"
              data-id={`${dataId}-details`}
              onClick={() => setDetailOpen(true)}
            >
              <i className="bi bi-info-circle" aria-hidden="true" />
            </button>
          </div>
        )}
      </div>

      <HilosModal
        open={detailOpen}
        title="Details"
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
        <HilosLongText kind="prose" text={text} dataId={`${dataId}-full`} />
      </HilosModal>
    </>
  )
}
