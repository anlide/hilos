// demo-chat boot: configure the SDK from the project's connection, scopes, and
// page registry, then mount the Vue app. bootHilos (core) binds the session and
// page scopes, builds the navigator, opens the socket, and applies the URL; this
// module supplies the project inputs and provides the navigator so HilosView and
// HilosLink resolve the current route (docs/agents/frontend/bootstrap-structure.md).
import { bootHilos } from '@hilos/core'
import { hilosRouterKey } from '@hilos/vue'
import { createApp } from 'vue'

import App from '../App.vue'
import { pageEntityTypes } from '../pages/entityTypes'
import { appName, pageTitles } from '../pages/pageTitles'
import { router } from '../pages/routes'
import { connection } from './connection'
import { scopes } from './session'

const hilosRouter = bootHilos({
  connection,
  scopes,
  router,
  pageEntityTypes,
  pageTitles,
  appName,
})

const app = createApp(App)
app.provide(hilosRouterKey, hilosRouter)
app.mount('#app')
