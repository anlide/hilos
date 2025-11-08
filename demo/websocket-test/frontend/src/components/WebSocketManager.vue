<template>
  <!-- Component handles WebSocket connection logic -->
</template>

<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue'
import { useChatStore } from '@/stores'
import { config } from '@/config'

const chatStore = useChatStore()

let ws: WebSocket | null = null
let reconnectTimer: number | null = null
const reconnectDelay = 500 // Fixed 500ms delay between reconnection attempts

const connect = () => {
  if (ws?.readyState === WebSocket.OPEN) {
    return // Already connected
  }

  chatStore.setConnecting(true)
  chatStore.setError(null)

  try {
    const url = `${config.websocketProtocol}://${config.websocketHost}:${config.websocketPort}`
    ws = new WebSocket(url)
    
    ws.onopen = () => {
      chatStore.setConnected(true)
      chatStore.setConnecting(false)
      chatStore.setError(null)
      chatStore.addNotification('Connected to server', 'user_joined')
      
      // Send initial username to server
      // TODO: Send username when server supports it
    }
    
    ws.onmessage = (event) => {
      try {
        // Handle incoming messages
        // TODO: Parse server messages when protocol is defined
        const data = event.data
        if (typeof data === 'string') {
          // For now, just display raw messages
          chatStore.addChatMessage('Server', data)
        }
      } catch (error) {
        console.error('Error processing message:', error)
      }
    }
    
    ws.onerror = (error) => {
      console.error('WebSocket error:', error)
      chatStore.setError('Connection error occurred')
      chatStore.setConnecting(false)
    }
    
    ws.onclose = () => {
      chatStore.setConnected(false)
      chatStore.setConnecting(false)
      
      if (ws) {
        chatStore.addNotification('Connection lost. Reconnecting...', 'connection_lost')
      }
      
      // Attempt to reconnect
      scheduleReconnect()
    }
  } catch (error) {
    chatStore.setError(`Failed to connect: ${error}`)
    chatStore.setConnecting(false)
    scheduleReconnect()
  }
}

const scheduleReconnect = () => {
  if (reconnectTimer) {
    clearTimeout(reconnectTimer)
  }

  chatStore.incrementReconnectAttempts()
  
  reconnectTimer = window.setTimeout(() => {
    connect()
  }, reconnectDelay)
}

const sendMessage = (message: string) => {
  if (ws?.readyState === WebSocket.OPEN) {
    ws.send(JSON.stringify({
      type: 'message',
      username: chatStore.username,
      content: message,
    }))
    chatStore.addChatMessage(chatStore.username, message)
  } else {
    chatStore.setError('Not connected to server')
  }
}

const disconnect = () => {
  if (reconnectTimer) {
    clearTimeout(reconnectTimer)
    reconnectTimer = null
  }
  
  if (ws) {
    ws.close()
    ws = null
  }
  
  chatStore.setConnected(false)
  chatStore.setConnecting(false)
}

// Auto-connect on mount
onMounted(() => {
  connect()
})

// Cleanup on unmount
onUnmounted(() => {
  disconnect()
})

// Expose sendMessage for parent components
defineExpose({
  sendMessage,
})
</script>

