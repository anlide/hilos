// The application's single Hilos connection (the project's connection
// singleton). createHilosConnection (core) merges the framework session and page
// schemas, attaches the action-error store and the action lifecycle, and wires
// the stale-build reload; this file only states the project's endpoint policy.
//
// The endpoint defaults to the same-origin /ws route, which nginx proxies to the
// daemon in test and production, and the Vite dev server proxies in local dev.
// VITE_WS_URL is kept for environments where the WebSocket lives on a separate
// hostname.
//
// `actions` is the requestId-correlated reply lifecycle: a modal submit calls
// `actions.dispatch(...)` and closes on the returned handle's resolved `done`.
//
// No projectSchemas: the demo declares no inbound signal of its own. The sign-in
// ones it is answered by are the framework's and are merged by
// createHilosConnection itself, like every other framework signal.
import { createHilosConnection } from '@hilos/core'

export const { connection, actions } = createHilosConnection({
  url: import.meta.env.VITE_WS_URL,
})
