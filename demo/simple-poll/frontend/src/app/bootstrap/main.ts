// demo-simple-poll boot: configure the SDK from the project's connection,
// scopes, and page registry, then bootstrap the Angular app. bootHilos (core)
// binds the session and page scopes, builds the navigator, opens the socket, and
// applies the URL; this module supplies the project inputs and provides the
// navigator as HILOS_ROUTER (docs/agents/frontend/bootstrap-structure.md).
import { mergeApplicationConfig } from '@angular/core'
import { bootstrapApplication } from '@angular/platform-browser'
import { HILOS_ROUTER } from '@hilos/angular'
import { bootHilos } from '@hilos/core'

import { App } from '../app'
import { appConfig } from '../app.config'
import { pageEntityTypes } from '../pages/entityTypes'
import { appName, pageTitles } from '../pages/pageTitles'
import { router } from '../pages/routes'
import { connection } from './connection'
import { bindGuestIdentity } from './guest'
import { scopes } from './session'

// Before bootHilos, which opens the socket: the guest identity is sent ahead of
// the handshake response, so a listener attached after the boot would miss it.
bindGuestIdentity(connection)

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

bootstrapApplication(
  App,
  mergeApplicationConfig(appConfig, {
    providers: [{ provide: HILOS_ROUTER, useValue: hilosRouter }],
  }),
).catch((error: unknown) => {
  console.error(error)
})
