<template>
  <div v-if="message.type === 'message'" class="d-flex flex-column mb-2">
    <div class="d-flex align-items-baseline gap-2">
      <span class="fw-bold text-primary">{{ message.username }}</span>
      <small class="text-muted">{{ formatTime(message.timestamp) }}</small>
    </div>
    <div class="ms-3">{{ message.content }}</div>
  </div>
  
  <div v-else class="text-center my-2">
    <small 
      :class="getNotificationClass(message.notificationType)"
    >
      <i :class="getNotificationIcon(message.notificationType)"></i>
      {{ message.content }}
    </small>
  </div>
</template>

<script setup lang="ts">
import type { ChatMessage } from '@/types'

interface Props {
  message: ChatMessage
}

defineProps<Props>()

const formatTime = (timestamp: number): string => {
  const date = new Date(timestamp)
  return date.toLocaleTimeString('en-US', { 
    hour: '2-digit', 
    minute: '2-digit',
    second: '2-digit'
  })
}

const getNotificationClass = (type?: ChatMessage['notificationType']): string => {
  const base = 'badge '
  switch (type) {
    case 'user_joined':
      return base + 'bg-success'
    case 'user_left':
      return base + 'bg-secondary'
    case 'connection_lost':
      return base + 'bg-danger'
    case 'user_renamed':
      return base + 'bg-info'
    default:
      return base + 'bg-secondary'
  }
}

const getNotificationIcon = (type?: ChatMessage['notificationType']): string => {
  switch (type) {
    case 'user_joined':
      return 'bi bi-person-plus'
    case 'user_left':
      return 'bi bi-person-dash'
    case 'connection_lost':
      return 'bi bi-exclamation-triangle'
    case 'user_renamed':
      return 'bi bi-pencil'
    default:
      return 'bi bi-info-circle'
  }
}
</script>

