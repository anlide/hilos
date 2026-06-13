// The todo application's single Hilos connection (the project's connection
// singleton). createHilosConnection (core) merges the framework session and page
// schemas and wires the stale-build reload; this file only states the project's
// endpoint policy.
//
// The endpoint defaults to the same-origin /ws route, which nginx proxies to the
// daemon in the test and production environments. The local dev stack overrides
// it with VITE_WS_URL, because there the page is served by the Vite dev server
// while the daemon publishes its own WebSocket port.
import { createHilosConnection } from '@hilos/core'

export const { connection } = createHilosConnection({
  url: import.meta.env.VITE_WS_URL,
})
