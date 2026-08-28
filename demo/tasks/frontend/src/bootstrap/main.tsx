// demo-tasks boot: configure the SDK from the project's connection,
// scopes, and page registry, then mount the React app. bootHilos (core) binds
// the session and page scopes, builds the navigator, opens the socket, and
// applies the URL; this module supplies the project inputs and provides the
// navigator through HilosRouterContext (docs/agents/frontend/bootstrap-structure.md).
import { bootHilos, createAuthGate, createOAuthLogin } from '@hilos/core'
import { HilosAuthGateContext, HilosRouterContext } from '@hilos/react'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'

import App from '../App'
import { hilosAuthContext } from '../auth/hilosAuthContext'
import { pageEntityTypes } from '../pages/entityTypes'
import { appName, pageTitles } from '../pages/pageTitles'
import { router } from '../pages/routes'
import { connection } from './connection'
import { bindGuestIdentity } from './guest'
import { currentUserId, pendingAck, scopes } from './session'

// Before bootHilos, which opens the socket: the guest identity is sent ahead of
// the handshake response, so a listener attached after the boot would miss it.
bindGuestIdentity(connection)

// The framework OAuth client, bound to this project's context. Its redirect state
// lives in the framework module, so the bindings registered here answer the trips
// the surfaces start. The readiness the callback relay waits on is not among them:
// since HIL-607 that is the shared page-ready gate, which bootHilos binds itself.
const oauth = createOAuthLogin(hilosAuthContext)

// The OAuth trip's bindings (HIL-281, HIL-633): put the authorize URL the daemon
// answers `hilos_oauth_start` with into the provider window, take the return the
// callback window couriers back, and notice a window the person closed by hand.
// Registered before bootHilos opens the socket so the reply always has a handler.
oauth.bindOAuthTrip()

// The OAuth email-collision link replay (HIL-282): once a collision re-auth
// upgrades the session (currentUserId turns non-null) with a pending link armed,
// redeem the link token to bind the OAuth identity. Bound here, outside any
// component, so it survives the sign-in surface unmounting when the gate closes on
// the upgrade. Registered before bootHilos opens the socket.
oauth.bindOAuthLinkReplay(currentUserId)

const hilosRouter = bootHilos({
  connection,
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
