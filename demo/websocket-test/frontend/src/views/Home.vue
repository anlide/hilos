<template>
  <div class="container-fluid py-4">
    <div class="row">
      <div class="col-12 col-lg-8 mx-auto">
        <h1 class="h2 mb-4 text-center">WebSocket Chat Demo</h1>
        
        <!-- WebSocket Manager (always active, handles auto-connect) -->
        <WebSocketManager ref="wsManager" />
        
        <!-- Connection Status -->
        <ChatConnection v-if="!chatStore.isConnected" />
        
        <!-- Chat Interface -->
        <div v-else>
          <UsernameForm />
          <ChatWindow />
          <MessageForm @send="handleSendMessage" />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useChatStore } from '@/stores'
import ChatConnection from '@/components/ChatConnection.vue'
import ChatWindow from '@/components/ChatWindow.vue'
import UsernameForm from '@/components/UsernameForm.vue'
import MessageForm from '@/components/MessageForm.vue'
import WebSocketManager from '@/components/WebSocketManager.vue'

const chatStore = useChatStore()
const wsManager = ref<InstanceType<typeof WebSocketManager> | null>(null)

const handleSendMessage = (message: string) => {
  wsManager.value?.sendMessage(message)
}
</script>
