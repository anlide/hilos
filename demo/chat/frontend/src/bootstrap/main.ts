// demo-chat boot: configure the SDK from the project's connection, scopes, and
// page registry, then mount the Vue app. bootHilos (core) binds the session and
// page scopes, builds the navigator, opens the socket, and applies the URL; this
// module supplies the project inputs and provides the navigator so HilosView and
// HilosLink resolve the current route (docs/agents/frontend/bootstrap-structure.md).
import { bootHilos, createAuthGate } from '@hilos/core'
import { hilosRouterKey } from '@hilos/vue'
import { createApp } from 'vue'

import App from '../App.vue'
import { authGateKey } from '../auth/authGateKey'
import { bindOAuthAuthorizeRedirect } from '../auth/oauthLogin'
import { pageEntityTypes } from '../pages/entityTypes'
import { appName, pageTitles } from '../pages/pageTitles'
import { router } from '../pages/routes'
import { connection } from './connection'
import { currentUserId, scopes } from './session'

// The OAuth start reply (HIL-281): navigate the browser to the provider's
// authorize URL when the daemon answers `oauth_start` with the authorize signal.
// Registered before bootHilos opens the socket so the reply always has a handler.
bindOAuthAuthorizeRedirect()

const hilosRouter = bootHilos({
  connection,
  scopes,
  router,
  pageEntityTypes,
  pageTitles,
  appName,
})

// The auth gate (HIL-165): resume a 401'd page and close the sign-in modal when
// the session upgrades (currentUserId turns non-null), and open the modal on an
// action-level 401 as a safety net. The concrete surface is the project's
// AuthSurface, mounted by HilosView; App wires both to the outlet.
const authGate = createAuthGate({
  router: hilosRouter,
  currentUserId,
  actionErrors: connection,
})

const app = createApp(App, { authGate })
app.provide(hilosRouterKey, hilosRouter)
// Also provide the gate app-wide so a live page (the main composer banner) can
// inject it and open sign-in in place; App still receives it as a prop to wire
// the HilosView outlet.
app.provide(authGateKey, authGate)
app.mount('#app')
