// demo-chat — an end project consuming the Hilos frontend SDK (@hilos/vue).
//
// This is a consumer, not a member of the SDK workspace: it pulls @hilos/vue
// the way any real Hilos project does. The chat application entry point lands
// here.

import { createApp } from 'vue'

import App from './App.vue'
import { connection } from './connection'
import { bindSessionScope, ensureSessionTokenCookie } from './session'

// The token must be in place before the socket opens — it rides the
// handshake cookies.
ensureSessionTokenCookie()
bindSessionScope(connection)
connection.connect()
createApp(App).mount('#app')
