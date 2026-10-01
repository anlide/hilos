// demo-binance-btc-tracker boot: configure the SDK from the project's connection,
// scopes, and page registry, then mount the Vue app. bootHilos (core) binds the
// session and page scopes, builds the navigator, opens the socket, and applies
// the URL; this module supplies the project inputs and provides the navigator so
// HilosView and HilosLink resolve the current route
// (docs/agents/frontend/bootstrap-structure.md).
import { bootHilos, createAuthGate } from '@hilos/core'
import { HILOS_VIEW_LAYER, hilosAuthGateKey, hilosRouterKey } from '@hilos/vue'
import { createApp } from 'vue'

import App from '../App.vue'
import { pageEntityTypes } from '../pages/entityTypes'
import { appName, pageTitles } from '../pages/pageTitles'
import { router } from '../pages/routes'
import { actions, connection } from './connection'
import { currentUserId, pendingAck, scopes } from './session'

const hilosRouter = bootHilos({
  viewLayer: HILOS_VIEW_LAYER,
  connection,
  actions,
  scopes,
  router,
  pageEntityTypes,
  pageTitles,
  appName,
  // Bind the notification center: this demo registers the framework notification
  // page and has real auth, so the bell in App's #user slot fills from the
  // per-user group once the handshake names the user.
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
// Also provide the gate app-wide, so a page can inject it and open sign-in in
// place; App still receives it as a prop to wire the HilosView outlet and the
// shell's Sign in button.
app.provide(hilosAuthGateKey, authGate)
app.mount('#app')
