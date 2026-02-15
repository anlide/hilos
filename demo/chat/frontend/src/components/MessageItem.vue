<template>
  <!-- All events (messages and service messages) -->
  <div 
    class="d-flex flex-column mb-2 p-2 rounded" 
    :class="getServiceMessageClass()"
  >
    <div class="d-flex align-items-baseline gap-2">
      <RouterLink
        v-if="getHeaderUserName()"
        class="fw-bold text-decoration-none"
        :class="isServiceMessage ? 'text-secondary' : 'text-primary'"
        :to="{ name: 'user', params: { id: getHeaderUserId() } }"
      >
        {{ getHeaderUserName() }}
      </RouterLink>
      <span
        v-if="getServiceTitle()"
        class="fw-bold text-secondary"
      >
        {{ getServiceTitle() }}
      </span>
      <small class="text-muted">{{ formatTime(event.timestamp) }}</small>
    </div>
    <div v-if="getMessageText()" class="ms-3 mt-1">
      {{ getMessageText() }}
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { Event } from '@/types'
import { useChatStore } from '@/stores'

interface Props {
  event: Event
}

const props = defineProps<Props>()
const chatStore = useChatStore()

const isServiceMessage = computed(() => {
  return props.event.type !== 'message_sent'
})

const formatTime = (timestamp: string): string => {
  const date = new Date(timestamp)
  return date.toLocaleTimeString('en-US', { 
    hour: '2-digit', 
    minute: '2-digit',
    second: '2-digit'
  })
}

const getParticipantName = (userId: number | null): string => {
  if (userId === null) {
    return 'Unknown user'
  }
  const user = chatStore.users.find(u => u.id === userId)
  return user?.name || `User${userId}`
}

const getHeaderUserId = (): number | null => {
  if (props.event.type === 'user_renamed') {
    return null
  }

  return props.event.userId
}

const getHeaderUserName = (): string => {
  if (getHeaderUserId() === null) {
    return ''
  }

  return getParticipantName(getHeaderUserId())
}

const getServiceTitle = (): string => {
  if (!isServiceMessage.value) {
    return ''
  }

  switch (props.event.type) {
    case 'user_registered':
      return 'registered in chat'
    case 'user_renamed': {
      const { data } = props.event
      const oldName = data.oldName as string | undefined
      const newName = data.newName as string | undefined
      if (oldName && newName) {
        return `renamed from ${oldName} to ${newName}`
      }
      return 'renamed'
    }
    case 'chat_started':
      return 'Chat started'
    case 'chat_stopped':
      return 'Chat stopped'
    case 'chat_cleared':
      return 'Chat history cleared'
    default:
      return `Event: ${props.event.type}`
  }
}

const getMessageText = (): string => {
  // For regular messages, show the message content
  if (props.event.type === 'message_sent') {
    return (props.event.data.message as string) || ''
  }
  
  // For service messages, only show explicit message payloads
  if (props.event.data.message) {
    return props.event.data.message as string
  }

  return ''
}

const getServiceMessageClass = (): string => {
  if (!isServiceMessage.value) {
    return ''
  }
  
  // Return Bootstrap utility classes for background colors
  switch (props.event.type) {
    case 'user_registered':
      return 'bg-success bg-opacity-25'
    case 'user_renamed':
      return 'bg-info bg-opacity-25'
    case 'chat_started':
    case 'chat_stopped':
    case 'chat_cleared':
      return 'bg-primary bg-opacity-25'
    default:
      return 'bg-secondary bg-opacity-25'
  }
}
</script>
