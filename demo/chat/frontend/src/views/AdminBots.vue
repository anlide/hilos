<template>
  <div class="row">
    <div class="col-12 col-lg-10 mx-auto">
      <div class="card">
        <div class="card-header">
          <h5 class="mb-0">Admin Bots</h5>
        </div>
        <div class="card-body">
          <Table
            :items="bots"
            item-key="id"
            :colspan="5"
            :searchable="true"
            search-placeholder="Search bots..."
            :search-fields="['id', 'name', 'type']"
            :sortable="true"
            :sortable-fields="['id', 'name']"
            :paginated="true"
            :fixed-items-per-page="25"
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
              <th>Type</th>
              <th>Status</th>
              <th>Actions</th>
            </template>
            <template #row="{ item, handleEdit, handleDelete, showEditButton, showDeleteButton }">
              <td>
                {{ item.id }}
              </td>
              <td>{{ item.name }}</td>
              <td>
                <span class="badge bg-info">{{ item.type }}</span>
              </td>
              <td>
                <span class="badge" :class="getStatusBadgeClass(item.status)">
                  {{ item.status }}
                </span>
              </td>
              <td>
                <div class="d-flex gap-1">
                  <button
                    v-if="showEditButton"
                    type="button"
                    class="btn btn-sm btn-outline-primary"
                    title="Edit"
                    aria-label="Edit"
                    @click="handleEdit"
                  >
                    <i class="bi bi-pencil" aria-hidden="true"></i>
                    <span class="visually-hidden">Edit</span>
                  </button>
                  <button
                    v-if="showDeleteButton"
                    type="button"
                    class="btn btn-sm btn-outline-danger"
                    title="Delete"
                    aria-label="Delete"
                    @click="handleDelete"
                  >
                    <i class="bi bi-trash" aria-hidden="true"></i>
                    <span class="visually-hidden">Delete</span>
                  </button>
                </div>
              </td>
            </template>
            <template #empty>
              <p class="mb-0">No bots found</p>
            </template>
          </Table>
        </div>
      </div>
    </div>
  </div>

  <Modal
    v-model="showModal"
    :title="modalTitle"
    modal-name="admin-bots-modal"
    :modal-type="modalMode"
    :confirm-on-close="isFormDirty"
    @cancel="resetForm"
    @ok="submitModal"
  >
    <form v-if="!isDeleteMode" @submit.prevent="submitModal">
      <div class="mb-3">
        <label class="form-label" for="bot-name">Name</label>
        <input
          id="bot-name"
          v-model="formBot.name"
          type="text"
          class="form-control"
          required
          minlength="2"
          maxlength="50"
          data-autofocus
        />
      </div>
      <div class="mb-3">
        <label class="form-label" for="bot-type">Type</label>
        <input
          id="bot-type"
          v-model="formBot.type"
          type="text"
          class="form-control"
          required
          maxlength="50"
        />
      </div>
      <div class="mb-0">
        <label class="form-label" for="bot-status">Status</label>
        <select id="bot-status" v-model="formBot.status" class="form-select">
          <option value="active">active</option>
          <option value="inactive">inactive</option>
          <option value="maintenance">maintenance</option>
          <option value="error">error</option>
        </select>
      </div>
    </form>
    <div v-else class="text-muted">
      Delete bot <strong>{{ selectedBot?.name }}</strong> (ID {{ selectedBot?.id }})?
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
      <LoadingButton
        v-else
        type="button"
        variant="btn-primary"
        :loading="saveLoading"
        :disabled="!isFormValid"
        :loading-delay="300"
        @click="submitModal"
      >
        Save
      </LoadingButton>
    </template>
  </Modal>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed } from 'vue'
import { Table, Modal, LoadingButton } from '@hilos/sdk/components'

interface BotEntity {
  id: number
  name: string
  type: string
  status: string
}

// Snapshot data - represents table state at a specific point in time
// Generate 30 bots to test pagination with fixed 25 per page
const snapshotBots = ref<BotEntity[]>(
  Array.from({ length: 30 }, (_, i) => ({
    id: i + 1,
    name: `Bot ${i + 1}`,
    type: i % 3 === 0 ? 'AI Assistant' : i % 3 === 1 ? 'Chat Bot' : 'Helper Bot',
    status: i % 4 === 0 ? 'active' : i % 4 === 1 ? 'inactive' : i % 4 === 2 ? 'maintenance' : 'error'
  }))
)

// Current displayed data (snapshot only)
const bots = computed(() => snapshotBots.value)

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

const pendingAdditions = ref<BotEntity[]>([])
const pendingUpdates = ref<Record<number, Partial<BotEntity>>>({})

const showModal = ref(false)
const modalMode = ref<'custom' | 'add' | 'edit' | 'delete'>('custom')
const selectedBot = ref<BotEntity | null>(null)
const formBot = ref<BotEntity>({
  id: 0,
  name: '',
  type: '',
  status: 'inactive'
})
const baselineBot = ref<BotEntity | null>(null)

const isDeleteMode = computed(() => modalMode.value === 'delete')
const modalTitle = computed(() => {
  switch (modalMode.value) {
    case 'add':
      return 'Add Bot'
    case 'edit':
      return 'Edit Bot'
    case 'delete':
      return 'Delete Bot'
    default:
      return 'Bot'
  }
})

const cloneBot = (bot: BotEntity): BotEntity => {
  return JSON.parse(JSON.stringify(bot)) as BotEntity
}

const markBotUpdated = (id: number, updates: Partial<BotEntity>) => {
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
  if (isDeleteMode.value || !baselineBot.value) return false
  return JSON.stringify(formBot.value) !== JSON.stringify(baselineBot.value)
})

