// demo-simple-todo boot: configure the SDK from the project's connection,
// scopes, and page registry, then mount the React app. bootHilos (core) binds
// the session and page scopes, builds the navigator, opens the socket, and
// applies the URL; this module supplies the project inputs and provides the
// navigator through HilosRouterContext (docs/agents/frontend/bootstrap-structure.md).
import { bootHilos } from '@hilos/core'
import { HilosRouterContext } from '@hilos/react'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'

import App from '../App'
import { router } from '../pages/routes'
import { connection } from './connection'
import { scopes } from './session'

const hilosRouter = bootHilos({ connection, scopes, router })

createRoot(document.getElementById('app')!).render(
  <StrictMode>
    <HilosRouterContext.Provider value={hilosRouter}>
      <App />
    </HilosRouterContext.Provider>
  </StrictMode>,
)
