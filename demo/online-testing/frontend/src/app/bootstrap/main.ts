// demo-online-testing boot: configure the SDK from the project's connection,
// scopes, and page registry, then bootstrap the Angular app. bootHilos (core)
// binds the session and page scopes, builds the navigator, opens the socket, and
// applies the URL; this module supplies the project inputs and provides the
// navigator as HILOS_ROUTER (docs/agents/frontend/bootstrap-structure.md).
import { mergeApplicationConfig } from '@angular/core'
import { bootstrapApplication } from '@angular/platform-browser'
import {
  HILOS_VIEW_LAYER,
  HILOS_AUTH_GATE,
  HILOS_ROUTER,
} from '@hilos/angular'
import { bootHilos, createAuthGate } from '@hilos/core'

import { App } from '../app.js'
import { appConfig } from '../app.config.js'
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
  // The ack holds the resume (HIL-422): a flow that ends by signing somebody in
  // has a sentence left to say, and un-gating on the upgrade alone would close
  // the surface over it. It also opens the surface on its own, which is how the
  // other window of the same session gets told.
  pendingAck,
})

bootstrapApplication(
  App,
  mergeApplicationConfig(appConfig, {
    providers: [
      { provide: HILOS_ROUTER, useValue: hilosRouter },
      // Provided app-wide, not passed down: the surface HilosView mounts takes no
      // inputs, so it reads the gate from here, and a live page can open sign-in
      // in place by injecting the same token.
      { provide: HILOS_AUTH_GATE, useValue: authGate },
    ],
  }),
).catch((error: unknown) => {
  console.error(error)
})
