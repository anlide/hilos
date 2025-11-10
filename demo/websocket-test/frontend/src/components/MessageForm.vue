<template>
  <div class="card">
    <div class="card-body">
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
import { ref } from 'vue'
import { useChatStore } from '@/stores'

const chatStore = useChatStore()
const message = ref('')

const emit = defineEmits<{
  send: [message: string]
}>()

const handleSubmit = () => {
  if (message.value.trim() && chatStore.isConnected) {
    emit('send', message.value.trim())
    message.value = ''
  }
}
</script>
