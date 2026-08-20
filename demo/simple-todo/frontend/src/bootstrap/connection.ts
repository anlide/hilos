// The todo application's single Hilos connection (the project's connection
// singleton). createHilosConnection (core) merges the framework session and page
// schemas and wires the stale-build reload; this file only states the project's
// endpoint policy.
//
// The endpoint defaults to the same-origin /ws route, which nginx proxies to the
// daemon in the test and production environments. The local dev stack overrides
// it with VITE_WS_URL, because there the page is served by the Vite dev server
// while the daemon publishes its own WebSocket port.
//
// `actions` is the requestId-correlated reply lifecycle: an admin modal such as
// the settings table's edit dialog calls `actions.dispatch(...)` and closes on
// the returned handle's resolved `done`.
import { createHilosConnection } from '@hilos/core'

import { GUEST_SIGNAL_SCHEMAS } from './guest'

export const { connection, actions } = createHilosConnection({
  url: import.meta.env.VITE_WS_URL,
  // The one inbound signal this project mounts: the display name of a session
  // that carries no account (HIL-610).
  projectSchemas: { ...GUEST_SIGNAL_SCHEMAS },
})
