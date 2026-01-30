<template>
  <div class="row">
    <div class="col-12 col-lg-10 mx-auto">
      <div class="card">
        <div class="card-header">
          <h5 class="mb-0">Admin Moderator</h5>
        </div>
        <div class="card-body">
          <Table
            :items="moderatorData"
            item-key="id"
            :colspan="5"
            :searchable="true"
            search-placeholder="Search moderator data..."
            :search-fields="['userId', 'username', 'action']"
            :sortable="true"
            :sortable-fields="['timestamp', 'action']"
            :paginated="false"
            :show-actions="true"
            :show-add-button="true"
            :show-edit-button="true"
            :show-delete-button="true"
            :pending-changes="pendingChanges"
            :change-markers="changeMarkers"
            @add="handleAdd"
            @edit="handleEdit"
            @delete="handleDelete"
            @update-snapshot="updateSnapshot"
          >
            <template #header="{ sort, handleSort, isFieldSortable }">
              <th>User ID</th>
              <th>Username</th>
              <th>
                <button
                  v-if="isFieldSortable('timestamp')"
                  class="btn btn-link p-0 text-decoration-none text-dark fw-bold"
                  @click="handleSort('timestamp')"
                >
                  Timestamp
                  <span v-if="sort.field === 'timestamp'">
                    {{ sort.direction === 'asc' ? '↑' : '↓' }}
                  </span>
                </button>
                <span v-else>Timestamp</span>
              </th>
              <th>
                <button
                  v-if="isFieldSortable('action')"
                  class="btn btn-link p-0 text-decoration-none text-dark fw-bold"
                  @click="handleSort('action')"
                >
                  Action
                  <span v-if="sort.field === 'action'">
                    {{ sort.direction === 'asc' ? '↑' : '↓' }}
                  </span>
                </button>
                <span v-else>Action</span>
              </th>
              <th>Actions</th>
            </template>
            <template #row="{ item, changeType, handleEdit, handleDelete, showEditButton, showDeleteButton }">
              <td>
                <span v-if="changeType === 'added'" class="badge bg-success me-1">+</span>
                <span v-else-if="changeType === 'updated'" class="badge bg-warning me-1">~</span>
                <span v-else-if="changeType === 'deleted'" class="badge bg-danger me-1">-</span>
                {{ item.userId }}
              </td>
              <td>{{ item.username }}</td>
              <td>{{ formatDate(item.timestamp) }}</td>
              <td>
                <span class="badge" :class="getActionBadgeClass(item.action)">
                  {{ item.action }}
                </span>
              </td>
              <td>
                <div class="d-flex gap-1">
                  <button
                    v-if="showEditButton"
                    type="button"
                    class="btn btn-sm btn-outline-primary"
                    @click="handleEdit"
                  >
                    Edit
                  </button>
                  <button
                    v-if="showDeleteButton"
                    type="button"
                    class="btn btn-sm btn-outline-danger"
                    @click="handleDelete"
                  >
                    Delete
                  </button>
                </div>
              </td>
            </template>
            <template #empty>
              <p class="mb-0">No moderator actions found</p>
            </template>
          </Table>
        </div>
      </div>
    </div>
  </div>

  <Modal
    v-model="showModal"
    :title="modalTitle"
    modal-name="admin-moderator-modal"
    :modal-type="modalMode"
    :confirm-on-close="isFormDirty"
    @cancel="resetForm"
    @ok="submitModal"
  >
    <form v-if="!isDeleteMode" @submit.prevent="submitModal">
      <div class="mb-3">
        <label class="form-label" for="mod-user-id">User ID</label>
        <input
          id="mod-user-id"
          v-model.number="formAction.userId"
          type="number"
          class="form-control"
          min="1"
          required
          data-autofocus
        />
      </div>
      <div class="mb-3">
        <label class="form-label" for="mod-username">Username</label>
        <input
          id="mod-username"
          v-model="formAction.username"
          type="text"
          class="form-control"
          required
          maxlength="50"
        />
      </div>
      <div class="mb-0">
        <label class="form-label" for="mod-action">Action</label>
        <select id="mod-action" v-model="formAction.action" class="form-select">
          <option value="approved">approved</option>
          <option value="rejected">rejected</option>
          <option value="flagged">flagged</option>
        </select>
      </div>
    </form>
    <div v-else class="text-muted">
      Delete moderator action for <strong>{{ selectedAction?.username }}</strong>?
    </div>
    <template #actions="{ requestClose }">
      <button type="button" class="btn btn-secondary" @click="requestClose">Cancel</button>
      <button
        v-if="isDeleteMode"
        type="button"
        class="btn btn-danger"
        @click="submitModal"
      >
        Delete
      </button>
      <button
        v-else
        type="button"
        class="btn btn-primary"
        :disabled="!isFormValid"
        @click="submitModal"
      >
        Save
      </button>
    </template>
  </Modal>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed } from 'vue'
