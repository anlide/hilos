// The inbound passkey-options signal the daemon delivers WS_USER to the
// initiating connection (HIL-284), declared as a project signal schema so the
// parse boundary validates it before the ceremony driver reacts. Kept pure
// (schema + names only, no connection import) so `bootstrap/connection` can merge
// it without a cycle through `passkeyCeremony`, which drives the flow over it.
//
// A `passkey_*_options` action's `action_success` carries no domain payload, so
// the WebAuthn `publicKey` options and the signed challenge token ride this
// signal instead; the driver hands the options to `navigator.credentials` and
// returns the signed challenge on confirm (the "client action = loading + signal,
// never fire-forget" pattern). The `ceremony` discriminator says which ceremony
// (register / login) the options are for, so the driver matches the reply to its
// in-flight request. The name is byte-equal to the backend
// `HilosSignalConstants::HILOS_PASSKEY_OPTIONS` constant.
import { z } from 'zod'

import { type ProjectSignalSchemas } from '../protocol/parseSignal.js'

/** Signal `type` for the passkey options reply (PHP `HilosSignalConstants::HILOS_PASSKEY_OPTIONS`). */
export const PASSKEY_OPTIONS_SIGNAL = 'hilos_passkey_options'

/** Ceremony discriminator for a register reply (PHP `WebAuthnChallengeSigner::PURPOSE_REGISTER`). */
export const PASSKEY_CEREMONY_REGISTER = 'register'

/** Ceremony discriminator for a login reply (PHP `WebAuthnChallengeSigner::PURPOSE_LOGIN`). */
export const PASSKEY_CEREMONY_LOGIN = 'login'

/** Either ceremony, narrow enough that a caller cannot name a third one. */
export type PasskeyCeremony =
  | typeof PASSKEY_CEREMONY_LOGIN
  | typeof PASSKEY_CEREMONY_REGISTER

/**
 * The options-reply payload: the ceremony discriminator, the opaque WebAuthn
 * `publicKey` options wire shape (spec-defined keys, binary fields base64url —
 * kept as an unstructured record here and typed at the `navigator.credentials`
 * boundary by the passkey wrapper), and the signed challenge token to hand back
 * on confirm. The targeting `acceptKey` is carried for validation fidelity though
 * the reaction ignores it (WS_USER already targets this connection).
 */
export const passkeyOptionsSignalSchema = z.object({
  acceptKey: z.string(),
  ceremony: z.string(),
  publicKeyOptions: z.record(z.string(), z.unknown()),
  signedChallenge: z.string(),
})

/** Typed options-reply payload (the schema's output). */
export type PasskeyOptionsSignalData = z.infer<
  typeof passkeyOptionsSignalSchema
>

/**
 * The project signal schemas `createHilosConnection` merges so the passkey
 * options signal parses at the boundary before {@link passkeyCeremony} reacts.
 */
export const PASSKEY_SIGNAL_SCHEMAS: ProjectSignalSchemas = {
  [PASSKEY_OPTIONS_SIGNAL]: passkeyOptionsSignalSchema,
}
