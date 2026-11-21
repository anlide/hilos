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
// `actionErrors` is the action-error store the same connection fills: the
// profile's rename binding reads its refusal there.
//
// The one inbound signal the demo declares of its own is the cart (HIL-2206),
// merged through projectSchemas so it parses at the boundary. The sign-in ones
// the demo is answered by are the framework's and are merged by
// createHilosConnection itself, like every other framework signal.
import { createHilosConnection } from '@hilos/core/base'

import { CART_SIGNAL_SCHEMAS } from './cart.js'

export const { connection, actions, actionErrors } = createHilosConnection({
  url: import.meta.env.VITE_WS_URL,
  projectSchemas: { ...CART_SIGNAL_SCHEMAS },
})
