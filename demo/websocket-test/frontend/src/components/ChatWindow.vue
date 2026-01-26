<template>
  <div class="card">
    <div class="card-body p-0 overflow-auto min-vh-50" ref="messagesContainer">
      <div class="list-group list-group-flush">
        <div
          v-for="event in chatStore.events"
          :key="event.id || `event-${event.timestamp}-${event.userId}`"
          class="list-group-item border-0"
        >
          <MessageItem :event="event" />
        </div>
      </div>
      <div v-if="chatStore.events.length === 0" class="text-center text-muted p-5">
        <p class="mb-0">No events yet. Start chatting!</p>
      </div>
    </div>
    <div class="card-footer">
      <form @submit.prevent="handleSubmit" class="d-flex gap-2">
        <input
          v-model="message"
          type="text"
          class="form-control"
          placeholder="Type your message..."
          :disabled="!chatStore.isConnected"
          required
          maxlength="500"
        />
        <button 
          type="submit" 
          class="btn btn-primary"
          :disabled="!chatStore.isConnected || !message.trim()"
        >
          Send
        </button>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch, nextTick, onMounted } from 'vue'
import { useChatStore } from '@/stores'
import MessageItem from './MessageItem.vue'

const chatStore = useChatStore()
const messagesContainer = ref<HTMLElement | null>(null)
const message = ref('')

const emit = defineEmits<{
  send: [message: string]
}>()

const scrollToBottom = () => {
  nextTick(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
    }
  })
}

const handleSubmit = () => {
  if (message.value.trim() && chatStore.isConnected) {
    emit('send', message.value.trim())
    message.value = ''
  }
}

watch(() => chatStore.events.length, scrollToBottom)
onMounted(scrollToBottom)
</script>
