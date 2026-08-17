// The inbound auth-converge signal the daemon delivers WS_USER to a parked
// sign-in surface (HIL-415/416/486), declared as a project signal schema so the
// parse boundary validates it before a reaction runs. Kept pure (schema + names
// only, no connection import) so `bootstrap/connection` can merge it without a
// cycle through the module that drives the flow over it — the same shape as
// `authCodeSignals`.
//
// This is the push half of the surface's converge property: an action reply
// answers the session that submitted, and this says the same thing to the ones
// that did not — a second tab parked on the code screen of an address somebody
// else just confirmed, a reservation that ran out, a recovery another device
// completed. Nothing here is a reply to anything this browser asked for, which
// is why it arrives as a signal and not on an ack.
//
// The name and the payload are byte-equal to the backend `ChatSignalConstants`
// / `AuthConvergeSignalData` (`framework/backend/Auth/Flow/DTO`).
import { z, type ProjectSignalSchemas } from '@hilos/core'

/** Signal `type` for a converge (PHP `ChatSignalConstants::AUTH_CONVERGE`). */
export const AUTH_CONVERGE_SIGNAL = 'auth_converge'

/**
 * The converge payload: the step and intent the surface moves to, and the
 * identifier the move is ABOUT — a connection can only have been parked on one
 * address, so a converge naming another one is ignored rather than applied to
 * whatever is being typed now.
 *
 * `step` and `intent` stay plain strings rather than enums on purpose: a server
 * one deploy ahead may name a step this build has no screen for, and refusing
 * the whole frame at the parse boundary would be a worse answer than the view
 * ignoring a value it does not know. `code` is the semantic reason of a
 * rollback (`reservation_expired`, `password_already_changed`) and is absent
 * from the wire whenever the move is not one. The targeting `acceptKey` is kept
 * for validation fidelity though the reaction ignores it — WS_USER already
 * targets this connection.
 */
export const authConvergeSignalSchema = z.object({
  acceptKey: z.string(),
  identifier: z.string(),
  step: z.string(),
  intent: z.string(),
  code: z.string().nullable().default(null),
})

/** Typed converge payload (the schema's output). */
export type AuthConvergeSignalData = z.infer<typeof authConvergeSignalSchema>

/**
 * The project signal schema `createHilosConnection` merges so a converge parses
 * at the boundary before the auth surface applies it.
 */
export const AUTH_CONVERGE_SIGNAL_SCHEMAS: ProjectSignalSchemas = {
  [AUTH_CONVERGE_SIGNAL]: authConvergeSignalSchema,
}
