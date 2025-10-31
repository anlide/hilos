import { defineStore } from 'pinia'
import type { ChatMessage } from '@/types'

/**
 * WebSocket chat store
 */
export const useChatStore = defineStore('chat', {
  state: () => ({
    connected: false,
    connecting: false,
    username: localStorage.getItem('chat_username') || 'User',
    messages: [] as ChatMessage[],
    error: null as string | null,
    reconnectAttempts: 0,
    maxReconnectAttempts: Infinity,
  }),
  
  getters: {
    isConnected: (state) => state.connected,
    isConnecting: (state) => state.connecting,
    hasError: (state) => state.error !== null,
  },
  
  actions: {
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
    
    setUsername(username: string) {
      this.username = username
      localStorage.setItem('chat_username', username)
    },
    
    addMessage(message: ChatMessage) {
      this.messages.push(message)
      // Keep last 1000 messages
      if (this.messages.length > 1000) {
        this.messages.shift()
      }
    },
    
    addNotification(content: string, type: ChatMessage['notificationType'] = 'user_joined') {
      const notification: ChatMessage = {
        id: `notif_${Date.now()}_${Math.random()}`,
        type: 'notification',
        timestamp: Date.now(),
        content,
        notificationType: type,
      }
      this.addMessage(notification)
    },
    
    addChatMessage(username: string, content: string) {
      const message: ChatMessage = {
        id: `msg_${Date.now()}_${Math.random()}`,
        type: 'message',
        timestamp: Date.now(),
        username,
        content,
      }
      this.addMessage(message)
    },
    
    clearMessages() {
      this.messages = []
    },
    
    setError(error: string | null) {
      this.error = error
    },
    
    incrementReconnectAttempts() {
      this.reconnectAttempts++
    },
  }
})
