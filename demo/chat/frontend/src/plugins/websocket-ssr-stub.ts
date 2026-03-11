/**
 * Stub WebSocket plugin for SSR/SSG.
 * Provides a no-op service so components using useWebSocket() don't throw.
 */
import type { App } from 'vue'

const stubService = {
  connect: () => {},
  disconnect: () => {},
  send: (_data: unknown) => {},
  get isConnected(): boolean {
    return false
  }
}

export function createWebSocketSSRStub() {
  return {
    install(app: App) {
      app.config.globalProperties.$websocket = stubService
    }
  }
}
