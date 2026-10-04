import {
  SEND_AGAIN_LABEL,
  SEND_PROGRESS_DETAILS_CLASS,
  SEND_PROGRESS_ROW_CLASS,
  sendAgainIn,
  sendAgainLocked,
  sendProgressLine,
  type CodeSendProgress,
} from '@hilos/core'
import { useEffect, useState } from 'react'

import { HilosLongText } from './HilosLongText.js'
import { HilosModal } from './HilosModal.js'

/** Props for the session send line and its resend control. */
export interface HilosSendProgressProps {
  /** The session's send line for this window, or null before the first send. */
  progress: CodeSendProgress | null
  /** Address or phone number receiving the code. */
  to: string
  /** Local-scale moment another send is allowed, or null. */
  resendAt: number | null
  /** Whether this window is ordering a code now. */
  busy: boolean
  /** Data-id of the whole block; the row and controls derive theirs from it. */
  dataId: string
  /** Called when the person asks for another code. */
  onSendAgain: () => void
}

/** Profile code delivery status and a timed resend control. */
export function HilosSendProgress({
  progress,
  to,
  resendAt,
  busy,
  dataId,
  onSendAgain,
}: HilosSendProgressProps) {
  const [now, setNow] = useState(Date.now)
  const [detailOpen, setDetailOpen] = useState(false)
  const line = sendProgressLine(progress, to)
  const countdown = sendAgainIn(resendAt, now)
  const locked = sendAgainLocked(progress, busy, resendAt, now)
  const hasSend = progress !== null || resendAt !== null

  useEffect(() => {
    setNow(Date.now())
    if (resendAt === null || Date.now() >= resendAt) {
      return
    }
    const clock = setInterval(() => {
      setNow(Date.now())
      if (Date.now() >= resendAt) {
        clearInterval(clock)
      }
    }, 1000)
    return () => clearInterval(clock)
  }, [resendAt])

  useEffect(() => {
    if (line === null) {
      setDetailOpen(false)
    }
  }, [line])

  return (
    <>
      <div data-id={dataId}>
        {line === null ? (
          <div
            className={`${SEND_PROGRESS_ROW_CLASS} invisible`}
            aria-hidden="true"
            data-id={`${dataId}-idle`}
          >
            <i
              className="bi bi-hourglass-split flex-shrink-0"
              aria-hidden="true"
            ></i>
            <span className="flex-grow-1 text-truncate">&nbsp;</span>
            <span className={SEND_PROGRESS_DETAILS_CLASS}>
              <i className="bi bi-info-circle" aria-hidden="true"></i>
            </span>
          </div>
        ) : (
          <div
            className={`${SEND_PROGRESS_ROW_CLASS} ${line.tone}`}
            data-id={`${dataId}-line`}
          >
            <i
              className={`bi ${line.icon} flex-shrink-0`}
              aria-hidden="true"
            ></i>
            <span className="flex-grow-1 text-truncate">{line.text}</span>
            <button
              type="button"
              className={SEND_PROGRESS_DETAILS_CLASS}
              aria-label="Show send details"
              title="Show send details"
              data-id={`${dataId}-details`}
              onClick={() => setDetailOpen(true)}
            >
              <i className="bi bi-info-circle" aria-hidden="true"></i>
            </button>
          </div>
        )}

        <div className="mb-3">
          {countdown !== null ? (
            <span
              className="btn btn-link btn-sm p-0 disabled"
              data-id={`${dataId}-again-in`}
            >
              {countdown}
            </span>
          ) : hasSend ? (
            <button
              type="button"
              className="btn btn-link btn-sm p-0"
              disabled={locked}
              aria-busy={busy || undefined}
              data-id={`${dataId}-again`}
              onClick={onSendAgain}
            >
              {SEND_AGAIN_LABEL}
            </button>
          ) : (
            <span
              className="btn btn-link btn-sm p-0 invisible"
              aria-hidden="true"
            >
              {SEND_AGAIN_LABEL}
            </span>
          )}
        </div>
      </div>

      <HilosModal
        open={detailOpen}
        title="Send details"
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
        <HilosLongText
          kind="prose"
          text={line?.text ?? ''}
          dataId={`${dataId}-full`}
        />
      </HilosModal>
    </>
  )
}
