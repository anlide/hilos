<template>
  <div class="row">
    <div class="col-12 col-lg-8 mx-auto">
      <!-- Connection Status -->
      <ChatConnection v-if="!chatStore.isConnected" />
      
      <!-- Chat Interface -->
      <ChatWindow v-else @send="handleSendMessage" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { useChatStore } from '@/stores'
import { useWebSocket } from '@hilos/sdk/plugins/websocket'
import ChatConnection from '@/components/ChatConnection.vue'
import ChatWindow from '@/components/ChatWindow.vue'
import { sendAction } from '@/services/websocketActions'

const chatStore = useChatStore()
const websocket = useWebSocket()

const handleSendMessage = (message: string) => {
  sendAction(websocket, 'message', { content: message })
}
</script>
