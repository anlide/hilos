<template>
  <div class="card h-100 d-flex flex-column">
    <div class="card-body p-0 overflow-auto flex-grow-1" ref="messagesContainer">
      <div v-if="!chatStore.isConnected" class="list-group list-group-flush p-3 placeholder-glow">
        <div
          v-for="i in 6"
          :key="i"
          class="d-flex flex-column gap-2 mb-3"
          :class="{ 'align-items-end': i % 3 === 0 }"
        >
          <span class="placeholder" :class="i % 3 === 0 ? 'col-4' : 'col-7'"></span>
          <span class="placeholder col-5" v-if="i % 2 === 1"></span>
        </div>
      </div>
      <template v-else>
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
      </template>
    </div>
    <div class="card-footer flex-shrink-0">
      <form @submit.prevent="handleSubmit" class="d-flex gap-2 align-items-center">
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
        <span
          v-if="isRateLimited"
          class="text-muted user-select-none"
          style="min-width: 2.5rem; font-variant-numeric: tabular-nums"
        >
          {{ rateLimitSecondsLeft }}s
        </span>
        <LoadingButton
          type="submit"
          variant="btn-primary"
          :loading="chatStore.isModeratingMessage"
          :disabled="!chatStore.isConnected || chatStore.isModeratingMessage || isRateLimited || !draftMessage.trim()"
          :loading-delay="300"
        >
          Send
        </LoadingButton>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { useChatStore } from '@/stores'
import { MESSAGE_RATE_LIMIT_SECONDS } from '@/constants'
import MessageItem from './MessageItem.vue'
import { LoadingButton } from '@hilos/sdk/components'

const chatStore = useChatStore()
const messagesContainer = ref<HTMLElement | null>(null)
const draftMessage = ref('')
const rateLimitSecondsLeft = ref(0)
let rateLimitInterval: ReturnType<typeof setInterval> | null = null

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
const isRateLimited = computed(() => rateLimitSecondsLeft.value > 0)

const scrollToBottom = () => {
  nextTick(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
    }
  })
}

const startRateLimitCountdown = () => {
  rateLimitSecondsLeft.value = MESSAGE_RATE_LIMIT_SECONDS
  if (rateLimitInterval) clearInterval(rateLimitInterval)
  rateLimitInterval = setInterval(() => {
    rateLimitSecondsLeft.value--
    if (rateLimitSecondsLeft.value <= 0 && rateLimitInterval) {
      clearInterval(rateLimitInterval)
      rateLimitInterval = null
    }
  }, 1000)
}

const handleSubmit = () => {
  if (!draftMessage.value.trim() || !chatStore.isConnected || chatStore.isModeratingMessage || isRateLimited.value) return
  const text = draftMessage.value.trim()
  draftMessage.value = ''
  emit('send', text)
  startRateLimitCountdown()
}

onUnmounted(() => {
  if (rateLimitInterval) clearInterval(rateLimitInterval)
})

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
