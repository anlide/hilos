<template>
  <!-- Message event -->
  <div v-if="event.type === 'message_sent'" class="d-flex flex-column mb-2">
    <div class="d-flex align-items-baseline gap-2">
      <span class="fw-bold text-primary">{{ getUserName(event.userId) }}</span>
      <small class="text-muted">{{ formatTime(event.timestamp) }}</small>
    </div>
    <div class="ms-3">{{ getMessageText() }}</div>
  </div>
  
  <!-- Notification events -->
  <div v-else class="text-center my-2">
    <small :class="getNotificationClass()">
      <i :class="getNotificationIcon()"></i>
      {{ getNotificationText() }}
    </small>
  </div>
</template>

<script setup lang="ts">
import { Event } from '@/types'
import { useChatStore } from '@/stores'

interface Props {
  event: Event
}

const props = defineProps<Props>()
const chatStore = useChatStore()

const formatTime = (timestamp: string): string => {
  const date = new Date(timestamp)
  return date.toLocaleTimeString('en-US', { 
    hour: '2-digit', 
    minute: '2-digit',
    second: '2-digit'
  })
}

const getUserName = (userId: number): string => {
  const user = chatStore.users.find(u => u.id === userId)
  return user?.name || `User${userId}`
}

const getMessageText = (): string => {
  return (props.event.data.message as string) || '(No message)'
}

const getNotificationText = (): string => {
  const { type, data } = props.event
  const userName = getUserName(props.event.userId)
  
  switch (type) {
    case 'user_joined':
      return `${userName} joined the chat`
    case 'user_left':
      return `${userName} left the chat`
    case 'user_renamed': {
      const oldName = data.oldName as string | undefined
      const newName = data.newName as string | undefined
      if (oldName && newName) {
        return `${oldName} renamed to ${newName}`
      }
      return `${userName} renamed`
    }
    case 'chat_created':
      return 'Chat created'
    case 'chat_cleared':
      return 'Chat history cleared'
    default:
      return `Event: ${type}`
  }
}

const getNotificationClass = (): string => {
  const base = 'badge '
  switch (props.event.type) {
    case 'user_joined':
      return base + 'bg-success'
    case 'user_left':
      return base + 'bg-secondary'
    case 'user_renamed':
      return base + 'bg-info'
    case 'chat_created':
    case 'chat_cleared':
      return base + 'bg-primary'
    default:
      return base + 'bg-secondary'
  }
}

const getNotificationIcon = (): string => {
  switch (props.event.type) {
    case 'user_joined':
      return 'bi bi-person-plus'
    case 'user_left':
      return 'bi bi-person-dash'
    case 'user_renamed':
      return 'bi bi-pencil'
    case 'chat_created':
      return 'bi bi-chat-dots'
    case 'chat_cleared':
      return 'bi bi-trash'
    default:
      return 'bi bi-info-circle'
  }
}
</script>
