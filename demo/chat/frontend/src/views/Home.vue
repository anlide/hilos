<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div class="col-12 col-lg-8 d-flex flex-column h-100 min-h-0">
      <ChatWindow @send="handleSendMessage" />
    </div>
    <div class="col-12 col-lg-4 ms-lg-auto d-flex flex-column h-100 min-h-0 participants-column">
      <OnlineUsersList />
    </div>
  </div>
</template>

<script setup lang="ts">
import { useWebSocket } from '@hilos/sdk/plugins/websocket'
import ChatWindow from '@/components/ChatWindow.vue'
import OnlineUsersList from '@/components/OnlineUsersList.vue'
import { sendAction } from '@/services/websocketActions'

const websocket = useWebSocket()

const handleSendMessage = (message: string) => {
  sendAction(websocket, 'message', { content: message })
}
</script>

<style scoped>
.min-h-0 {
  min-height: 0;
}

@media (max-width: 991.98px) {
  .participants-column {
    max-height: 35dvh;
  }
}
</style>
