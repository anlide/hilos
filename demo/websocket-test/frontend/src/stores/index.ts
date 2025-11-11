import { defineStore } from 'pinia'
import { ChatMessage, ChatNotification, type ChatEvent, type NotificationType } from '@/types'

/**
 * WebSocket chat store - uses base connection store pattern from framework
 */
export const useChatStore = defineStore('chat', {
  state: () => ({
    // Inherit base connection state
    connected: false,
    connecting: false,
    error: null as string | null,
    // Demo-specific state
    username: localStorage.getItem('chat_username') || 'User',
    messages: [] as ChatEvent[],
    reconnectAttempts: 0,
    maxReconnectAttempts: Infinity,
  }),
  
  getters: {
    // Inherit base getters
    isConnected(): boolean {
      return this.connected
    },
    isConnecting(): boolean {
      return this.connecting
    },
    hasError(): boolean {
      return this.error !== null
    },
  },
  
  actions: {
    // Inherit base actions
    setConnected(value: boolean) {
      this.connected = value
      if (value) {
        this.connecting = false
        this.error = null
        this.reconnectAttempts = 0
      }
    },
    
    setConnecting(value: boolean) {
      this.connecting = value
    },
    
    setError(error: string | null) {
      this.error = error
    },
    
    // Demo-specific actions
    setUsername(username: string) {
      this.username = username
      localStorage.setItem('chat_username', username)
    },
    
    addMessage(message: ChatEvent) {
      this.messages.push(message)
      // Keep last 1000 messages
      if (this.messages.length > 1000) {
        this.messages.shift()
      }
    },
    
    addNotification(content: string, type: NotificationType = 'user_joined') {
      const notification = new ChatNotification(
        `notif_${Date.now()}_${Math.random()}`,
        Date.now(),
        content,
        type
      )
      this.addMessage(notification)
    },
    
    addChatMessage(username: string, content: string) {
      const message = new ChatMessage(
        `msg_${Date.now()}_${Math.random()}`,
        Date.now(),
        username,
        content
      )
      this.addMessage(message)
    },
    
    clearMessages() {
      this.messages = []
    },
    
    incrementReconnectAttempts() {
      this.reconnectAttempts++
    },
  }
})
