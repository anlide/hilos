// The project's HilosAuthContext: binds the framework sign-in surface
// (@hilos/vue HilosAuthSurface) to this project's connection, scope stores, and
// action lifecycle. The framework owns the machine, the wire, the screens and the
// copy; the project supplies only where the data lives and how a code is sent —
// and, on its backend, the handlers.
//
// Which ways in the surface offers is not declared here (HIL-427): the backend's
// method directory names what this project wired — the password — and the set
// reaches the surface with the handshake.
import {
  createHilosAuthContext,
  type CodeChannelDescriptor,
  type HilosAuthContext,
} from '@hilos/core'

import { actions, connection } from '../bootstrap/connection'
import { scopes } from '../bootstrap/session'

// No code channel: a registration and a recovery confirm the address by mail,
// which is the framework's own and needs no descriptor here.
const channels: readonly CodeChannelDescriptor[] = []

/** This project's context for the framework sign-in surface. */
export const hilosAuthContext: HilosAuthContext = createHilosAuthContext({
  connection,
  scopes,
  actions,
  channels,
})
