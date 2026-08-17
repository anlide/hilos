// The inbound phone one-time-code signal the daemon delivers WS_USER to the
// requesting connection (HIL-492), declared as a project signal schema so the
// parse boundary validates it before a reaction runs. Kept pure (schema + names
// only, no connection import) so `bootstrap/connection` can merge it without a
// cycle through the module that drives the flow over it.
//
// Requesting a code became asynchronous for every channel: deciding whether a
// channel can reach a number is a network round-trip on some of them, so the
// action ack only means "accepted, working" and the real outcome arrives here.
// SUCCESS travels on this signal too, unlike the OAuth result — the code screen
// must open only once a code really went out, and it must name the channel it
// went over rather than the one that was clicked.
//
// The name and the reason values are byte-equal to the backend
// `HilosSignalConstants` / `AuthCodeResultSignalData` constants.
import { z } from 'zod'

import { type ProjectSignalSchemas } from '../protocol/parseSignal.js'

/** Signal `type` for a code-request outcome (PHP `HilosSignalConstants::HILOS_AUTH_CODE_RESULT`). */
export const AUTH_CODE_RESULT_SIGNAL = 'hilos_auth_code_result'

/**
 * `reason` marking a code that went out (PHP `AuthCodeResultSignalData::REASON_CODE_SENT`).
 * The only arm that advances the surface to the code screen.
 */
export const AUTH_CODE_REASON_SENT = 'code_sent'

/**
 * `reason` marking a channel that cannot reach this number (PHP
 * `REASON_CHANNEL_UNAVAILABLE`). Nothing was minted and no cooldown was spent, so
 * the person may pick another channel and still get their first code.
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

/**
 * The code-request outcome payload: the channel the request named and a stable,
 * non-sensitive reason code. `resendAt` is filled on the arms where waiting is the
 * answer — the SERVER moment a fresh send's cooldown runs out, or the one already
 * running does — and null where waiting changes nothing. `expiresAt` is the SERVER
 * moment the code now in play dies, which the code screen counts down; it is the
 * EARLIER code's moment on the rate-limited arm, since that is the one the person
 * is being asked for. The wire also carries the targeting `acceptKey`, kept
 * here for validation fidelity though the reaction ignores it (WS_USER already
 * targets this connection).
 */
export const authCodeResultSignalSchema = z.object({
  acceptKey: z.string(),
  channel: z.string(),
  reason: z.string(),
  resendAt: z.number().nullable().default(null),
  expiresAt: z.number().nullable().default(null),
})

/** Typed code-request outcome payload (the schema's output). */
export type AuthCodeResultSignalData = z.infer<
  typeof authCodeResultSignalSchema
>

/**
 * The project signal schema `createHilosConnection` merges so the code-request
 * outcome parses at the boundary before the auth surface reacts to it.
 */
export const AUTH_CODE_SIGNAL_SCHEMAS: ProjectSignalSchemas = {
  [AUTH_CODE_RESULT_SIGNAL]: authCodeResultSignalSchema,
}
