<template>
  <div class="row">
    <div class="col-12 col-lg-8 mx-auto">
      <div class="card">
        <div class="card-header">
          <h5 class="mb-0">Profile</h5>
        </div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label">Your Name</label>
            <div class="d-flex align-items-center gap-3">
              <div class="flex-grow-1">
                <strong class="fs-5">{{ displayUsername }}</strong>
              </div>
              <button
                type="button"
                class="btn btn-primary"
                @click="showModal = true"
              >
                Изменить
              </button>
            </div>
          </div>
        </div>
      </div>
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
import { ref, computed, watch } from 'vue'
import { useChatStore } from '@/stores'
import { useWebSocket } from '@hilos/sdk/plugins/websocket'
import { Modal } from '@hilos/sdk/components'

const chatStore = useChatStore()
const websocket = useWebSocket()
const showModal = ref(false)
const localUsername = ref('')

const displayUsername = computed(() => {
  return chatStore.currentUsername || 'User'
})

const isValidUsername = computed(() => {
  const trimmed = localUsername.value.trim()
  return trimmed.length >= 2 && trimmed.length <= 20
})

// Initialize with current username from store when modal opens
watch(showModal, (isOpen) => {
  if (isOpen) {
    localUsername.value = chatStore.currentUsername || 'User'
  }
})

// Watch for username changes from backend
watch(() => chatStore.currentUsername, (newUsername) => {
  if (newUsername && !showModal.value) {
    // Only update if modal is not open
    localUsername.value = newUsername
  }
})

const handleSubmit = () => {
  if (isValidUsername.value) {
    const trimmedUsername = localUsername.value.trim()
    // TODO: Send username change to server via WebSocket
    // Example: websocket.send({ type: 'change_username', content: trimmedUsername })
    showModal.value = false
  }
}

const resetForm = () => {
  localUsername.value = chatStore.currentUsername || 'User'
  showModal.value = false
}
</script>
