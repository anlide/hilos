// demo-chat — an end project consuming the Hilos frontend SDK (@hilos/vue).
//
// This is a consumer, not a member of the SDK workspace: it pulls @hilos/vue
// the way any real Hilos project does. The chat application entry point lands
// here.

import { createApp } from 'vue'

import App from './App.vue'
import { connection } from './connection'
import { bindSessionScope, ensureSessionTokenCookie } from './session'
import { bindPageScope } from './pageScope'
import { matchPage } from './pages'

// The token must be in place before the socket opens — it rides the
// handshake cookies.
ensureSessionTokenCookie()
bindSessionScope(connection)
const pages = bindPageScope(connection)
connection.connect()
// The URL is the source of truth for the page subscription on cold load; the
// manager sends page_subscribe once the connection reaches `connected`, with
// any route params the matched page template captured.
const route = matchPage(location.pathname)
pages.subscribe(route.page, route.params)
createApp(App).mount('#app')
