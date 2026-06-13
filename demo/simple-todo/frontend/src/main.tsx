// demo-simple-todo — an end project consuming the Hilos frontend SDK
// (@hilos/react).
//
// This is a consumer, not a member of the SDK workspace: it pulls @hilos/react
// the way any real Hilos project does, and doubles as the React conformance
// demo (docs/agents/frontend/multiframework-core.md). The todo application
// entry point lands here.

import {
  bindPageScope,
  browserNavigationEnvironment,
  createHilosRouter,
} from '@hilos/core'
import { HilosRouterContext } from '@hilos/react'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'

import App from './App'
import { connection } from './connection'
import { bindSessionScope, ensureSessionTokenCookie, scopes } from './session'
import { router } from './pages/routes'

// The token must be in place before the socket opens — it rides the
// handshake cookies.
ensureSessionTokenCookie()
bindSessionScope(connection)
const pages = bindPageScope(connection, scopes)
// The navigator drives the page subscription from the URL: it subscribes the
// page named by the location on start, and re-subscribes over the live socket
// on every in-place navigation (HilosLink / back-forward), so moving between
// pages never reconnects. It is provided to the app so HilosView and HilosLink
// resolve and drive the current route.
const hilosRouter = createHilosRouter(
  router,
  pages,
  browserNavigationEnvironment(),
)
connection.connect()
hilosRouter.start()
createRoot(document.getElementById('app')!).render(
  <StrictMode>
    <HilosRouterContext.Provider value={hilosRouter}>
      <App />
    </HilosRouterContext.Provider>
  </StrictMode>,
)
