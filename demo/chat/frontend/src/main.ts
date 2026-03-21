import { ViteSSG } from 'vite-ssg'
import { createPinia } from 'pinia'
import { createChatWebSocketPlugin } from './plugins/websocket'
import { createWebSocketSSRStub } from './plugins/websocket-ssr-stub'
import { localStorageService } from './services/LocalStorageService'
import { initBootstrapTheme } from './theme/bootstrapTheme'
import 'bootstrap/dist/css/bootstrap.min.css'
import 'bootstrap-icons/font/bootstrap-icons.css'
import App from './App.vue'
import { demoRoutes } from '@/router/routes'

/** Static routes allowed for prerender (SSG) */
const PRERENDER_STATIC_PATHS = new Set(['', 'licence', 'terms', 'privacy', 'agents'])

/** user/1, bot/42, etc. (numeric id segment) */
const isUserOrBotProfilePath = (path: string): boolean => /^user\/\d+$/.test(path) || /^bot\/\d+$/.test(path)

export async function includedRoutes(paths: string[]) {
  return paths.filter(
    (path) =>
      !path.includes(':') &&
      (PRERENDER_STATIC_PATHS.has(path) || isUserOrBotProfilePath(path))
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
      initBootstrapTheme()
      app.use(createChatWebSocketPlugin() as Parameters<typeof app.use>[0])
    }
  }
)
