import { ViteSSG } from 'vite-ssg'
import { createPinia } from 'pinia'
import { createChatWebSocketPlugin } from './plugins/websocket'
import { createWebSocketSSRStub } from './plugins/websocket-ssr-stub'
import { localStorageService } from './services/LocalStorageService'
import 'bootstrap/dist/css/bootstrap.min.css'
import 'bootstrap-icons/font/bootstrap-icons.css'
import App from './App.vue'
import { demoRoutes } from '@/router/routes'

/** Routes to prerender (exclude dynamic :id routes) */
export async function includedRoutes(paths: string[]) {
  return paths.filter(
    (path) =>
      !path.startsWith('user') &&
      !path.startsWith('bot') &&
      !path.includes('/user/') &&
      !path.includes('/bot/')
  )
}

export const createApp = ViteSSG(
  App,
  { routes: demoRoutes },
  ({ app, initialState }) => {
    const pinia = createPinia()
    app.use(pinia)

    if (import.meta.env.SSR) {
      initialState.pinia = pinia.state.value as Record<string, unknown>
      app.use(createWebSocketSSRStub())
    } else {
      pinia.state.value = (initialState.pinia as Record<string, unknown>) ?? {}
      localStorageService.init()
      app.use(createChatWebSocketPlugin() as Parameters<typeof app.use>[0])
    }
  }
)
