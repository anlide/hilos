<template>
  <DaemonSectionShell :title="pageTitle">
    <p v-if="!connectionStore.isConnected" class="text-body-secondary mb-3">
      Connect to the server to load this user.
    </p>
    <p v-else-if="parsedUserId === null" class="text-body-secondary mb-3">
      Invalid user ID.
      <router-link to="/hilos/users">Back to users</router-link>
    </p>
    <p v-else-if="!tableState" class="text-body-secondary mb-3">Loading…</p>
    <p v-else-if="!baseRow" class="text-body-secondary mb-3">
      User not found.
      <router-link to="/hilos/users">Back to users</router-link>
    </p>
    <template v-else-if="currentRow">
      <p class="text-body-secondary small mb-3">
        Email and Hilos roles are not stored on the chat <code>user</code> row. Edit display name below; changes use the same backend action as
        <router-link to="/admin/users">Admin → Users</router-link>.
      </p>
      <form class="row g-3" @submit.prevent="saveUser">
        <div class="col-12 col-md-6">
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
          />
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label">Last activity</label>
          <div class="form-control-plaintext">{{ formatDate(currentRow.lastActivity) }}</div>
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label">Presence</label>
          <div class="form-control-plaintext">
            <span class="badge" :class="getPresenceBadgeClass(currentRow.presence)">
              {{ currentRow.presence || 'offline' }}
            </span>
          </div>
        </div>
        <div class="col-12 d-flex flex-wrap gap-2">
          <LoadingButton
            type="submit"
            variant="btn-primary"
            :loading="saveLoading"
            :disabled="!isFormValid || !isFormDirty"
            :loading-delay="300"
          >
            Save
          </LoadingButton>
          <router-link class="btn btn-outline-secondary" to="/hilos/users">Back to list</router-link>
        </div>
      </form>
    </template>
  </DaemonSectionShell>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useHead } from '@unhead/vue'
import DaemonSectionShell from '@hilos/sdk/views/Hilos/Daemon/DaemonSectionShell.vue'
import { LoadingButton } from '@hilos/sdk/components'
import { getTableDisplayRows } from '@hilos/sdk/composables'
import { TableActionConstants } from '@hilos/sdk/constants/tableActions'
import { useConnectionStore, useTableStore } from '@hilos/sdk/stores'
import { useWebSocket } from '@hilos/sdk/plugins/websocket'
import { sendAction } from '@hilos/sdk/services/websocketActions'
import type { ChatUserTableRow } from '@hilos/sdk/types/chatUserTableRow'
import { useChatStore } from '@/stores'

const route = useRoute()
const connectionStore = useConnectionStore()
const tableStore = useTableStore()
const chatStore = useChatStore()
const websocket = useWebSocket()

const tableKey = 'users'
const tableState = computed(() => tableStore.tableData[tableKey])
const displayRows = computed(() => getTableDisplayRows<ChatUserTableRow>(tableState.value))

const userIdParam = computed(() => {
  const raw = route.params.userId
  return typeof raw === 'string' ? raw : ''
})

const parsedUserId = computed((): number | null => {
  const raw = userIdParam.value
  const n = Number.parseInt(raw, 10)
  return Number.isFinite(n) && n > 0 ? n : null
})

const baseRow = computed((): ChatUserTableRow | null => {
  const id = parsedUserId.value
  if (id === null) return null
  return displayRows.value.find((r) => r.id === id) ?? null
})

const currentRow = computed((): ChatUserTableRow | null => {
  const row = baseRow.value
  if (!row) return null
  const liveUser = chatStore.users.find((u) => u.id === row.id)
  const presence = liveUser?.presence ?? row.presence
  return { ...row, presence }
})

const form = ref({ name: '' })
const baselineName = ref('')
const saveLoading = ref(false)

watch(
  baseRow,
  (row) => {
    saveLoading.value = false
    if (row) {
      form.value = { name: row.name }
      baselineName.value = row.name
    } else {
      form.value = { name: '' }
      baselineName.value = ''
    }
  },
  { immediate: true },
)

const isFormDirty = computed(() => form.value.name.trim() !== baselineName.value.trim())

const isFormValid = computed(() => {
  const name = form.value.name.trim()
  return name.length >= 2 && name.length <= 50
})

const pageTitle = computed(() => {
  const id = parsedUserId.value
  if (id === null) return 'User'
  if (baseRow.value) return `User · ${baseRow.value.name}`
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

const saveUser = () => {
  const id = parsedUserId.value
  if (id === null || !isFormValid.value) return
  saveLoading.value = true
  sendAction(websocket, TableActionConstants.HILOS_USER_UPDATE, {
    id,
    name: form.value.name.trim(),
  })
  window.setTimeout(() => {
    saveLoading.value = false
  }, 12_000)
}

useHead({
  title: () => `${pageTitle.value} | Hilos | Chat Demo`,
})
</script>
