<template>
  <div class="card">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
      <h5 class="mb-0">WebSocket Chat Demo</h5>
      <button
        type="button"
        class="btn btn-link text-white p-2"
        @click="showModal = true"
        title="Change username"
        aria-label="Change username"
      >
        <i class="bi bi-gear-fill"></i>
      </button>
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

    <!-- Username change modal -->
    <Modal
      v-model="showModal"
      title="Change Username"
      modal-name="change-username-modal"
      @ok="handleSubmitUsername"
      @cancel="resetForm"
    >
      <template #header>
        <h5 class="modal-title">Change Username</h5>
      </template>

      <form @submit.prevent="handleSubmitUsername">
        <div class="mb-3">
          <label for="username-input" class="form-label">Your Name</label>
          <input
            id="username-input"
            v-model="localUsername"
            type="text"
            class="form-control"
            placeholder="Enter your name"
            required
            minlength="2"
            maxlength="20"
            data-autofocus
          />
          <div class="form-text">Username must be between 2 and 20 characters</div>
        </div>
      </form>

      <template #actions>
        <button type="button" class="btn btn-secondary" @click="resetForm">Cancel</button>
        <button
          type="button"
          class="btn btn-primary"
          @click="handleSubmitUsername"
          :disabled="!isValidUsername"
        >
          Save
        </button>
      </template>
    </Modal>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted } from 'vue'
import { useChatStore } from '@/stores'
import { Modal } from '@hilos/sdk/components'
import MessageItem from './MessageItem.vue'

const chatStore = useChatStore()
const messagesContainer = ref<HTMLElement | null>(null)
const message = ref('')
const showModal = ref(false)
const localUsername = ref('User')

const emit = defineEmits<{
  send: [message: string]
}>()

const isValidUsername = computed(() => {
  const trimmed = localUsername.value.trim()
  return trimmed.length >= 2 && trimmed.length <= 20
})

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

const handleSubmitUsername = () => {
  if (isValidUsername.value) {
    // TODO: Send username change to server via WebSocket
    showModal.value = false
  }
}

const resetForm = () => {
  localUsername.value = 'User'
  showModal.value = false
}

watch(() => chatStore.messages.length, scrollToBottom)
onMounted(scrollToBottom)
</script>
