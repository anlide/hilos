// The chat application's single Hilos connection (the project's connection
// singleton). createHilosConnection (core) merges the framework session and page
// schemas, attaches the action-error store and the action lifecycle, and wires
// the stale-build reload; this file only states the project's endpoint policy
// and any project signals.
//
// The endpoint defaults to the same-origin /ws route, which nginx proxies to the
// daemon in the test and production environments. The local dev stack overrides
// it with VITE_WS_URL, because there the page is served by the Vite dev server
// while the daemon publishes its own WebSocket port.
//
// `actions` is the requestId-correlated reply lifecycle: a modal submit calls
// `actions.dispatch(...)` and closes on the returned handle's resolved `done`.
import {
  AUTH_CODE_SIGNAL_SCHEMAS,
  AUTH_CONVERGE_SIGNAL_SCHEMAS,
  createHilosConnection,
  OAUTH_SIGNAL_SCHEMAS,
  PASSKEY_SIGNAL_SCHEMAS,
} from '@hilos/core'

import { PASSWORD_SIGNAL_SCHEMAS } from '../auth/passwordSignals'

export const { connection, actionErrors, actions } = createHilosConnection({
  url: import.meta.env.VITE_WS_URL,
  // The inbound signals this project mounts: the four auth ones the framework
  // owns and declares — the OAuth login start-reply and failure/timeout
  // (HIL-281), the passkey ceremony options (HIL-284), the phone code-request
  // outcome (HIL-492), the auth-converge step change (HIL-415) — plus the
  // project's own profile set-password success (HIL-402). All arrive WS_USER.
  projectSchemas: {
    ...AUTH_CODE_SIGNAL_SCHEMAS,
    ...AUTH_CONVERGE_SIGNAL_SCHEMAS,
    ...OAUTH_SIGNAL_SCHEMAS,
    ...PASSKEY_SIGNAL_SCHEMAS,
    ...PASSWORD_SIGNAL_SCHEMAS,
  },
})
