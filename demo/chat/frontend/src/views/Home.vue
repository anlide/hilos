<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <noscript>
      <div class="col-12 py-5 text-center">
        <p class="lead">Please enable JavaScript to use the chat.</p>
      </div>
    </noscript>
    <div class="col-12 col-lg-8 d-flex flex-column h-100 min-h-0">
      <ChatWindow @send="handleSendMessage" />
    </div>
    <div class="col-12 col-lg-4 ms-lg-auto d-flex flex-column h-100 min-h-0 participants-column">
      <OnlineUsersList />
    </div>
  </div>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'
import { useWebSocket } from '@hilos/sdk/plugins/websocket'

useHead({
  title: 'Chat Hilos Demo',
  meta: [
    {
      name: 'description',
      content: 'Demo WebSocket chat interface for real-time messaging, moderation, and user management.'
    }
  ]
})
import ChatWindow from '@/components/ChatWindow.vue'
import OnlineUsersList from '@/components/OnlineUsersList.vue'
import { sendAction } from '@/services/websocketActions'

const websocket = useWebSocket()

type SendMessagePayload = {
  content: string
  attachmentDraftIds: string[]
}

const handleSendMessage = (payload: SendMessagePayload) => {
  sendAction(websocket, 'message', payload)
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
