// Note: Vue types are resolved by demo projects via tsconfig.app.json
// IDE may show errors here, but TypeScript compiler in demo projects will resolve them correctly
import type { App } from 'vue'
import { getCurrentInstance } from 'vue'
import type { WebSocketOptions } from '../types/websocket'
import { WebSocketService } from '../services/WebSocketService'

/**
 * Vue plugin factory for WebSocket management
 */
export function createWebSocketPlugin(options: WebSocketOptions) {
  const service = new WebSocketService(options)

  return {
    install(app: App) {
      // Provide service instance to app
      app.config.globalProperties.$websocket = service

      // Auto-connect if enabled
      if (options.autoConnect !== false) {
        service.connect()
      }
    },
    service,
  }
}

/**
 * Composable for using WebSocket service in components
 * Note: This requires the plugin to be installed first
 */
export function useWebSocket(): WebSocketService {
  // Get service from current app instance
  const app = getCurrentInstance()?.appContext.app
  if (app && '$websocket' in app.config.globalProperties) {
    return app.config.globalProperties.$websocket as WebSocketService
  }

  throw new Error('WebSocket plugin is not installed. Make sure to call app.use(createWebSocketPlugin(...)) in main.ts')
}

// Re-export types and service for convenience
export type { WebSocketOptions } from '../types/websocket'
export { WebSocketService } from '../services/WebSocketService'
