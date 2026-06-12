// The poll application's single Hilos connection — one WebSocket drives the
// whole SPA (docs/agents/frontend/core-and-connection.md).
//
// The endpoint is the same-origin /ws route in every environment: nginx
// proxies it to the daemon in test and production, and the Angular CLI dev
// server proxies it via proxy.conf.json (Angular has no import.meta.env
// mechanism for a URL override, unlike the Vite demos).
import { HilosConnection } from '@hilos/core'

import { pollSignalSchemas } from './session'
import { pageSignalSchemas } from './pages'

const sameOriginUrl = `${location.protocol === 'https:' ? 'wss' : 'ws'}://${location.host}/ws`

export const connection = new HilosConnection({
  url: sameOriginUrl,
  projectSchemas: { ...pollSignalSchemas, ...pageSignalSchemas },
})

// Forced-refresh check (docs/agents/frontend/wire-protocol.md): a welcome
// carrying a different build than the latched one means this bundle is stale —
// reload to pick up the new build. The build-bump pipeline lands at step 7.10.
connection.on('buildMismatch', () => {
  location.reload()
})
