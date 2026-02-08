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
import { watch } from 'vue'
import { useChatStore } from '@/stores'
import { useWebSocket } from '@hilos/sdk/plugins/websocket'
import ChatConnection from '@/components/ChatConnection.vue'
import ChatWindow from '@/components/ChatWindow.vue'
import { sendAction } from '@/services/websocketActions'

const chatStore = useChatStore()
const websocket = useWebSocket()

/** Called when send completes (after ws response / new event); clears Send button loading */
let sendDone: (() => void) | null = null

const handleSendMessage = (payload: { message: string; done: () => void }) => {
  sendDone = payload.done
  sendAction(websocket, 'message', { content: payload.message })
}

// When a new event appears (e.g. message_sent from server), consider send complete
watch(
  () => chatStore.events.length,
  () => {
    if (sendDone) {
      sendDone()
      sendDone = null
    }
  }
)
</script>