import { Table, Modal } from '@hilos/sdk/components'

interface ModeratorAction {
  id: number
  userId: number
  username: string
  timestamp: string
  action: string
}

// Snapshot data - represents table state at a specific point in time
const snapshotModeratorData = ref<ModeratorAction[]>([
  {
    id: 1,
    userId: 1,
    username: 'User 1',
    timestamp: '2025-01-29T10:00:00Z',
    action: 'approved'
  },
  {
    id: 2,
    userId: 2,
    username: 'User 2',
    timestamp: '2025-01-29T09:30:00Z',
    action: 'rejected'
  },
  {
    id: 3,
    userId: 3,
    username: 'User 3',
    timestamp: '2025-01-29T08:00:00Z',
    action: 'flagged'
  },
  {
    id: 4,
    userId: 1,
    username: 'User 1',
    timestamp: '2025-01-29T07:00:00Z',
    action: 'approved'
  },
  {
    id: 5,
    userId: 4,
    username: 'User 4',
    timestamp: '2025-01-29T06:00:00Z',
    action: 'approved'
  }
])

// Current displayed data (snapshot + pending changes)
const moderatorData = ref<ModeratorAction[]>([...snapshotModeratorData.value])

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
const modalMode = ref<'custom' | 'add' | 'edit' | 'delete'>('custom')
const selectedAction = ref<ModeratorAction | null>(null)
const formAction = ref<ModeratorAction>({
  id: 0,
  userId: 0,
  username: '',
  timestamp: '',
  action: 'approved'
})
const baselineAction = ref<ModeratorAction | null>(null)

const isDeleteMode = computed(() => modalMode.value === 'delete')
const modalTitle = computed(() => {
  switch (modalMode.value) {
    case 'add':
      return 'Add Moderator Action'
    case 'edit':
      return 'Edit Moderator Action'
    case 'delete':
      return 'Delete Moderator Action'
    default:
      return 'Moderator Action'
  }
})

const cloneAction = (action: ModeratorAction): ModeratorAction => {
  return JSON.parse(JSON.stringify(action)) as ModeratorAction
}

const isFormDirty = computed(() => {
  if (isDeleteMode.value || !baselineAction.value) return false
  return JSON.stringify(formAction.value) !== JSON.stringify(baselineAction.value)
})

const isFormValid = computed(() => {
  return formAction.value.userId > 0 && formAction.value.username.trim().length > 0
})

// TEMPORARY: WebSocket emulation for debugging
// TODO: Replace with real WebSocket implementation
let emulationTimeouts: ReturnType<typeof setTimeout>[] = []

const emulateWebSocketEvents = () => {
  // Simulate multiple events at different times
  emulationTimeouts.push(
    setTimeout(() => {
      // Add new moderator action
      const newAction = {
        id: 6,
        userId: 5,
        username: 'User 5',
        timestamp: new Date().toISOString(),
        action: 'flagged'
      }
      moderatorData.value.push(newAction)
      changeMarkers.value.added.push(6)
      pendingChanges.value.added++
    }, 3000)
  )

  emulationTimeouts.push(
    setTimeout(() => {
      // Update existing action
      const actionIndex = moderatorData.value.findIndex(a => a.id === 2)
      const actionEntry = moderatorData.value[actionIndex]
      if (actionEntry && actionIndex !== -1) {
        moderatorData.value[actionIndex] = {
          ...actionEntry,
          action: 'approved'
        }
        changeMarkers.value.updated.push(2)
        pendingChanges.value.updated++
      }
    }, 5000)
  )

  emulationTimeouts.push(
    setTimeout(() => {
      // Mark for deletion
      changeMarkers.value.deleted.push(5)
      pendingChanges.value.deleted++
    }, 10000)
  )
}

