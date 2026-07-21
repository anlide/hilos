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
import { createHilosConnection } from '@hilos/core'

import { OAUTH_SIGNAL_SCHEMAS } from '../auth/oauthSignals'
import { PASSKEY_SIGNAL_SCHEMAS } from '../auth/passkeySignals'

export const { connection, actionErrors, actions } = createHilosConnection({
  url: import.meta.env.VITE_WS_URL,
  // The project's own inbound signals: the OAuth login start-reply and
  // failure/timeout (HIL-281) and the passkey ceremony options (HIL-284) the
  // daemon delivers WS_USER.
  projectSchemas: { ...OAUTH_SIGNAL_SCHEMAS, ...PASSKEY_SIGNAL_SCHEMAS },
})
