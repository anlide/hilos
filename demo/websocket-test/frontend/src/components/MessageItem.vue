<template>
  <div v-if="message.type === 'message'" class="d-flex flex-column mb-2">
    <div class="d-flex align-items-baseline gap-2">
      <span class="fw-bold text-primary">{{ (message as ChatMessage).username }}</span>
      <small class="text-muted">{{ formatTime(message.timestamp) }}</small>
    </div>
    <div class="ms-3">{{ (message as ChatMessage).content }}</div>
  </div>
  
  <div v-else-if="message.type === 'notification'" class="text-center my-2">
    <small 
      :class="getNotificationClass((message as ChatNotification).notificationType)"
    >
      <i :class="getNotificationIcon((message as ChatNotification).notificationType)"></i>
      {{ (message as ChatNotification).content }}
    </small>
  </div>
</template>

<script setup lang="ts">
import { ChatMessage, ChatNotification } from '@/types'
import type { ChatEvent, NotificationType } from '@/types'

interface Props {
  message: ChatEvent
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

const getNotificationClass = (type?: NotificationType): string => {
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

const getNotificationIcon = (type?: NotificationType): string => {
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
