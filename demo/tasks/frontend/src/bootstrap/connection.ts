// The tasks application's single Hilos connection (the project's connection
// singleton). createHilosConnection (core) merges the framework session and page
// schemas and wires the stale-build reload; this file only states the project's
// endpoint policy.
//
// The endpoint defaults to the same-origin /ws route, which nginx proxies to the
// daemon in test and production, and the Vite dev server proxies in local dev.
// VITE_WS_URL is kept for environments where the WebSocket lives on a separate
// hostname, such as the preview stack (docker/docker-compose.preview.yml).
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
