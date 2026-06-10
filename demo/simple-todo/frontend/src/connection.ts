// The todo application's single Hilos connection — one WebSocket drives the
// whole SPA (docs/agents/frontend/core-and-connection.md).
//
// The endpoint defaults to the same-origin /ws route, overridable with
// VITE_WS_URL. The demo ships no backend yet, so no environment answers it:
// the Connection machine honestly cycles connecting → reconnecting until the
// demo backend lands at step 7, which is exactly what the conformance slice
// shows.
import { HilosConnection } from '@hilos/core'

const sameOriginUrl = `${location.protocol === 'https:' ? 'wss' : 'ws'}://${location.host}/ws`

export const connection = new HilosConnection({
  url: import.meta.env.VITE_WS_URL ?? sameOriginUrl,
})

// Forced-refresh check (docs/agents/frontend/wire-protocol.md): a welcome
// carrying a different build than the latched one means this bundle is stale —
// reload to pick up the new build. The build-bump pipeline lands at step 7.10.
connection.on('buildMismatch', () => {
  location.reload()
})
