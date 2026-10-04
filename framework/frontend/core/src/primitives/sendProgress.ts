import { AUTH_CODE_REASON_RATE_LIMITED } from '../auth/authCodeSignals.js'
import {
  CODE_SEND_STATE_FAILED,
  CODE_SEND_STATE_HELD,
  CODE_SEND_STATE_NOT_SENT,
  CODE_SEND_STATE_QUEUED,
  CODE_SEND_STATE_SENDING,
  CODE_SEND_STATE_SENT,
  type CodeSendProgress,
} from '../auth/authSendProgress.js'
import { formatCountdown } from '../format/duration.js'

/** One-line status and details-button layout, including the idle twin. */
export const SEND_PROGRESS_ROW_CLASS =
  'd-flex align-items-center gap-2 small mb-3'

/** Details button layout shared with its inert idle twin. */
export const SEND_PROGRESS_DETAILS_CLASS =
  'btn btn-link btn-sm p-0 lh-1 flex-shrink-0'

/** Text of the button when another send is allowed. */
export const SEND_AGAIN_LABEL = 'Send again'

/** One send-progress line ready for a view package to draw. */
export interface SendProgressLine {
  readonly icon: string
  readonly tone: string
  readonly text: string
}

/**
 * Resolve the line's stable state into copy shared by all profile views.
 *
 * @param progress Session send line, or null before the first send.
 * @param to Address or phone number receiving the code.
 * @returns Copy and visual cues, or null for an unknown state.
 */
export function sendProgressLine(
  progress: CodeSendProgress | null,
  to: string,
): SendProgressLine | null {
  if (progress === null) {
    return null
  }

  let line: SendProgressLine
  if (progress.state === CODE_SEND_STATE_HELD) {
    line = {
      icon: 'bi-clock',
      tone: 'text-body-secondary',
      text: `The last code sent to ${to} was already used`,
    }
  } else if (
    progress.state === CODE_SEND_STATE_SENT &&
    progress.reason === AUTH_CODE_REASON_RATE_LIMITED
  ) {
    line = {
      icon: 'bi-check-circle-fill',
      tone: 'text-success',
      text: `Sent to ${to} earlier — that code still works`,
    }
  } else {
    switch (progress.state) {
      case CODE_SEND_STATE_QUEUED:
        line = {
          icon: 'bi-hourglass-split',
          tone: 'text-body-secondary',
          text: 'Queued for sending…',
        }
        break
      case CODE_SEND_STATE_SENDING:
        line = {
          icon: 'bi-arrow-repeat',
          tone: 'text-primary',
          text: `Sending to ${to}…`,
        }
        break
      case CODE_SEND_STATE_SENT:
        line = {
          icon: 'bi-check-circle-fill',
          tone: 'text-success',
          text: `Sent to ${to}`,
        }
        break
      case CODE_SEND_STATE_FAILED:
        line = {
          icon: 'bi-exclamation-triangle-fill',
          tone: 'text-danger',
          text: 'Could not send',
        }
        break
      case CODE_SEND_STATE_NOT_SENT:
        line = {
          icon: 'bi-flask',
          tone: 'text-body-secondary',
          text: `Not really sent to ${to} — letters are written here, not mailed`,
        }
        break
      default:
        return null
    }
  }

  return progress.detail === null
    ? line
    : { ...line, text: `${line.text}: ${progress.detail}` }
}

/**
 * Copy of the countdown before another send becomes available.
 *
 * @param resendAt Local-scale moment the gate reopens, or null.
 * @param now Current local clock in epoch milliseconds.
 * @returns Countdown sentence, or null after the gate opens.
 */
export function sendAgainIn(
  resendAt: number | null,
  now: number,
): string | null {
  const countdown = formatCountdown(resendAt, now)
  return countdown === null ? null : `Send again in ${countdown}`
}

/**
 * Whether a resend button must wait for a request, transport, or cooldown.
 *
 * @param progress Session send line, or null before the first send.
 * @param busy Whether this window has a code request in flight.
 * @param resendAt Local-scale moment the gate reopens, or null.
 * @param now Current local clock in epoch milliseconds.
 * @returns True while a resend must be disabled.
 */
export function sendAgainLocked(
  progress: CodeSendProgress | null,
  busy: boolean,
  resendAt: number | null,
  now: number,
): boolean {
  return (
    busy ||
    progress?.state === CODE_SEND_STATE_QUEUED ||
    progress?.state === CODE_SEND_STATE_SENDING ||
    (resendAt !== null && now < resendAt)
  )
}
