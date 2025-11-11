<template>
  <div class="card mb-3">
    <div class="card-body d-flex justify-content-between align-items-center">
      <div>
        <small class="text-muted d-block">Your Name</small>
        <strong>{{ chatStore.username }}</strong>
      </div>
      <button
        type="button"
        class="btn btn-link p-2"
        @click="showModal = true"
        title="Change username"
        aria-label="Change username"
      >
        <i class="bi bi-gear-fill"></i>
      </button>
    </div>
  </div>

  <!-- Username change modal -->
  <Modal
    v-model="showModal"
    title="Change Username"
    modal-name="change-username-modal"
    @ok="handleSubmit"
    @cancel="resetForm"
  >
    <template #header>
      <h5 class="modal-title">Change Username</h5>
    </template>

    <form @submit.prevent="handleSubmit">
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
        @click="handleSubmit"
        :disabled="!isValidUsername"
      >
        Save
      </button>
    </template>
  </Modal>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useChatStore } from '@/stores'
import { Modal } from '@hilos/sdk/components'

const chatStore = useChatStore()
const showModal = ref(false)
const localUsername = ref(chatStore.username)

const isValidUsername = computed(() => {
  const trimmed = localUsername.value.trim()
  return trimmed.length >= 2 && trimmed.length <= 20
})

const handleSubmit = () => {
  if (isValidUsername.value) {
    chatStore.setUsername(localUsername.value.trim())
    showModal.value = false
    // TODO: Send username change to server via WebSocket
  }
}

const resetForm = () => {
  localUsername.value = chatStore.username
  showModal.value = false
}
</script>
