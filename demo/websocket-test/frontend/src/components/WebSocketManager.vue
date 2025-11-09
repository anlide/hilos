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
const reconnectDelay = 500 // Fixed 500ms delay between reconnection attempts
const pingInterval = 40000 // 40 seconds in milliseconds

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
      
      // Start ping interval
      startPingInterval()
      
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
      
      // Stop ping interval
      stopPingInterval()
      
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

/**
 * Send WebSocket ping frame with opCode = 0x9
 * 
 * Browser WebSocket API doesn't allow direct control of opCode, so we create
 * a ping frame manually and send it as binary data. The server recognizes
 * ping frames inside binary payloads and handles them as OPCODE_PING.
 */
const sendPing = () => {
  if (!ws || ws.readyState !== WebSocket.OPEN) {
    return
  }

  try {
    // Try to use ping() method if available (Node.js ws library, etc.)
    if (typeof (ws as any).ping === 'function') {
      ;(ws as any).ping()
      return
    }

    // Create WebSocket ping frame manually (RFC 6455)
    // Structure: [0x89 (FIN+PING), 0x80 (MASK+len0), mask_key_4_bytes]
    const frame = new Uint8Array(6)
    frame[0] = 0x89 // FIN (0x80) | PING opCode (0x09)
    frame[1] = 0x80 // MASK (0x80) | payload length 0
    
    // Generate 4-byte masking key (required for client-to-server frames)
    const mask = crypto.getRandomValues(new Uint8Array(4))
    frame[2] = mask[0] ?? 0
    frame[3] = mask[1] ?? 0
    frame[4] = mask[2] ?? 0
    frame[5] = mask[3] ?? 0
    
    // Send as binary - server will detect ping frame structure and handle as OPCODE_PING
    ws.send(frame.buffer)
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
