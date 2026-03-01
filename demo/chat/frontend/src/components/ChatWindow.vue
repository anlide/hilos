<template>
  <div class="card">
    <div class="card-body p-0 overflow-auto min-vh-50" ref="messagesContainer">
      <div class="list-group list-group-flush">
        <div
          v-for="event in visibleEvents"
          :key="event.id || `event-${event.timestamp}-${event.userId ?? event.botId ?? 'sys'}`"
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
          :value="displayMessage"
          @input="handleInput"
          type="text"
          class="form-control"
          placeholder="Type your message..."
          :readonly="chatStore.isModeratingMessage"
          :disabled="!chatStore.isConnected || chatStore.isModeratingMessage"
          required
          maxlength="500"
        />
        <LoadingButton
          type="submit"
          variant="btn-primary"
          :loading="chatStore.isModeratingMessage"
          :disabled="!chatStore.isConnected || chatStore.isModeratingMessage || !draftMessage.trim()"
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
const draftMessage = ref('')

const emit = defineEmits<{
  send: [message: string]
}>()

const hiddenEventTypes = new Set(['user_online', 'user_offline'])
const visibleEvents = computed(() => chatStore.events.filter((event) => !hiddenEventTypes.has(event.type)))
const currentUserModerationState = computed(() => chatStore.currentUserModerationState ?? null)
const displayMessage = computed(() => {
  if (currentUserModerationState.value !== null) {
    return currentUserModerationState.value
  }
  return draftMessage.value
})

const scrollToBottom = () => {
  nextTick(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
    }
  })
}

const handleSubmit = () => {
  if (!draftMessage.value.trim() || !chatStore.isConnected || chatStore.isModeratingMessage) return
  const text = draftMessage.value.trim()
  draftMessage.value = ''
  emit('send', text)
}

const handleInput = (event: Event) => {
  if (chatStore.isModeratingMessage) {
    return
  }
  const target = event.target as HTMLInputElement
  draftMessage.value = target.value
}

watch(() => visibleEvents.value.length, scrollToBottom)
onMounted(scrollToBottom)
</script>
