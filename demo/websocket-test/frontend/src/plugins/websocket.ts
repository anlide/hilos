import { createWebSocketPlugin } from '@hilos/sdk/plugins/websocket'
import { config } from '@/config'
import { useChatStore } from '@/stores'

/**
 * WebSocket plugin configuration for chat application
 * This is a project-specific implementation of the universal WebSocket plugin
 */
export function createChatWebSocketPlugin() {
  const websocketUrl = `${config.websocketProtocol}://${config.websocketHost}:${config.websocketPort}`
  
  return createWebSocketPlugin({
    url: websocketUrl,
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
      
      if (typeof data === 'string') {
        // Try to parse as JSON
        try {
          const message = JSON.parse(data)
          if (message.type && message.data !== undefined) {
            // Handle structured message
            console.log('Received message:', message.type, message.data)
            // TODO: Handle different message types
          } else {
            // Fallback: display as raw message
            chatStore.addChatMessage('Server', data)
          }
        } catch (parseError) {
          // Not JSON, display as raw message
          chatStore.addChatMessage('Server', data)
        }
      } else if (typeof data === 'object' && data !== null) {
        // Already parsed JSON
        const message = data as { type?: string; data?: unknown }
        if (message.type && message.data !== undefined) {
          console.log('Received message:', message.type, message.data)
        } else {
          chatStore.addChatMessage('Server', JSON.stringify(data))
        }
      }
    },
  })
}
