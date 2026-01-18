import { createWebSocketPlugin } from '@hilos/sdk/plugins/websocket'
import { config } from '@/config'
import { useChatStore } from '@/stores'
import { localStorageService } from '@/services/LocalStorageService'
import { Event } from '@/types'

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
          // Not JSON, ignore
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
        
        // Handle subscription_response
        if (message.type === 'subscription_response') {
          const subscriptionData = message.data as {
            events: Array<{
              id: number
              type: string
              timestamp: number
              data: Record<string, unknown>
            }>
            startTime: number
            userId: number
            username: string
          }
          
          chatStore.handleSubscriptionResponse(
            subscriptionData.events,
            subscriptionData.startTime,
            subscriptionData.userId,
            subscriptionData.username,
          )
          return
        }
        
        // Handle chat event messages (new events from server)
        if (message.type === 'chat_event' || message.type === 'event') {
          const eventData = message.data as {
            id: number
            type: string
            timestamp: number
            data: Record<string, unknown>
          }
          
          const userId = (eventData.data.userId as number | null) ?? chatStore.currentUserId
          const timestampString = new Date(eventData.timestamp * 1000).toISOString().slice(0, 19).replace('T', ' ')
          
          const event = Event.fromObject({
            id: eventData.id,
            userId: userId,
            type: eventData.type,
            timestamp: timestampString,
            data: eventData.data,
          })
          
          chatStore.addEvent(event)
          return
        }
        
        // Handle other structured messages
        if (message.data !== undefined) {
          console.log('Received message:', message.type, message.data)
          // TODO: Handle different message types
        }
      }
    },
  })
  
  return plugin
}
