import { createApp } from 'vue'
import { createPinia } from 'pinia'
import router from './router'
import { createChatWebSocketPlugin } from './plugins/websocket'
import 'bootstrap/dist/css/bootstrap.min.css'
import 'bootstrap-icons/font/bootstrap-icons.css'
import App from './App.vue'

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(createChatWebSocketPlugin())

app.mount('#app')