const updateSnapshot = () => {
  // Apply all pending changes to snapshot
  snapshotModeratorData.value = moderatorData.value.filter(a => !changeMarkers.value.deleted.includes(a.id))
  
  // Reset pending changes
  pendingChanges.value = { added: 0, updated: 0, deleted: 0 }
  changeMarkers.value = { added: [], updated: [], deleted: [] }
  
  // Update displayed data
  moderatorData.value = [...snapshotModeratorData.value]
}

onMounted(() => {
  // TEMPORARY: Start WebSocket emulation
  emulateWebSocketEvents()
})

onUnmounted(() => {
  emulationTimeouts.forEach(timeout => clearTimeout(timeout))
  emulationTimeouts = []
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

const getActionBadgeClass = (action: string | null | undefined): string => {
  switch (action) {
    case 'approved':
      return 'bg-success'
    case 'rejected':
      return 'bg-danger'
    case 'flagged':
      return 'bg-warning'
    default:
      return 'bg-secondary'
  }
}

const handleAdd = () => {
  modalMode.value = 'add'
  selectedAction.value = null
  formAction.value = {
    id: 0,
    userId: 1,
    username: '',
    timestamp: '',
    action: 'approved'
  }
  baselineAction.value = cloneAction(formAction.value)
  showModal.value = true
}

const handleEdit = (item: unknown) => {
  if (typeof item !== 'object' || item === null) return
  const action = item as ModeratorAction
  modalMode.value = 'edit'
  selectedAction.value = action
  formAction.value = cloneAction(action)
  baselineAction.value = cloneAction(action)
  showModal.value = true
}

const handleDelete = (item: unknown) => {
  if (typeof item !== 'object' || item === null) return
  const action = item as ModeratorAction
  modalMode.value = 'delete'
  selectedAction.value = action
  formAction.value = cloneAction(action)
  baselineAction.value = cloneAction(action)
  showModal.value = true
}

const submitModal = () => {
  if (isDeleteMode.value) {
    const id = selectedAction.value?.id
    if (id === undefined) return
    if (!changeMarkers.value.deleted.includes(id)) {
      changeMarkers.value.deleted.push(id)
      pendingChanges.value.deleted++
    }
    resetForm()
    return
  }

  if (!isFormValid.value) return

  if (modalMode.value === 'add') {
    const maxId = moderatorData.value.reduce((max, entry) => Math.max(max, entry.id), 0)
    const newAction: ModeratorAction = {
      id: maxId + 1,
      userId: formAction.value.userId,
      username: formAction.value.username.trim(),
      timestamp: new Date().toISOString(),
      action: formAction.value.action
    }
    moderatorData.value.push(newAction)
    changeMarkers.value.added.push(newAction.id)
    pendingChanges.value.added++
    resetForm()
    return
  }

  if (modalMode.value === 'edit' && selectedAction.value) {
    const index = moderatorData.value.findIndex(a => a.id === selectedAction.value?.id)
    if (index === -1) return
    const existing = moderatorData.value[index]
    if (!existing) return
    moderatorData.value[index] = {
      ...existing,
      userId: formAction.value.userId,
      username: formAction.value.username.trim(),
      action: formAction.value.action
    }
    if (!changeMarkers.value.updated.includes(existing.id)) {
      changeMarkers.value.updated.push(existing.id)
      pendingChanges.value.updated++
    }
    resetForm()
  }
}

const resetForm = () => {
  showModal.value = false
  modalMode.value = 'custom'
  selectedAction.value = null
  baselineAction.value = null
  formAction.value = {
    id: 0,
    userId: 0,
    username: '',
    timestamp: '',
    action: 'approved'
  }
}
</script>
