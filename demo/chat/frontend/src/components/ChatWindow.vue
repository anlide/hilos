<template>
  <div class="card">
    <div class="card-body p-0 overflow-auto min-vh-50" ref="messagesContainer">
      <div class="list-group list-group-flush">
        <div
          v-for="event in visibleEvents"
          :key="event.id || `event-${event.timestamp}-${event.userId}`"
          class="list-group-item border-0"
        >
          <MessageItem :event="event" />
        </div>
      </div>
      <div v-if="visibleEvents.length === 0" class="text-center text-muted p-5">
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
        <LoadingButton
          type="submit"
          variant="btn-primary"
          :loading="submitLoading"
          :disabled="!chatStore.isConnected || !message.trim()"
          :loading-delay="300"
        >
          Send
        </LoadingButton>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch, nextTick, onMounted } from 'vue'
import { useChatStore } from '@/stores'
import MessageItem from './MessageItem.vue'
import { LoadingButton } from '@hilos/sdk/components'

const chatStore = useChatStore()
const messagesContainer = ref<HTMLElement | null>(null)
const message = ref('')

/** Payload: message to send and done() to call when send is complete (e.g. after ws response) */
const emit = defineEmits<{
  send: [payload: { message: string; done: () => void }]
}>()

const submitLoading = ref(false)
const hiddenEventTypes = new Set(['user_online', 'user_offline'])
const visibleEvents = computed(() => chatStore.events.filter((event) => !hiddenEventTypes.has(event.type)))

const scrollToBottom = () => {
  nextTick(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
    }
  })
}

const handleSubmit = () => {
  if (!message.value.trim() || !chatStore.isConnected) return
  const text = message.value.trim()
  message.value = ''
  submitLoading.value = true
  emit('send', {
    message: text,
    done: () => {
      submitLoading.value = false
    },
  })
}

watch(() => visibleEvents.value.length, scrollToBottom)
onMounted(scrollToBottom)
</script>
