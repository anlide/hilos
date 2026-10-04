// The send-progress line on the code screen: the frame that says where the code
// this browser is waiting for has got to (HIL-826).
//
// Pressing the button used to move the screen and say nothing further. Whether
// the letter left, is still queued behind a stalled mail server, or was refused
// outright, the person could not tell — all three look like waiting for digits.
// This frame is what ends that, over email and over every code channel alike.
//
// It is addressed to the browser SESSION rather than to the socket, which is what
// makes a reload and a second tab read the same line and another browser read
// nothing: the three clauses fall out of the addressing instead of being three
// separate pieces of work. Like the session's toast stack it carries the WHOLE
// line rather than a change, so a reconnect and an ordinary step are one and the
// same sentence — and a frame with a null `state` is the legal one that takes the
// line away.
//
// The name and the six state values are byte-equal to the backend
// `HilosSignalConstants` / `HilosCodeSendAttempt` constants.
import { z } from 'zod'

import { type HilosConnection } from '../connection/HilosConnection.js'
import { type ProjectSignal } from '../protocol/parseSignal.js'
import { toLocal } from '../session/serverClock.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
} from '../state/signal.js'

/** Signal `type` for the line (PHP `HilosSignalConstants::HILOS_CODE_SEND_PROGRESS`). */
export const SIGNAL_CODE_SEND_PROGRESS = 'hilos_code_send_progress'

/**
 * `state` of a send that is ordered and not yet attempted (PHP
 * `HilosCodeSendAttempt::STATE_QUEUED`). A dead mail shard leaves the line here
 * though the letter died with it: a raw send has no durable record. The wait ends
 * with the code expiring or the person pressing resend.
 */
export const CODE_SEND_STATE_QUEUED = 'queued'

/** `state` of a send a transport is attempting right now (PHP `STATE_SENDING`). */
export const CODE_SEND_STATE_SENDING = 'sending'

/** `state` of a send the transport took (PHP `STATE_SENT`). The digits are on their way. */
export const CODE_SEND_STATE_SENT = 'sent'

/**
 * `state` of a send that is over and did not go (PHP `STATE_FAILED`). Terminal: a
 * retryable refusal goes back to {@link CODE_SEND_STATE_QUEUED} instead, so this is
 * the state a person acts on by pressing resend.
 */
export const CODE_SEND_STATE_FAILED = 'failed'

/**
 * `state` of a send that is over and was written down instead of mailed (PHP
 * `STATE_NOT_SENT`). Terminal like {@link CODE_SEND_STATE_SENT} and, like it, a
 * SUCCESS: the code is live and the person types it in, having read it where the
 * installation's test mode puts the letter. It is its own state rather than a
 * shade of `sent` because a line that claims a delivery leaves a person waiting
 * for a message that is not coming.
 */
export const CODE_SEND_STATE_NOT_SENT = 'not_sent'

/** A profile send held by the cooldown after its previous code was used. */
export const CODE_SEND_STATE_HELD = 'held'

/** Purpose of a code sent for operation-level identity confirmation. */
export const CODE_SEND_PURPOSE_STEP_UP = 'step_up'

/**
 * The frame: the whole line, every field nullable.
 *
 * Every field being null is not a lax reader but the commonest frame the signal
 * carries — it is what a handshake is answered with when the session is waiting
 * for nothing, and what takes the line off the screen when a wait is let go.
 * `detail` carries the provider's own sentence and is deliberately untranslated:
 * the point of the refusal is that a person is told "mailbox unavailable (550)"
 * rather than "something went wrong", and no key of ours can hold words we did
 * not write.
 *
 * The rest is the send outcome: `ticket` names the send, `purpose` names a
 * profile window when one ordered it, and `reason` plus the two moments carry
 * the gate's verdict. The line is replayed on every handshake, so a tab back
 * from a dropped connection reads the ending too.
 */
