// The profile set-password success signal the daemon delivers WS_USER to every
// one of the user's connections (HIL-402), declared as a project signal schema so
// the parse boundary validates it before a reaction runs. Kept pure (schema + name
// only, no connection import) so `bootstrap/connection` can merge it without a
// cycle through the profile actions that react to it.
//
// A password change rewrites only the never-projected secret, so nothing in the
// identity projection moves to confirm it — success rides this explicit signal
// instead. `mode` distinguishes a first-time add from a change so the profile can
// word its confirmation. The name is byte-equal to the backend
// `ChatSignalConstants::PASSWORD_UPDATED` constant.
import { z, type ProjectSignalSchemas } from '@hilos/core'

/** Signal `type` for the set-password success (PHP `ChatSignalConstants::PASSWORD_UPDATED`). */
export const PASSWORD_UPDATED_SIGNAL = 'password_updated'

/** `mode` marking a first-time password add (PHP `PasswordUpdatedSignalData::MODE_ADDED`). */
export const PASSWORD_MODE_ADDED = 'added'

/** `mode` marking a change of an existing password (PHP `PasswordUpdatedSignalData::MODE_CHANGED`). */
export const PASSWORD_MODE_CHANGED = 'changed'

/**
 * The success payload: whether the password was added or changed, so the profile
 * can word its confirmation toast. Any unknown mode falls back to "changed".
 */
export const passwordUpdatedSignalSchema = z.object({
  mode: z
    .enum([PASSWORD_MODE_ADDED, PASSWORD_MODE_CHANGED])
    .catch(PASSWORD_MODE_CHANGED),
})

/** Typed set-password success payload (the schema's output). */
export type PasswordUpdatedSignalData = z.infer<
  typeof passwordUpdatedSignalSchema
>

/**
 * The project signal schema `createHilosConnection` merges so the set-password
 * success signal parses at the boundary before the profile reacts to it.
 */
export const PASSWORD_SIGNAL_SCHEMAS: ProjectSignalSchemas = {
  [PASSWORD_UPDATED_SIGNAL]: passwordUpdatedSignalSchema,
}
