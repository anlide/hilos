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
let pingTimer: number | null = null
let isReconnecting = false // Flag to prevent multiple simultaneous reconnection attempts
const reconnectDelay = 3000 // 3 seconds delay between reconnection attempts
const pingInterval = 40000 // 40 seconds in milliseconds

const connect = () => {
  if (ws?.readyState === WebSocket.OPEN) {
    return // Already connected
  }

  // Prevent multiple simultaneous connection attempts
  if (isReconnecting || (ws && ws.readyState === WebSocket.CONNECTING)) {
    return
  }

  chatStore.setConnecting(true)
  chatStore.setError(null)
  isReconnecting = false

  try {
    const url = `${config.websocketProtocol}://${config.websocketHost}:${config.websocketPort}`
    ws = new WebSocket(url)
    
    ws.onopen = () => {
      isReconnecting = false
      chatStore.setConnected(true)
      chatStore.setConnecting(false)
      chatStore.setError(null)
      chatStore.addNotification('Connected to server', 'user_joined')
      
      // Start ping interval
      startPingInterval()
      
      // Send initial username to server
      // TODO: Send username when server supports it
    }
    
    ws.onmessage = (event) => {
      try {
        // Handle incoming messages
        const data = event.data
        if (typeof data === 'string') {
          // Try to parse as JSON
          try {
            const message = JSON.parse(data)
            if (message.type && message.data !== undefined) {
              // Handle structured message
              console.log('Received message:', message.type, message.data)
              // TODO: Handle different message types
              // For now, just log it
            } else {
              // Fallback: display as raw message
              chatStore.addChatMessage('Server', data)
            }
          } catch (parseError) {
            // Not JSON, display as raw message
            chatStore.addChatMessage('Server', data)
          }
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
      
      // Stop ping interval
      stopPingInterval()
      
      // Clean up current connection
      ws = null
      
      // Only reconnect if not already reconnecting
      if (!isReconnecting) {
        chatStore.addNotification('Connection lost. Reconnecting...', 'connection_lost')
        
        // Attempt to reconnect
        scheduleReconnect()
      }
    }
  } catch (error) {
    chatStore.setError(`Failed to connect: ${error}`)
    chatStore.setConnecting(false)
    scheduleReconnect()
  }
}

const scheduleReconnect = () => {
  // Prevent multiple simultaneous reconnection attempts
  if (isReconnecting) {
    return
  }

  if (reconnectTimer) {
    clearTimeout(reconnectTimer)
    reconnectTimer = null
  }

  isReconnecting = true
  chatStore.incrementReconnectAttempts()
  
  reconnectTimer = window.setTimeout(() => {
    isReconnecting = false
    reconnectTimer = null
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

/**
 * Send WebSocket ping as simple text message
 */
const sendPing = () => {
  if (!ws || ws.readyState !== WebSocket.OPEN) {
    return
  }

  try {
    // Send simple text "ping" message
    ws.send('ping')
  } catch (error) {
    console.error('Error sending ping:', error)
  }
}

/**
 * Start ping interval - sends ping every 40 seconds
 */
const startPingInterval = () => {
  stopPingInterval() // Clear any existing interval
  
  pingTimer = window.setInterval(() => {
    sendPing()
  }, pingInterval)
}

/**
 * Stop ping interval
 */
const stopPingInterval = () => {
  if (pingTimer !== null) {
    clearInterval(pingTimer)
    pingTimer = null
  }
}

const disconnect = () => {
  if (reconnectTimer) {
    clearTimeout(reconnectTimer)
    reconnectTimer = null
  }
  
  stopPingInterval()
  
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