export const codeSendProgressSchema = z.looseObject({
  state: z.string().nullable().default(null),
  channel: z.string().nullable().default(null),
  purpose: z.string().nullable().default(null),
  detail: z.string().nullable().default(null),
  ticket: z.string().nullable().default(null),
  reason: z.string().nullable().default(null),
  resendAt: z.number().nullable().default(null),
  expiresAt: z.number().nullable().default(null),
})

/** Typed send-progress payload (the schema's output). */
export type CodeSendProgressSignalData = z.infer<typeof codeSendProgressSchema>

/**
 * One reported step of the send behind the code screen (HIL-826).
 *
 * `state` is one of the six `CODE_SEND_STATE_*` values. `detail` carries the
 * provider's own sentence and only on a refusal — untranslated, because the whole
 * point of it is that a person is told "mailbox unavailable (550)" rather than
 * "something went wrong".
 */
export interface CodeSendProgress {
  /** One of the six `CODE_SEND_STATE_*` values. */
  readonly state: string
  /** The channel the code travels over, or `null` when the server named none. */
  readonly channel: string | null
  /** Profile operation owning this line, or null for a guest send. */
  readonly purpose: string | null
  /** The provider's own sentence on a refusal, `null` otherwise. */
  readonly detail: string | null
  /** Ticket of the send this line follows. */
  readonly ticket: string | null
  /** Send gate outcome on a held code, or null. */
  readonly reason: string | null
  /** Local-scale moment another send is allowed, or null. */
  readonly resendAt: number | null
  /** Local-scale moment the live code dies, or null. */
  readonly expiresAt: number | null
}

/** Server reply to a profile code request, before its moments are made local. */
export const codeSendReplySchema = z.object({
  sent: z.boolean(),
  resendAt: z.number(),
  expiresAt: z.number().nullable(),
})

/** Send gate outcome carried by a profile code request. */
export type HilosCodeSendReply = z.infer<typeof codeSendReplySchema>

const codeSendProgress = createSignal<CodeSendProgress | null>(null)

/**
 * The line this browser session is currently being shown, or `null` when it is
 * owed none (HIL-826).
 *
 * One per loaded SDK and read by the auth surface, exactly as the toast stack is
 * read by the shell. It is a HELD value and not a subscription for a reason the
 * screen depends on: the server publishes the line on every handshake, and on a
 * gated page the surface only mounts once that handshake has been answered — a
 * surface that listened for itself would miss the frame it exists to draw, which
 * is precisely the reload case.
 */
export const hilosCodeSendProgress: ReadonlySignal<CodeSendProgress | null> =
  codeSendProgress

/**
 * The session line only while it belongs to the given profile operation.
 *
 * @param purpose Profile operation key.
 */
export function hilosCodeSendProgressFor(
  purpose: string,
): ReadonlySignal<CodeSendProgress | null> {
  return computedSignal(() => {
    const progress = codeSendProgress.get()
    return progress?.purpose === purpose ? progress : null
  })
}

/**
 * Route the session's send-progress frames into {@link hilosCodeSendProgress}.
 *
 * Bound by `bootHilos` before the socket opens, the way the toast stack is bound,
 * so the first frame is never missed. A frame with a null `state` is stored as
 * `null` rather than dropped: it is the legal frame that takes the line away, and
 * silence and "there is nothing on its way" must not look the same here either.
 *
 * @param connection The application's Hilos connection.
 * @returns Unsubscribe for the registered signal handler.
 */
export function bindCodeSendProgress(connection: HilosConnection): () => void {
  return connection.on('projectSignal', (signal: ProjectSignal) => {
    if (signal.type !== SIGNAL_CODE_SEND_PROGRESS) {
      return
    }
    const data = codeSendProgressSchema.parse(signal.data)
    codeSendProgress.set(
      data.state === null
        ? null
        : {
            state: data.state,
            channel: data.channel,
            purpose: data.purpose,
            detail: data.detail,
            ticket: data.ticket,
            reason: data.reason,
            resendAt: data.resendAt === null ? null : toLocal(data.resendAt),
            expiresAt: data.expiresAt === null ? null : toLocal(data.expiresAt),
          },
    )
  })
}
