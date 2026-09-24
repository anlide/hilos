// The outcome reasons of a phone one-time-code request (HIL-492). Requesting a
// code is asynchronous for every channel: deciding whether a channel can reach a
// number is a network round-trip on some of them, so the action reply names the
// send and the real outcome arrives later.
//
// It arrives on the session's send-progress line (HIL-1044), not on a signal of
// its own: the code agent's closing step carries the reason and the moments the
// code screen counts down to, and the line is replayed on every handshake, so a
// tab back from a dropped connection reads the ending too. What the reasons
// decide is what the surface does next; WHEN the code screen opens is not theirs
// (HIL-826) - it opens the moment the send is ordered.
//
// The values are byte-equal to the backend `HilosCodeSendAttempt::REASON_*`
// constants.

/**
 * `reason` marking a code that went out (PHP `HilosCodeSendAttempt::REASON_CODE_SENT`).
 * The arm that arms the resend gate and names the channel the code carried.
 */
export const AUTH_CODE_REASON_SENT = 'code_sent'

/**
 * `reason` marking a channel that cannot reach this number (PHP
 * `REASON_CHANNEL_UNAVAILABLE`). Nothing was minted and no cooldown was spent, so
 * the person is taken back to the step they sent from, that channel is dimmed, and
 * they still get their first code over another one.
 */
export const AUTH_CODE_REASON_CHANNEL_UNAVAILABLE = 'code_channel_unavailable'

/**
 * `reason` marking a send the cooldown held back (PHP `REASON_RATE_LIMITED`).
 * `resendAt` says when it opens.
 */
export const AUTH_CODE_REASON_RATE_LIMITED = 'code_rate_limited'

/**
 * `reason` marking a send the per-window cap refused (PHP `REASON_CAP_REACHED`).
 * It carries no moment: waiting a little changes nothing this window.
 */
export const AUTH_CODE_REASON_CAP_REACHED = 'code_cap_reached'

/**
 * `reason` marking a minted code the transport refused (PHP `REASON_SEND_FAILED`).
 * The provider/network detail stays in the agent log, never on the wire.
 */
export const AUTH_CODE_REASON_SEND_FAILED = 'code_send_failed'
