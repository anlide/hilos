// The chat's HilosAuthContext: binds the framework sign-in surface
// (@hilos/vue HilosAuthSurface) to this project's connection, scope stores, and
// action lifecycle. The framework owns the machine, the wire, the screens and the
// copy; the project supplies only where the data lives and how a code is sent —
// and, on its backend, the handlers.
//
// Which ways in the surface offers is not declared here (HIL-427): the backend's
// method directory names what this project wired, an administrator narrows it on
// the sign-in methods screen, and the set reaches the surface with the handshake.
import {
  createHilosAuthContext,
  SMS_CODE_CHANNEL,
  TELEGRAM_CODE_CHANNEL,
  type CodeChannelDescriptor,
  type HilosAuthContext,
} from '@hilos/core'

import { actions, connection } from '../bootstrap/connection'
import { scopes } from '../bootstrap/session'

/** Where this deployment serves the terms the consent screen links to. */
const TERMS_PATH = '/terms'

/** Where this deployment serves the privacy policy the consent screen links to. */
const PRIVACY_PATH = '/privacy'

// The project's ORDERED code delivery channels (HIL-492): adding one is a
// descriptor here and a setting on the backend, never an edit inside the surface.
const channels: readonly CodeChannelDescriptor[] = [
  SMS_CODE_CHANNEL,
  TELEGRAM_CODE_CHANNEL,
]

/** This project's context for the framework sign-in surface. */
export const hilosAuthContext: HilosAuthContext = createHilosAuthContext({
  connection,
  scopes,
  actions,
  channels,
  termsPath: TERMS_PATH,
  privacyPath: PRIVACY_PATH,
})
