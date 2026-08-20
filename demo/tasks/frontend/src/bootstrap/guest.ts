// The visitor's display name (HIL-610): this demo's own answer to "who am I"
// for a browser that has no account. The framework handshake response answers
// the account question and answers it with nobody, so the name a guest is shown
// travels on a project signal of its own, declared here as a schema so it parses
// at the boundary before anything reacts to it.
//
// Kept pure of `./connection` — the connection arrives as a parameter — so that
// module can merge the schema without a cycle back through this one. It is a
// cross-cutting app-setup concern and therefore its own bootstrap file
// (docs/agents/frontend/bootstrap-structure.md).
//
// The signal name is byte-equal to the backend
// `TasksSignalConstants::GUEST_IDENTITY` constant.
import {
  createSignal,
  z,
  type HilosConnection,
  type ProjectSignal,
  type ProjectSignalSchemas,
  type ReadonlySignal,
  type Unsubscribe,
} from '@hilos/core'

/** Signal `type` for the guest identity (PHP `TasksSignalConstants::GUEST_IDENTITY`). */
export const GUEST_IDENTITY_SIGNAL = 'guest_identity'

/** The payload: the name the backend gave this browser's session. */
export const guestIdentitySignalSchema = z.object({
  name: z.string(),
})

/**
 * The project signal schema `createHilosConnection` merges so the guest identity
 * parses at the boundary.
 */
export const GUEST_SIGNAL_SCHEMAS: ProjectSignalSchemas = {
  [GUEST_IDENTITY_SIGNAL]: guestIdentitySignalSchema,
}

const guestName = createSignal('')

/**
 * The current guest's display name; empty while the session carries an account
 * (the backend sends no guest identity for one) and until the signal lands.
 */
export const currentGuestName: ReadonlySignal<string> = guestName

/**
 * Keep {@link currentGuestName} fed from the connection's guest identity signal.
 *
 * Called before `bootHilos` opens the socket, so the subscription is standing
 * when the handshake answers — the signal is sent ahead of the handshake
 * response, and a listener attached later would miss the only copy of it.
 *
 * @param connection The project's connection singleton.
 * @returns Unsubscribe for the registered signal handler.
 */
export function bindGuestIdentity(connection: HilosConnection): Unsubscribe {
  return connection.on('projectSignal', (signal: ProjectSignal) => {
    if (signal.type !== GUEST_IDENTITY_SIGNAL) {
      return
    }
    guestName.set(
      (signal.data as ReturnType<typeof guestIdentitySignalSchema.parse>).name,
    )
  })
}
