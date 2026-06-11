// demo-simple-todo — an end project consuming the Hilos frontend SDK
// (@hilos/react).
//
// This is a consumer, not a member of the SDK workspace: it pulls @hilos/react
// the way any real Hilos project does, and doubles as the React conformance
// demo (docs/agents/frontend/multiframework-core.md). The todo application
// entry point lands here.

import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'

import App from './App'
import { connection } from './connection'
import { bindSessionScope, ensureSessionTokenCookie } from './session'

// The token must be in place before the socket opens — it rides the
// handshake cookies.
ensureSessionTokenCookie()
bindSessionScope(connection)
connection.connect()
createRoot(document.getElementById('app')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
