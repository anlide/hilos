// demo-ecommerce-shop boot: configure the SDK from the project's connection,
// scopes, and page registry, then mount the React app. bootHilos (core) binds
// the session and page scopes, builds the navigator, opens the socket, and
// applies the URL; this module supplies the project inputs and provides the
// navigator through HilosRouterContext (docs/agents/frontend/bootstrap-structure.md).
import { bootHilos, createAuthGate } from '@hilos/core'
import {
  HILOS_VIEW_LAYER,
  HilosAuthGateContext,
  HilosRouterContext,
} from '@hilos/react'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'

import App from '../App.js'
import { pageEntityTypes } from '../pages/entityTypes.js'
import { appName, pageTitles } from '../pages/pageTitles.js'
import { router } from '../pages/routes.js'
import { actions, connection } from './connection.js'
import { currentUserId, pendingAck, scopes } from './session.js'

const hilosRouter = bootHilos({
  viewLayer: HILOS_VIEW_LAYER,
  connection,
  actions,
  scopes,
  router,
  pageEntityTypes,
  pageTitles,
  appName,
  // Register the notification center so the bell in the shell's user slot fills
  // once a user is known — which, since sign-in was activated here (HIL-623), is
  // as soon as somebody signs in.
  notifications: true,
})

// The auth gate (HIL-165): resume a 401'd page and close the sign-in modal when
// the session upgrades (currentUserId turns non-null), and open the modal on an
// action-level 401 as a safety net. The concrete surface is the project's
// AuthSurface, mounted by HilosView; App wires both to the outlet.
const authGate = createAuthGate({
  router: hilosRouter,
  currentUserId,
  actionErrors: connection,
  // The ack holds the resume (HIL-422): a flow that ends by signing somebody in
  // has a sentence left to say, and un-gating on the upgrade alone would close
  // the surface over it. It also opens the surface on its own, which is how the
  // other window of the same session gets told.
  pendingAck,
})

createRoot(document.getElementById('app')!).render(
  <StrictMode>
    <HilosRouterContext.Provider value={hilosRouter}>
      {/* Provided app-wide, not passed down: the surface HilosView mounts takes
          no props, so it reads the gate from here, and a live page can open
          sign-in in place by reading the same context. */}
      <HilosAuthGateContext.Provider value={authGate}>
        <App authGate={authGate} />
      </HilosAuthGateContext.Provider>
    </HilosRouterContext.Provider>
  </StrictMode>,
)
