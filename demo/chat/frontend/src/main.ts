// demo-chat — an end project consuming the Hilos frontend SDK (@hilos/vue).
//
// This is a consumer, not a member of the SDK workspace: it pulls @hilos/vue
// the way any real Hilos project does. The chat application entry point lands
// here.

import { browserNavigationEnvironment, createHilosRouter } from '@hilos/core'
import { hilosRouterKey } from '@hilos/vue'
import { createApp } from 'vue'

import App from './App.vue'
import { connection } from './connection'
import { bindSessionScope, ensureSessionTokenCookie } from './session'
import { bindPageScope } from './pageScope'
import { router } from './pages'

// The token must be in place before the socket opens — it rides the
// handshake cookies.
ensureSessionTokenCookie()
bindSessionScope(connection)
const pages = bindPageScope(connection)
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
const app = createApp(App)
app.provide(hilosRouterKey, hilosRouter)
app.mount('#app')
