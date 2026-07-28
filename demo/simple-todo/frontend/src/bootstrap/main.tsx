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
import { pageEntityTypes } from '../pages/entityTypes'
import { appName, pageTitles } from '../pages/pageTitles'
import { router } from '../pages/routes'
import { connection } from './connection'
import { scopes } from './session'

const hilosRouter = bootHilos({
  connection,
  scopes,
  router,
  pageEntityTypes,
  pageTitles,
  appName,
  // Register the notification center so the bell in the shell's user slot fills
  // once a user is known; this demo is anonymous, so it stays an empty no-op
  // until auth arrives, exercising the wiring end-to-end all the same.
  notifications: true,
})

createRoot(document.getElementById('app')!).render(
  <StrictMode>
    <HilosRouterContext.Provider value={hilosRouter}>
      <App />
    </HilosRouterContext.Provider>
  </StrictMode>,
)
