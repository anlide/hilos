<template>
  <div class="card mb-3">
    <div class="card-body">
      <form @submit.prevent="handleSubmit" class="d-flex gap-2 align-items-end">
        <div class="flex-grow-1">
          <label for="username" class="form-label mb-1">Your Name</label>
          <input
            id="username"
            v-model="localUsername"
            type="text"
            class="form-control"
            placeholder="Enter your name"
            required
            minlength="2"
            maxlength="20"
          />
        </div>
        <button type="submit" class="btn btn-primary">Change Name</button>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useChatStore } from '@/stores'

const chatStore = useChatStore()
const localUsername = ref(chatStore.username)

const handleSubmit = () => {
  if (localUsername.value.trim().length >= 2) {
    chatStore.setUsername(localUsername.value.trim())
    // TODO: Send username change to server via WebSocket
  }
}
</script>

