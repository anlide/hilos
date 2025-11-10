<template>
  <div class="card">
    <div class="card-header bg-primary text-white">
      <h5 class="mb-0">Chat</h5>
    </div>
    <div class="card-body p-0 overflow-auto min-vh-50" ref="messagesContainer">
      <div class="list-group list-group-flush">
        <div
          v-for="message in chatStore.messages"
          :key="message.id"
          class="list-group-item border-0"
        >
          <MessageItem :message="message" />
        </div>
      </div>
      <div v-if="chatStore.messages.length === 0" class="text-center text-muted p-5">
        <p class="mb-0">No messages yet. Start chatting!</p>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch, nextTick, onMounted } from 'vue'
import { useChatStore } from '@/stores'
import MessageItem from './MessageItem.vue'

const chatStore = useChatStore()
const messagesContainer = ref<HTMLElement | null>(null)

const scrollToBottom = () => {
  nextTick(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
    }
  })
}

watch(() => chatStore.messages.length, scrollToBottom)
onMounted(scrollToBottom)
</script>
