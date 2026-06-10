// The poll application's single Hilos connection — one WebSocket drives the
// whole SPA (docs/agents/frontend/core-and-connection.md).
//
// The endpoint is the same-origin /ws route. The demo ships no backend yet,
// so no environment answers it: the Connection machine honestly cycles
// connecting → reconnecting until the demo backend lands at step 7, which is
// exactly what the conformance slice shows.
import { HilosConnection } from '@hilos/core'

const sameOriginUrl = `${location.protocol === 'https:' ? 'wss' : 'ws'}://${location.host}/ws`

export const connection = new HilosConnection({
  url: sameOriginUrl,
})

// Forced-refresh check (docs/agents/frontend/wire-protocol.md): a welcome
// carrying a different build than the latched one means this bundle is stale —
// reload to pick up the new build. The build-bump pipeline lands at step 7.10.
connection.on('buildMismatch', () => {
  location.reload()
})
