<template>
  <DaemonSectionShell :title="pageTitle">
    <p v-if="!connectionStore.isConnected" class="text-body-secondary mb-3">
      Connect to the server to load this user.
    </p>
    <p v-else-if="parsedUserId === null" class="text-body-secondary mb-3">
      Invalid user ID.
      <router-link to="/hilos/users">Back to users</router-link>
    </p>
    <p v-else-if="!hasSubscribeResponse" class="text-body-secondary mb-3">Loading…</p>
    <p v-else-if="!currentUser" class="text-body-secondary mb-3">
      User not found.
      <router-link to="/hilos/users">Back to users</router-link>
    </p>
    <template v-else>
      <p class="text-body-secondary small mb-3">
        Email and Hilos roles are not stored on the chat <code>user</code> row. Rename the user via
        the modal below; changes use the same backend action as
        <router-link to="/admin/users">Admin → Users</router-link>.
      </p>
      <div class="row g-3">
        <div class="col-12 col-md-6">
          <label class="form-label">Name</label>
          <div class="form-control-plaintext">{{ currentUser.name }}</div>
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label">Last activity</label>
          <div class="form-control-plaintext">{{ formatDate(currentUser.lastActivity) }}</div>
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label">Presence</label>
          <div class="form-control-plaintext">
            <span class="badge" :class="getPresenceBadgeClass(currentUser.presence)">
              {{ currentUser.presence || 'offline' }}
            </span>
          </div>
        </div>
        <div class="col-12 d-flex flex-wrap gap-2">
          <button type="button" class="btn btn-primary" @click="handleEdit">
            <i class="bi bi-pencil" aria-hidden="true"></i>
            Rename
          </button>
          <router-link class="btn btn-outline-secondary" to="/hilos/users">Back to list</router-link>
        </div>
      </div>

      <Modal
        v-model="showModal"
        title="Edit User"
        modal-name="hilos-user-modal"
        modal-type="edit"
        :confirm-on-close="isFormDirty"
        @cancel="resetForm"
        @ok="saveUser"
      >
        <form @submit.prevent="saveUser">
          <div class="mb-3">
            <label class="form-label" for="hilos-user-name">Name</label>
            <input
              id="hilos-user-name"
              v-model="form.name"
              type="text"
              class="form-control"
              required
              minlength="2"
              maxlength="50"
              autocomplete="off"
              data-autofocus
            />
            <div v-if="updateErrorMessage" class="form-text text-danger" role="alert">
              {{ updateErrorMessage }}
            </div>
          </div>
        </form>
        <template #actions="{ requestClose }">
          <button type="button" class="btn btn-secondary" @click="requestClose">Cancel</button>
          <LoadingButton
            type="button"
            variant="btn-primary"
            :loading="saveLoading"
            :disabled="!isFormValid || !isFormDirty"
            :loading-delay="300"
            @click="saveUser"
          >
            Save
          </LoadingButton>
        </template>
      </Modal>
    </template>
  </DaemonSectionShell>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useHead } from '@unhead/vue'
import DaemonSectionShell from '@hilos/sdk/views/Hilos/Daemon/DaemonSectionShell.vue'
import { LoadingButton, Modal } from '@hilos/sdk/components'
import { TableActionConstants } from '@hilos/sdk/constants/tableActions'
import { useConnectionStore } from '@hilos/sdk/stores'
import { useWebSocket } from '@hilos/sdk/plugins/websocket'
import { sendAction } from '@/services/websocketActions'
import type { User } from '@/types'
import { useChatStore } from '@/stores'
import { useSignalRouter } from '@/plugins/websocket'
import {
  hilosUserUpdateSuccess,
  hilosUserUpdateFail,
  type HilosUserUpdateFailReason,
} from '@/signals'

const route = useRoute()
const connectionStore = useConnectionStore()
const chatStore = useChatStore()
const websocket = useWebSocket()
const signalRouter = useSignalRouter()

const userIdParam = computed(() => {
  const raw = route.params.userId
  return typeof raw === 'string' ? raw : ''
})

const parsedUserId = computed((): number | null => {
  const raw = userIdParam.value
  const n = Number.parseInt(raw, 10)
  return Number.isFinite(n) && n > 0 ? n : null
})

// Stay in loading until this route's subscription response arrives.
const hasSubscribeResponse = computed(() => {
  const id = parsedUserId.value
  return id !== null && chatStore.lastHilosUserSubscribeAckId === id
})

const currentUser = computed((): User | null => {
  const id = parsedUserId.value
  if (id === null) return null
  return chatStore.users.find((u) => u.id === id) ?? null
})

const showModal = ref(false)
const form = ref({ name: '' })
const baselineName = ref('')
const saveLoading = ref(false)
const updateErrorMessage = ref<string | null>(null)

const isFormDirty = computed(() => form.value.name.trim() !== baselineName.value.trim())

const isFormValid = computed(() => {
  const name = form.value.name.trim()
  return name.length >= 2 && name.length <= 50
})

const pageTitle = computed(() => {
  const id = parsedUserId.value
  if (id === null) return 'User'
  if (currentUser.value) return `User · ${currentUser.value.name}`
  return `User · #${id}`
})

const formatDate = (dateStr: string | null | undefined): string => {
  if (!dateStr) return 'Never'
  try {
    return new Date(dateStr).toLocaleString()
  } catch {
    return dateStr
  }
}

const getPresenceBadgeClass = (presence: string | null | undefined): string => {
  switch (presence) {
    case 'online':
      return 'bg-success'
    case 'unstable':
      return 'bg-warning'
    case 'offline':
      return 'bg-secondary'
    default:
      return 'bg-secondary'
  }
}

const handleEdit = () => {
  const user = currentUser.value
  if (!user) return
  form.value = { name: user.name }
  baselineName.value = user.name
  updateErrorMessage.value = null
  saveLoading.value = false
  showModal.value = true
}

const resetForm = () => {
  showModal.value = false
  saveLoading.value = false
  updateErrorMessage.value = null
  const user = currentUser.value
  form.value = { name: user?.name ?? '' }
  baselineName.value = user?.name ?? ''
}

// Closes the modal only on success — on fail we keep it open so the user can see the error
// and retry. This differs from AdminUsers.vue (which closes synchronously) because that page
// does not render an error message for this action.
const onUpdateSuccess = () => {
  saveLoading.value = false
  updateErrorMessage.value = null
  if (currentUser.value) {
    baselineName.value = currentUser.value.name
  }
  showModal.value = false
}

const onUpdateFail = ({ message }: { reason: HilosUserUpdateFailReason; message: string }) => {
  saveLoading.value = false
  updateErrorMessage.value = message
}

onMounted(() => {
  signalRouter.on(hilosUserUpdateSuccess, onUpdateSuccess)
  signalRouter.on(hilosUserUpdateFail, onUpdateFail)
})

const saveUser = () => {
  const id = parsedUserId.value
  if (id === null || !isFormValid.value || !isFormDirty.value) return
  saveLoading.value = true
  updateErrorMessage.value = null
  sendAction(websocket, TableActionConstants.HILOS_USER_UPDATE, {
    id,
    name: form.value.name.trim(),
  })
}

useHead({
  title: () => `${pageTitle.value} | Hilos | Chat Demo`,
})
</script>
