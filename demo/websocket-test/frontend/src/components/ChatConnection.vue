<template>
  <div class="card">
    <div class="card-body">
      <div v-if="chatStore.isConnecting" class="text-center">
        <div class="spinner-border text-primary" role="status">
          <span class="visually-hidden">Connecting...</span>
        </div>
        <p class="mt-3 mb-0">Connecting to server...</p>
      </div>
      <div v-else-if="!chatStore.isConnected" class="text-center">
        <div class="spinner-border text-warning" role="status">
          <span class="visually-hidden">Reconnecting...</span>
        </div>
        <p class="mt-3 mb-0">Reconnecting... (attempt {{ chatStore.reconnectAttempts }})</p>
        <p v-if="chatStore.error" class="text-danger small mt-2">{{ chatStore.error }}</p>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useChatStore } from '@/stores'

const chatStore = useChatStore()
</script>

