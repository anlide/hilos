<template>
  <div class="row g-3">
    <div v-if="!chatStore.isConnected" class="col-12 col-lg-8 mx-auto">
      <ChatConnection />
    </div>
    <template v-else>
      <div class="col-12 col-lg-8">
        <ChatWindow @send="handleSendMessage" />
      </div>
      <div class="col-12 col-lg-4 ms-lg-auto">
        <OnlineUsersList />
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { useChatStore } from '@/stores'
import { useWebSocket } from '@hilos/sdk/plugins/websocket'
import ChatConnection from '@/components/ChatConnection.vue'
import ChatWindow from '@/components/ChatWindow.vue'
import OnlineUsersList from '@/components/OnlineUsersList.vue'
import { sendAction } from '@/services/websocketActions'

const chatStore = useChatStore()
const websocket = useWebSocket()

const handleSendMessage = (message: string) => {
  sendAction(websocket, 'message', { content: message })
}
</script>
