import { createWebSocketPlugin } from '@hilos/sdk/plugins/websocket'
import { config } from '@/config'
import { useChatStore } from '@/stores'
import { localStorageService } from '@/services/LocalStorageService'

/**
 * WebSocket plugin configuration for chat application
 * This is a project-specific implementation of the universal WebSocket plugin
 */
export function createChatWebSocketPlugin() {
  const websocketUrl = `${config.websocketProtocol}://${config.websocketHost}:${config.websocketPort}`
  
  // Get session token with initialization (always returns non-null)
  const sessionToken = localStorageService.getSessionWithInit()
  
  const plugin = createWebSocketPlugin({
    url: websocketUrl,
    queryParams: {
      'X-Session-Token': sessionToken,
    },
    autoConnect: true,
    reconnectDelay: 3000,
    pingInterval: 40000,
    pingMessage: 'ping',
    onOpen: () => {
      const chatStore = useChatStore()
      chatStore.setConnected(true)
      chatStore.setConnecting(false)
      chatStore.setError(null)
      chatStore.addNotification('Connected to server', 'user_joined')
    },
    onClose: () => {
      const chatStore = useChatStore()
      chatStore.setConnected(false)
      chatStore.setConnecting(false)
    },
    onError: () => {
      const chatStore = useChatStore()
      chatStore.setError('Connection error occurred')
      chatStore.setConnecting(false)
    },
    onMessage: (data: string | object) => {
      const chatStore = useChatStore()
      
      let message: { type?: string; data?: unknown; content?: unknown } | null = null
      
      if (typeof data === 'string') {
        // Try to parse as JSON
        try {
          message = JSON.parse(data)
        } catch (parseError) {
          // Not JSON, display as raw message
          chatStore.addChatMessage('Server', data)
          return
        }
      } else if (typeof data === 'object' && data !== null) {
        // Already parsed JSON
        message = data as { type?: string; data?: unknown; content?: unknown }
      }
      
      if (message && message.type) {
        // Handle session response
        if (message.type === 'session') {
          const sessionToken = message.content as string | null
          if (sessionToken) {
            localStorageService.setSession(sessionToken)
          }
          return
        }
        
        // Handle other structured messages
        if (message.data !== undefined) {
          console.log('Received message:', message.type, message.data)
          // TODO: Handle different message types
        } else {
          chatStore.addChatMessage('Server', JSON.stringify(message))
        }
      } else {
        chatStore.addChatMessage('Server', JSON.stringify(data))
      }
    },
  })
  
  return plugin
}
