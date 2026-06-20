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
import { router } from '../pages/routes'
import { connection } from './connection'
import { scopes } from './session'

const hilosRouter = bootHilos({ connection, scopes, router, pageEntityTypes })

bootstrapApplication(
  App,
  mergeApplicationConfig(appConfig, {
    providers: [{ provide: HILOS_ROUTER, useValue: hilosRouter }],
  }),
).catch((error: unknown) => {
  console.error(error)
})
