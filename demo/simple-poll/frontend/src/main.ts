// demo-simple-poll — an end project consuming the Hilos frontend SDK
// (@hilos/angular).
//
// This is a consumer, not a member of the SDK workspace: it pulls
// @hilos/angular the way any real Hilos project does — through the canonical
// Angular CLI toolchain — and doubles as the Angular conformance demo
// (docs/agents/frontend/multiframework-core.md). The poll application entry
// point lands here.

import { bootstrapApplication } from '@angular/platform-browser'

import { App } from './app/app'
import { appConfig } from './app/app.config'
import { connection } from './app/connection'
import { bindSessionScope, ensureSessionTokenCookie } from './app/session'

// The token must be in place before the socket opens — it rides the
// handshake cookies.
ensureSessionTokenCookie()
bindSessionScope(connection)
connection.connect()
bootstrapApplication(App, appConfig).catch((error: unknown) => {
  console.error(error)
})
