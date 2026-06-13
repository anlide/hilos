// The chat application's single Hilos connection — one WebSocket drives the
// whole SPA (docs/agents/frontend/core-and-connection.md).
//
// The endpoint defaults to the same-origin /ws route, which nginx proxies to
// the daemon in the test and production environments. The local dev stack
// overrides it with VITE_WS_URL, because there the page is served by the Vite
// dev server while the daemon publishes its own WebSocket port.
import { ActionErrorStore, HilosConnection } from '@hilos/core'

import { chatSignalSchemas } from './session'
import { pageSignalSchemas } from './pages'

const sameOriginUrl = `${location.protocol === 'https:' ? 'wss' : 'ws'}://${location.host}/ws`

export const connection = new HilosConnection({
  url: import.meta.env.VITE_WS_URL ?? sameOriginUrl,
  projectSchemas: { ...chatSignalSchemas, ...pageSignalSchemas },
})

// `action_error` is a framework signal, so the core ActionErrorStore (not the
// app) turns it into reactive per-action error state; pages read the actions
// they care about through it.
export const actionErrors = new ActionErrorStore(connection)

// Forced-refresh check (docs/agents/frontend/wire-protocol.md): a welcome
// carrying a different build than the latched one means this bundle is stale —
// reload to pick up the new build. The build-bump pipeline lands at step 7.10.
connection.on('buildMismatch', () => {
  location.reload()
})
