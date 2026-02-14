<template>
  <div class="row">
    <div class="col-12 col-lg-10 mx-auto">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">Admin Users</h5>
          <button
            v-if="usersTableMeta"
            type="button"
            class="btn btn-outline-light btn-sm"
            title="Refresh table from server"
            aria-label="Refresh table"
            @click="updateSnapshot"
          >
            <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
            Refresh
          </button>
        </div>
        <div class="card-body">
          <Table
            :items="users"
            item-key="id"
            :colspan="5"
            :searchable="true"
            search-placeholder="Search users..."
            :search-fields="['id', 'name']"
            :sortable="true"
            :sortable-fields="['id', 'name', 'lastActivity']"
            :paginated="true"
            :show-actions="true"
            :show-add-button="false"
            :show-edit-button="true"
            :show-delete-button="false"
            :pending-changes="pendingChanges"
            :change-markers="changeMarkers"
            @edit="handleEdit"
            @update-snapshot="updateSnapshot"
          >
            <template #header="{ sort, handleSort, isFieldSortable }">
              <th>
                <button
                  v-if="isFieldSortable('id')"
                  class="btn btn-link p-0 text-decoration-none text-dark fw-bold"
                  @click="handleSort('id')"
                >
                  ID
                  <span v-if="sort.field === 'id'">
                    {{ sort.direction === 'asc' ? '↑' : '↓' }}
                  </span>
                </button>
                <span v-else>ID</span>
              </th>
              <th>
                <button
                  v-if="isFieldSortable('name')"
                  class="btn btn-link p-0 text-decoration-none text-dark fw-bold"
                  @click="handleSort('name')"
                >
                  Name
                  <span v-if="sort.field === 'name'">
                    {{ sort.direction === 'asc' ? '↑' : '↓' }}
                  </span>
                </button>
                <span v-else>Name</span>
              </th>
              <th>
                <button
                  v-if="isFieldSortable('lastActivity')"
                  class="btn btn-link p-0 text-decoration-none text-dark fw-bold"
                  @click="handleSort('lastActivity')"
                >
                  Last Activity
                  <span v-if="sort.field === 'lastActivity'">
                    {{ sort.direction === 'asc' ? '↑' : '↓' }}
                  </span>
                </button>
                <span v-else>Last Activity</span>
              </th>
              <th>
                Presence
              </th>
              <th>Actions</th>
            </template>
            <template #row="{ item, handleEdit, showEditButton }">
              <td>
                {{ item.id }}
              </td>
              <td>{{ item.name }}</td>
              <td>{{ formatDate(item.lastActivity) }}</td>
              <td>
                <span class="badge" :class="getPresenceBadgeClass(item.presence)">
                  {{ item.presence || 'offline' }}
                </span>
              </td>
              <td>
                <div v-if="showEditButton" class="d-flex gap-1">
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-primary"
                    title="Edit"
                    aria-label="Edit"
                    @click="handleEdit"
                  >
                    <i class="bi bi-pencil" aria-hidden="true"></i>
                    <span class="visually-hidden">Edit</span>
                  </button>
                </div>
              </td>
            </template>
            <template #empty>
              <p class="mb-0">No users found</p>
            </template>
          </Table>
        </div>
      </div>
    </div>
  </div>

  <Modal
    v-model="showModal"
    :title="modalTitle"
    modal-name="admin-users-modal"
    modal-type="edit"
    :confirm-on-close="isFormDirty"
    @cancel="resetForm"
    @ok="saveUser"
  >
    <form @submit.prevent="saveUser">
      <div class="mb-3">
        <label class="form-label" for="user-name">Name</label>
        <input
          id="user-name"
          v-model="formUser.name"
          type="text"
          class="form-control"
          required
          minlength="2"
          maxlength="50"
          data-autofocus
        />
      </div>
      <div class="mb-3">
        <label class="form-label">Presence</label>
        <div class="form-control-plaintext">
          <span class="badge" :class="getPresenceBadgeClass(formUser.presence)">
            {{ formUser.presence || 'offline' }}
          </span>
        </div>
      </div>
      <div class="mb-0">
        <label class="form-label">Last Activity</label>
        <div class="form-control-plaintext">{{ formatDate(formUser.lastActivity) }}</div>
      </div>
    </form>
    <template #actions="{ requestClose }">
      <button type="button" class="btn btn-secondary" @click="requestClose">Cancel</button>
      <LoadingButton
        type="button"
        variant="btn-primary"
        :loading="saveLoading"
        :disabled="!isFormValid"
        :loading-delay="300"
        @click="saveUser"
      >
        Save
      </LoadingButton>
    </template>
  </Modal>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useWebSocket } from '@hilos/sdk/plugins/websocket'
import { Table, Modal, LoadingButton } from '@hilos/sdk/components'
import { useChatStore } from '@/stores'
import { sendAction } from '@/services/websocketActions'
import { TableActionConstants } from '@hilos/sdk/constants'

interface UserEntity {
  id: number
  name: string
  lastActivity: string
  presence?: string
}

const chatStore = useChatStore()
const websocket = useWebSocket()

// Table data from backend (subscription_page_admin_users / table_update)
const usersTableKey = 'users'
const usersTableMeta = computed(() => chatStore.tableData[usersTableKey])
const users = computed(() => {
  const data = usersTableMeta.value
  if (!data || !Array.isArray(data.rows)) return []
  return data.rows as UserEntity[]
})

// Pending changes tracking
const pendingChanges = ref({
  added: 0,
  updated: 0,
  deleted: 0
})

const changeMarkers = ref({
  added: [] as number[],
  updated: [] as number[],
  deleted: [] as number[]
})

const showModal = ref(false)
const selectedUser = ref<UserEntity | null>(null)
const formUser = ref<UserEntity>({
  id: 0,
  name: '',
  lastActivity: '',
  presence: 'offline'
})
const baselineUser = ref<UserEntity | null>(null)

const modalTitle = computed(() => 'Edit User')

const cloneUser = (user: UserEntity): UserEntity => {
  return JSON.parse(JSON.stringify(user)) as UserEntity
}

const isFormDirty = computed(() => {
  if (!baselineUser.value) return false
  return JSON.stringify(formUser.value) !== JSON.stringify(baselineUser.value)
})

const isFormValid = computed(() => {
  const name = formUser.value.name.trim()
  return name.length >= 2 && name.length <= 50
})

/** Request fresh table data from backend; response comes via table_update signal. */
const updateSnapshot = () => {
  sendAction(websocket, TableActionConstants.ACTION_REFRESH_SNAPSHOT, {
    [TableActionConstants.PAYLOAD_KEY_TABLE_KEY]: usersTableKey,
  })
}

const formatDate = (dateStr: string | null | undefined): string => {
  if (!dateStr) return 'Never'
  try {
    const date = new Date(dateStr)
    return date.toLocaleString()
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

const handleEdit = (item: unknown) => {
  if (typeof item !== 'object' || item === null) return
  const user = item as UserEntity
  selectedUser.value = user
  formUser.value = cloneUser(user)
  baselineUser.value = cloneUser(user)
  showModal.value = true
}

const saveLoading = ref(false)

const saveUser = () => {
  if (!selectedUser.value || !isFormValid.value) return
  saveLoading.value = true
  // TODO: send update user action to backend when API is available
  resetForm()
}

const resetForm = () => {
  showModal.value = false
  selectedUser.value = null
  baselineUser.value = null
  saveLoading.value = false
  formUser.value = {
    id: 0,
    name: '',
    lastActivity: '',
    presence: 'offline'
  }
}
</script>
