<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div class="col-12 col-lg-8 mx-auto d-flex flex-column h-100 min-h-0">
      <div class="card flex-grow-1 overflow-auto">
        <div class="card-header">
          <h5 class="mb-0">Profile</h5>
        </div>
        <div class="card-body">
          <div v-if="!connectionStore.isConnected" class="mb-3 placeholder-glow">
            <span class="placeholder col-3 mb-2 d-block" style="height: 0.875rem"></span>
            <div class="d-flex align-items-center gap-3">
              <span class="placeholder col-6" style="height: 1.5rem"></span>
              <span class="placeholder" style="width: 4rem; height: 2.25rem"></span>
            </div>
          </div>
          <div v-else class="mb-3">
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
                Edit
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
      <div v-if="renameErrorMessage" class="alert alert-danger mb-0" role="alert">
        {{ renameErrorMessage }}
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
        <template #save-button="{ disabled, onSave }">
          <LoadingButton
            type="button"
            variant="btn-primary"
            :loading="saveLoading"
            :disabled="disabled"
            :loading-delay="300"
            @click="onSave"
          >
            Save
          </LoadingButton>
        </template>
      </ConflictActions>
    </template>
  </Modal>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useConnectionStore } from '@hilos/sdk/stores'
import { useChatStore } from '@/stores'
import { useWebSocket } from '@hilos/sdk/plugins/websocket'
import { useSignalRouter } from '@/plugins/websocket'
import { sendAction } from '@/services/websocketActions'
import { renameSuccess, renameFail } from '@/signals'
import { Modal, ConflictHeader, ConflictActions, LoadingButton } from '@hilos/sdk/components'

const connectionStore = useConnectionStore()
const chatStore = useChatStore()
const websocket = useWebSocket()
const signalRouter = useSignalRouter()
const showModal = ref(false)
const localUsername = ref('')
const conflictState = ref(false)
const baselineUsername = ref('')
const remoteUsername = ref('')
const saveLoading = ref(false)
const renameErrorMessage = ref<string | null>(null)

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
    saveLoading.value = false
    renameErrorMessage.value = null
  }
})

// Watch for username changes from backend (external rename sync only).
// Rename acknowledgement for the local user is handled via renameSuccess signal.
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

const onRenameSuccess = () => {
  saveLoading.value = false
  renameErrorMessage.value = null
  showModal.value = false
}

const onRenameFail = ({ message }: { reason: string; message: string }) => {
  saveLoading.value = false
  renameErrorMessage.value = message
}

onMounted(() => {
  signalRouter.on(renameSuccess, onRenameSuccess)
  signalRouter.on(renameFail, onRenameFail)
})

onBeforeUnmount(() => {
  // No-op: router currently keeps a single handler per dispatch key, but
  // since this view is the only registrar of these keys during its lifetime
  // there's nothing to tear down. When multi-subscriber semantics are added
  // the router API will expose an explicit unsubscribe.
})

const handleSubmit = () => {
  if (!isValidUsername.value || conflictState.value) return
  const trimmedUsername = localUsername.value.trim()
  saveLoading.value = true
  renameErrorMessage.value = null
  sendAction(websocket, 'rename', { newName: trimmedUsername })
}

const resetForm = () => {
  const current = chatStore.currentUsername || 'User'
  localUsername.value = current
  baselineUsername.value = current
  remoteUsername.value = current
  conflictState.value = false
  saveLoading.value = false
  renameErrorMessage.value = null
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
