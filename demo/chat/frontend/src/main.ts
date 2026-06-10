// demo-chat — an end project consuming the Hilos frontend SDK (@hilos/vue).
//
// This is a consumer, not a member of the SDK workspace: it pulls @hilos/vue
// the way any real Hilos project does. The chat application entry point lands
// here.

import { createApp } from 'vue'

import App from './App.vue'
import { connection } from './connection'

connection.connect()
createApp(App).mount('#app')
