<template>
  <div class="row">
    <div class="col-12 col-lg-10 mx-auto">
      <div class="card">
        <div class="card-header">
          <h5 class="mb-0">Admin Users</h5>
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
        <label class="form-label" for="user-presence">Presence</label>
        <select id="user-presence" v-model="formUser.presence" class="form-select">
          <option value="online">online</option>
          <option value="unstable">unstable</option>
          <option value="offline">offline</option>
        </select>
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
import { ref, onMounted, onUnmounted, computed } from 'vue'
import { Table, Modal, LoadingButton } from '@hilos/sdk/components'

interface UserEntity {
  id: number
  name: string
  lastActivity: string
  presence: string
}

// Snapshot data - represents table state at a specific point in time
const snapshotUsers = ref<UserEntity[]>([
  {
    id: 1,
    name: 'User 1',
    lastActivity: '2025-01-29T10:00:00Z',
    presence: 'online'
  },
  {
    id: 2,
    name: 'User 2',
    lastActivity: '2025-01-29T09:30:00Z',
    presence: 'unstable'
  },
  {
    id: 3,
    name: 'User 3',
    lastActivity: '2025-01-29T08:00:00Z',
    presence: 'offline'
  }
])

// Current displayed data (snapshot only)
const users = computed(() => snapshotUsers.value)

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

const pendingAdditions = ref<UserEntity[]>([])
const pendingUpdates = ref<Record<number, Partial<UserEntity>>>({})

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

const markUserUpdated = (id: number, updates: Partial<UserEntity>) => {
  pendingUpdates.value[id] = {
    ...(pendingUpdates.value[id] || {}),
    ...updates
  }
  if (!changeMarkers.value.updated.includes(id)) {
    changeMarkers.value.updated.push(id)
    pendingChanges.value.updated++
  }
}

const isFormDirty = computed(() => {
  if (!baselineUser.value) return false
  return JSON.stringify(formUser.value) !== JSON.stringify(baselineUser.value)
})

const isFormValid = computed(() => {
  const name = formUser.value.name.trim()
  return name.length >= 2 && name.length <= 50
})

// TEMPORARY: WebSocket emulation for debugging
// TODO: Replace with real WebSocket implementation
let emulationTimeout: ReturnType<typeof setTimeout> | null = null

const emulateWebSocketEvents = () => {
  // Simulate: User 2 updated, User 4 added, User 3 marked for deletion
  setTimeout(() => {
    // Update existing user
    const user2 = snapshotUsers.value.find(u => u.id === 2)
    if (user2) {
      markUserUpdated(2, {
        name: 'User 2 (Updated)',
        presence: 'online'
      })
    }

    // Add new user
    const newUser = {
      id: 4,
      name: 'User 4 (New)',
      lastActivity: new Date().toISOString(),
      presence: 'online'
    }
    if (!changeMarkers.value.added.includes(4)) {
      pendingAdditions.value.push(newUser)
      changeMarkers.value.added.push(4)
      pendingChanges.value.added++
    }

    // Mark for deletion
    if (!changeMarkers.value.deleted.includes(3)) {
      changeMarkers.value.deleted.push(3)
      pendingChanges.value.deleted++
    }
  }, 5000)
}

const updateSnapshot = () => {
  // Apply all pending changes to snapshot
  const updatedSnapshot = snapshotUsers.value.map(user => {
    const updates = pendingUpdates.value[user.id]
    return updates ? { ...user, ...updates } : user
  })
  const additions = pendingAdditions.value.filter(user => !changeMarkers.value.deleted.includes(user.id))
  snapshotUsers.value = [...updatedSnapshot, ...additions].filter(
    user => !changeMarkers.value.deleted.includes(user.id)
  )
  
  // Reset pending changes
  pendingChanges.value = { added: 0, updated: 0, deleted: 0 }
  changeMarkers.value = { added: [], updated: [], deleted: [] }
  pendingAdditions.value = []
  pendingUpdates.value = {}
}

onMounted(() => {
  // TEMPORARY: Start WebSocket emulation after 5 seconds
  emulationTimeout = setTimeout(emulateWebSocketEvents, 5000)
})

onUnmounted(() => {
  if (emulationTimeout) {
    clearTimeout(emulationTimeout)
  }
})

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
  markUserUpdated(selectedUser.value.id, {
    name: formUser.value.name.trim(),
    presence: formUser.value.presence
  })

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
