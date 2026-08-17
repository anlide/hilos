// demo-chat boot: configure the SDK from the project's connection, scopes, and
// page registry, then mount the Vue app. bootHilos (core) binds the session and
// page scopes, builds the navigator, opens the socket, and applies the URL; this
// module supplies the project inputs and provides the navigator so HilosView and
// HilosLink resolve the current route (docs/agents/frontend/bootstrap-structure.md).
import { bootHilos, createAuthGate, createOAuthLogin } from '@hilos/core'
import { hilosAuthGateKey, hilosRouterKey } from '@hilos/vue'
import { createApp } from 'vue'

import App from '../App.vue'
import { hilosAuthContext } from '../auth/hilosAuthContext'
import { pageEntityTypes } from '../pages/entityTypes'
import { appName, pageTitles } from '../pages/pageTitles'
import { router } from '../pages/routes'
import { connection } from './connection'
import { currentUserId, pendingAck, scopes } from './session'

// The framework OAuth client, bound to this project's context. Its redirect and
// session-ready state lives in the framework module, so the bindings registered
// here answer the trips the surfaces start.
const oauth = createOAuthLogin(hilosAuthContext)

// The OAuth start reply (HIL-281): navigate the browser to the provider's
// authorize URL when the daemon answers `hilos_oauth_start` with the authorize signal.
// Registered before bootHilos opens the socket so the reply always has a handler.
oauth.bindOAuthAuthorizeRedirect()

// The OAuth callback's session-ready gate (HIL-281): latch the session handshake
// so the /auth/callback relay holds its `hilos_oauth_callback` dispatch until the daemon
// has registered this cold-loaded connection against its session. Registered
// before bootHilos opens the socket so the first handshake response is never missed.
oauth.bindSessionReady()

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
  // Bind the notification center: chat registers the framework notification page
  // and has real auth, so the bell in App's #user slot fills from the per-user
  // group once the handshake names the user.
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

const app = createApp(App, { authGate })
app.provide(hilosRouterKey, hilosRouter)
// Also provide the gate app-wide so a live page (the main composer banner) can
// inject it and open sign-in in place; App still receives it as a prop to wire
// the HilosView outlet.
app.provide(hilosAuthGateKey, authGate)
app.mount('#app')
