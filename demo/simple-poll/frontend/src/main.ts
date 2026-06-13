// demo-simple-poll — an end project consuming the Hilos frontend SDK
// (@hilos/angular).
//
// This is a consumer, not a member of the SDK workspace: it pulls
// @hilos/angular the way any real Hilos project does — through the canonical
// Angular CLI toolchain — and doubles as the Angular conformance demo
// (docs/agents/frontend/multiframework-core.md). The poll application entry
// point lands here.

import { mergeApplicationConfig } from '@angular/core'
import { bootstrapApplication } from '@angular/platform-browser'
import { HILOS_ROUTER } from '@hilos/angular'
import {
  bindPageScope,
  browserNavigationEnvironment,
  createHilosRouter,
} from '@hilos/core'

import { App } from './app/app'
import { appConfig } from './app/app.config'
import { connection } from './app/connection'
import { router } from './app/pages/routes'
import {
  bindSessionScope,
  ensureSessionTokenCookie,
  scopes,
} from './app/session'

// The token must be in place before the socket opens — it rides the
// handshake cookies.
ensureSessionTokenCookie()
bindSessionScope(connection)
const pages = bindPageScope(connection, scopes)
// The navigator drives the page subscription from the URL: it subscribes the
// page named by the location on start, and re-subscribes over the live socket
// on every in-place navigation (HilosLink / back-forward), so moving between
// pages never reconnects. It is provided to the app so HilosView and HilosLink
// resolve and drive the current route.
const hilosRouter = createHilosRouter(
  router,
  pages,
  browserNavigationEnvironment(),
)
connection.connect()
hilosRouter.start()
bootstrapApplication(
  App,
  mergeApplicationConfig(appConfig, {
    providers: [{ provide: HILOS_ROUTER, useValue: hilosRouter }],
  }),
).catch((error: unknown) => {
  console.error(error)
})
