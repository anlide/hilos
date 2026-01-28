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
      <ConflictHeader title="Change Username" :conflict="conflictState" />
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
      <ConflictActions
        :conflict="conflictState"
        :disable-save="conflictState || !isValidUsername"
        @save="handleSubmit"
        @accept-mine="acceptMine"
        @accept-theirs="acceptTheirs"
        @merge="mergeChanges"
      >
        <template #leading>
          <button type="button" class="btn btn-secondary" @click="resetForm">Cancel</button>
        </template>
      </ConflictActions>
    </template>
  </Modal>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useChatStore } from '@/stores'
import { useWebSocket } from '@hilos/sdk/plugins/websocket'
import { Modal, ConflictHeader, ConflictActions } from '@hilos/sdk/components'

const chatStore = useChatStore()
const websocket = useWebSocket()
const showModal = ref(false)
const localUsername = ref('')
const conflictState = ref(false)
const baselineUsername = ref('')
const remoteUsername = ref('')

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
    const current = chatStore.currentUsername || 'User'
    localUsername.value = current
    baselineUsername.value = current
    remoteUsername.value = current
    conflictState.value = false
  }
})

// Watch for username changes from backend
watch(() => chatStore.currentUsername, (newUsername) => {
  if (!newUsername) {
    return
  }

  if (!showModal.value) {
    localUsername.value = newUsername
    baselineUsername.value = newUsername
    remoteUsername.value = newUsername
    conflictState.value = false
    return
  }

  const trimmedLocal = localUsername.value.trim()
  const trimmedNew = newUsername.trim()

  if (trimmedLocal === trimmedNew) {
    baselineUsername.value = newUsername
    remoteUsername.value = newUsername
    conflictState.value = false
    return
  }

  if (baselineUsername.value !== newUsername) {
    conflictState.value = true
    remoteUsername.value = newUsername
  }
})

const handleSubmit = () => {
  if (isValidUsername.value && !conflictState.value) {
    const trimmedUsername = localUsername.value.trim()
    websocket.send({
      type: 'rename',
      username: trimmedUsername,
    })
    showModal.value = false
    conflictState.value = false
    baselineUsername.value = trimmedUsername
    remoteUsername.value = trimmedUsername
  }
}

const resetForm = () => {
  const current = chatStore.currentUsername || 'User'
  localUsername.value = current
  baselineUsername.value = current
  remoteUsername.value = current
  conflictState.value = false
  showModal.value = false
}

const acceptMine = () => {
  const trimmed = localUsername.value.trim()
  baselineUsername.value = trimmed
  remoteUsername.value = trimmed
  conflictState.value = false
}

const acceptTheirs = () => {
  const trimmed = remoteUsername.value.trim()
  if (trimmed !== '') {
    localUsername.value = trimmed
  }
  baselineUsername.value = localUsername.value.trim()
  remoteUsername.value = baselineUsername.value
  conflictState.value = false
}

const mergeChanges = () => {
  const local = localUsername.value.trim()
  const remote = remoteUsername.value.trim()
  if (remote === '') {
    conflictState.value = false
    return
  }
  if (local === '') {
    localUsername.value = remote
  } else if (local !== remote) {
    localUsername.value = `${local} / ${remote}`
  }
  baselineUsername.value = localUsername.value.trim()
  remoteUsername.value = baselineUsername.value
  conflictState.value = false
}
</script>
