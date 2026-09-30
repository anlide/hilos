// The online-testing demo's HilosAuthContext: binds the framework sign-in
// surface (@hilos/angular HilosAuthSurface) to this project's connection, scope
// stores, and action lifecycle. The framework owns the machine, the wire, the
// screens and the copy; the project supplies only where the data lives and how a
// code is sent — and, on its backend, the handlers.
//
// Which ways in the surface offers is not declared here (HIL-427): the backend's
// method directory names what this project wired — the password and nothing
// else — and the set reaches the surface with the handshake.
import { createHilosAuthContext, type HilosAuthContext } from '@hilos/core'

import { actions, connection } from '../bootstrap/connection.js'
import { scopes } from '../bootstrap/session.js'

/** This project's context for the framework sign-in surface. */
export const hilosAuthContext: HilosAuthContext = createHilosAuthContext({
  connection,
  scopes,
  actions,
  // No code delivery channel: a registration and a recovery confirm the address
  // by a code the framework mails, and the phone channels are not wired here.
  channels: [],
})