const isFormValid = computed(() => {
  const name = formBot.value.name.trim()
  const type = formBot.value.type.trim()
  return name.length >= 2 && name.length <= 50 && type.length > 0
})

// TEMPORARY: WebSocket emulation for debugging
// TODO: Replace with real WebSocket implementation
let emulationTimeouts: ReturnType<typeof setTimeout>[] = []

const emulateWebSocketEvents = () => {
  // Simulate multiple events at different times
  emulationTimeouts.push(
    setTimeout(() => {
      // Add new bot
      const newBot = {
        id: 31,
        name: 'Bot 31 (New)',
        type: 'AI Assistant',
        status: 'active'
      }
      if (!changeMarkers.value.added.includes(31)) {
        pendingAdditions.value.push(newBot)
        changeMarkers.value.added.push(31)
        pendingChanges.value.added++
      }
    }, 3000)
  )

  emulationTimeouts.push(
    setTimeout(() => {
      // Update existing bot
      const bot = snapshotBots.value.find(b => b.id === 5)
      if (bot) {
        markBotUpdated(5, {
          name: 'Bot 5 (Updated)',
          status: 'maintenance'
        })
      }
    }, 5000)
  )

  emulationTimeouts.push(
    setTimeout(() => {
      // Mark for deletion
      if (!changeMarkers.value.deleted.includes(10)) {
        changeMarkers.value.deleted.push(10)
        pendingChanges.value.deleted++
      }
    }, 8000)
  )

  emulationTimeouts.push(
    setTimeout(() => {
      // Another update
      const bot = snapshotBots.value.find(b => b.id === 15)
      if (bot) {
        markBotUpdated(15, {
          status: 'active'
        })
      }
    }, 10000)
  )
}

const updateSnapshot = () => {
  // Apply all pending changes to snapshot
  const updatedSnapshot = snapshotBots.value.map(bot => {
    const updates = pendingUpdates.value[bot.id]
    return updates ? { ...bot, ...updates } : bot
  })
  const additions = pendingAdditions.value.filter(bot => !changeMarkers.value.deleted.includes(bot.id))
  snapshotBots.value = [...updatedSnapshot, ...additions].filter(
    bot => !changeMarkers.value.deleted.includes(bot.id)
  )
  
  // Reset pending changes
  pendingChanges.value = { added: 0, updated: 0, deleted: 0 }
  changeMarkers.value = { added: [], updated: [], deleted: [] }
  pendingAdditions.value = []
  pendingUpdates.value = {}
}

onMounted(() => {
  // TEMPORARY: Start WebSocket emulation
  emulateWebSocketEvents()
})

onUnmounted(() => {
  emulationTimeouts.forEach(timeout => clearTimeout(timeout))
  emulationTimeouts = []
})

const getStatusBadgeClass = (status: string | null | undefined): string => {
  switch (status) {
    case 'active':
      return 'bg-success'
    case 'inactive':
      return 'bg-secondary'
    case 'maintenance':
      return 'bg-warning'
    case 'error':
      return 'bg-danger'
    default:
      return 'bg-secondary'
  }
}

const handleAdd = () => {
  modalMode.value = 'add'
  selectedBot.value = null
  formBot.value = {
    id: 0,
    name: '',
    type: '',
    status: 'inactive'
  }
  baselineBot.value = cloneBot(formBot.value)
  showModal.value = true
}

const handleEdit = (item: unknown) => {
  if (typeof item !== 'object' || item === null) return
  const bot = item as BotEntity
  modalMode.value = 'edit'
  selectedBot.value = bot
  formBot.value = cloneBot(bot)
  baselineBot.value = cloneBot(bot)
  showModal.value = true
}

const handleDelete = (item: unknown) => {
  if (typeof item !== 'object' || item === null) return
  const bot = item as BotEntity
  modalMode.value = 'delete'
  selectedBot.value = bot
  formBot.value = cloneBot(bot)
  baselineBot.value = cloneBot(bot)
  showModal.value = true
}

const saveLoading = ref(false)

const submitModal = () => {
  if (isDeleteMode.value) {
    const id = selectedBot.value?.id
    if (id === undefined) return
    if (!changeMarkers.value.deleted.includes(id)) {
      changeMarkers.value.deleted.push(id)
      pendingChanges.value.deleted++
    }
    resetForm()
    return
  }

  if (!isFormValid.value) return

  saveLoading.value = true
  if (modalMode.value === 'add') {
    const maxId = snapshotBots.value.reduce((max, bot) => Math.max(max, bot.id), 0)
    const newBot: BotEntity = {
      id: maxId + 1,
      name: formBot.value.name.trim(),
      type: formBot.value.type.trim(),
      status: formBot.value.status
    }
    if (!changeMarkers.value.added.includes(newBot.id)) {
      pendingAdditions.value.push(newBot)
      changeMarkers.value.added.push(newBot.id)
      pendingChanges.value.added++
    }
    resetForm()
    return
  }

  if (modalMode.value === 'edit' && selectedBot.value) {
    markBotUpdated(selectedBot.value.id, {
      name: formBot.value.name.trim(),
      type: formBot.value.type.trim(),
      status: formBot.value.status
    })
    resetForm()
  }
}

const resetForm = () => {
  showModal.value = false
  modalMode.value = 'custom'
  selectedBot.value = null
  baselineBot.value = null
  saveLoading.value = false
  formBot.value = {
    id: 0,
    name: '',
    type: '',
    status: 'inactive'
  }
}
</script>
