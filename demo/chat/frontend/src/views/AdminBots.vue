<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div class="col-12 col-lg-10 mx-auto d-flex flex-column h-100 min-h-0">
      <ClientOnly>
      <div class="card d-flex flex-column flex-grow-1 min-h-0 overflow-hidden">
        <div class="card-header">
          <h5 class="mb-0">Admin Bots</h5>
        </div>
        <div class="card-body overflow-auto">
          <Table
            :items="displayRows"
            item-key="id"
            :colspan="7"
            :placeholder-when-empty="!connectionStore.isConnected || tableState === null"
            :searchable="true"
            search-placeholder="Search bots..."
            :search-fields="['id', 'name', 'description', 'style']"
            :sortable="true"
            :sortable-fields="['id', 'name', 'active']"
            :paginated="true"
            :show-actions="true"
            :show-add-button="true"
            :show-edit-button="true"
            :show-delete-button="true"
            @add="handleAdd"
            @edit="handleEdit"
            @delete="handleDelete"
          >
            <template #header="{ sort, handleSort, isFieldSortable }">
              <th>
                <button
                  v-if="isFieldSortable('id')"
                  class="btn btn-link p-0 text-decoration-none text-body fw-bold"
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
                  class="btn btn-link p-0 text-decoration-none text-body fw-bold"
                  @click="handleSort('name')"
                >
                  Name
                  <span v-if="sort.field === 'name'">
                    {{ sort.direction === 'asc' ? '↑' : '↓' }}
                  </span>
                </button>
                <span v-else>Name</span>
              </th>
              <th>Description</th>
              <th>Style</th>
              <th>
                <button
                  v-if="isFieldSortable('active')"
                  class="btn btn-link p-0 text-decoration-none text-body fw-bold"
                  @click="handleSort('active')"
                >
                  Status
                  <span v-if="sort.field === 'active'">
                    {{ sort.direction === 'asc' ? '↑' : '↓' }}
                  </span>
                </button>
                <span v-else>Status</span>
              </th>
              <th>Runtime</th>
              <th>Actions</th>
            </template>
            <template #row="row">
              <td>{{ row.item.id }}</td>
              <td>{{ row.item.name }}</td>
              <td class="text-truncate" style="max-width: 200px" :title="row.item.description ?? ''">
                {{ row.item.description ?? '—' }}
              </td>
              <td>
                <span v-if="row.item.style" class="badge bg-secondary">{{ row.item.style }}</span>
                <span v-else>—</span>
              </td>
              <td>
                <span class="badge" :class="row.item.active ? 'bg-success' : 'bg-secondary'">
                  {{ row.item.active ? 'active' : 'inactive' }}
                </span>
              </td>
              <td>
                <span
                  class="badge"
                  :class="row.item.presence === 'online' ? 'bg-success' : 'bg-secondary'"
                >
                  {{ row.item.presence }}
                </span>
              </td>
              <td>
                <div class="d-flex gap-1">
                  <button
                    v-if="row.showEditButton"
                    type="button"
                    class="btn btn-sm btn-outline-primary"
                    title="Edit"
                    aria-label="Edit"
                    @click="row.handleEdit"
                  >
                    <i class="bi bi-pencil" aria-hidden="true"></i>
                  </button>
                  <button
                    v-if="row.showDeleteButton"
                    type="button"
                    class="btn btn-sm btn-outline-danger"
                    title="Delete"
                    aria-label="Delete"
                    @click="row.handleDelete"
                  >
                    <i class="bi bi-trash" aria-hidden="true"></i>
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

  <Modal
    v-model="showModal"
    :title="modalTitle"
    modal-name="admin-bots-modal"
    :modal-type="isCreating ? 'add' : 'edit'"
    :confirm-on-close="isFormDirty"
    @cancel="resetForm"
    @ok="saveBot"
  >
    <form @submit.prevent="saveBot">
      <div class="mb-3">
        <label class="form-label" for="bot-name">Name</label>
        <input
          id="bot-name"
          v-model="formBot.name"
          type="text"
          class="form-control"
          required
          minlength="2"
          maxlength="255"
          data-autofocus
        />
      </div>
      <div class="mb-3">
        <label class="form-label" for="bot-description">Description</label>
        <textarea
          id="bot-description"
          v-model="formBot.description"
          class="form-control"
          rows="2"
          maxlength="1000"
        />
      </div>
      <div class="mb-3">
        <label class="form-label" for="bot-style">Style</label>
        <input
          id="bot-style"
          v-model="formBot.style"
          type="text"
          class="form-control"
          maxlength="100"
          placeholder="e.g. friendly, professional"
        />
      </div>
      <div class="mb-3">
        <label class="form-label" for="bot-active">Status</label>
        <select id="bot-active" v-model="formBot.active" class="form-select">
          <option :value="true">active</option>
          <option :value="false">inactive</option>
        </select>
      </div>
      <div class="mb-0">
        <label class="form-label">Topics / Personality</label>
        <div class="form-control-plaintext small text-muted">
          JSON fields (topics, personality) — edit via API
        </div>
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
        @click="saveBot"
      >
        {{ isCreating ? 'Create' : 'Save' }}
      </LoadingButton>
    </template>
  </Modal>

  <Modal
    v-model="showDeleteModal"
    :title="deleteModalTitle"
    modal-name="admin-bots-delete-modal"
    modal-type="delete"
    :close-on-backdrop="!deleteLoading"
    :close-on-esc="!deleteLoading"
    @cancel="resetDeleteModal"
  >
    <p class="mb-0 text-body-secondary">
      This removes the bot from the database.
    </p>
    <p v-if="deleteTarget?.name" class="mb-0 mt-2">
      <span class="text-break fw-medium">{{ deleteTarget.name }}</span>
    </p>
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-secondary"
        :disabled="deleteLoading"
        @click="requestClose"
      >
        Cancel
      </button>
      <LoadingButton
        type="button"
        variant="btn-danger"
        :loading="deleteLoading"
        :disabled="deleteTarget?.id == null"
        :loading-delay="300"
        @click="confirmDeleteBot"
      >
        Delete
      </LoadingButton>
    </template>
  </Modal>
  <template #placeholder>
    <div class="card flex-grow-1 overflow-auto">
      <div class="card-header"><h5 class="mb-0">Admin Bots</h5></div>
      <div class="card-body">
        <p class="mb-0 text-body-secondary">Access denied for guests.</p>
      </div>
    </div>
  </template>
  </ClientOnly>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useWebSocket } from '@hilos/sdk/plugins/websocket'
import { Table, Modal, LoadingButton } from '@hilos/sdk/components'
import { useConnectionStore, useBrowserStore } from '@hilos/sdk/stores'
import { useBrowserTableDeleteMutationModal } from '@/composables/useBrowserTableDeleteMutationModal'
import { sendAction } from '@/services/websocketActions'
import { BOT_CREATE, BOT_UPDATE, BOT_DELETE } from '@/constants'
import {
  adminBotsFromBrowserRows,
  BROWSER_PAGE_ADMIN_BOTS,
  BROWSER_TABLE_BOTS,
  type AdminBotRow,
} from '@/entities/browserAdminTables'

const connectionStore = useConnectionStore()
const browserStore = useBrowserStore()
const websocket = useWebSocket()

const tableState = computed(() => {
  return browserStore.pages[BROWSER_PAGE_ADMIN_BOTS]?.tables[BROWSER_TABLE_BOTS] ?? null
})
const displayRows = computed(() => adminBotsFromBrowserRows(tableState.value?.rowsByKey))

const showModal = ref(false)
const isCreating = ref(false)
const selectedBot = ref<AdminBotRow | null>(null)
const formBot = ref<AdminBotRow>({
  id: null,
  name: '',
  description: null,
  style: null,
  topics: null,
  personality: null,
  active: true,
  presence: 'offline',
})
const baselineBot = ref<AdminBotRow | null>(null)

const modalTitle = computed(() => isCreating.value ? 'Create Bot' : 'Edit Bot')

const {
  showDeleteModal,
  deleteTarget,
  deleteLoading,
  resetDeleteModal,
  openDeleteModal,
  confirmDelete,
} = useBrowserTableDeleteMutationModal<AdminBotRow>(
  BROWSER_PAGE_ADMIN_BOTS,
  BROWSER_TABLE_BOTS,
  (b) => b.id ?? null,
)

const deleteModalTitle = computed(() => {
  const b = deleteTarget.value
  if (!b) return 'Delete bot'
  return b.name?.trim() ? `Delete · ${b.name}` : 'Delete bot'
})

const cloneBot = (bot: AdminBotRow): AdminBotRow => {
  return JSON.parse(JSON.stringify(bot)) as AdminBotRow
}

const isFormDirty = computed(() => {
  if (isCreating.value) return formBot.value.name.trim().length > 0
  if (!baselineBot.value) return false
  return JSON.stringify(formBot.value) !== JSON.stringify(baselineBot.value)
})

const isFormValid = computed(() => {
  const name = formBot.value.name.trim()
  return name.length >= 2 && name.length <= 255
})

const handleAdd = () => {
  isCreating.value = true
  selectedBot.value = null
  formBot.value = {
    id: null,
    name: '',
    description: null,
    style: null,
    topics: null,
    personality: null,
    active: true,
    presence: 'offline',
  }
  baselineBot.value = null
  showModal.value = true
}

const handleEdit = (item: unknown) => {
  if (typeof item !== 'object' || item === null) return
  const bot = item as AdminBotRow
  isCreating.value = false
  selectedBot.value = bot
  formBot.value = cloneBot(bot)
  baselineBot.value = cloneBot(bot)
  showModal.value = true
}

const handleDelete = (item: unknown) => {
  if (typeof item !== 'object' || item === null) return
  const bot = item as AdminBotRow
  if (bot.id == null) return
  openDeleteModal(bot)
}

const confirmDeleteBot = () => {
  const id = deleteTarget.value?.id
  if (id == null) return
  confirmDelete(() => sendAction(websocket, BOT_DELETE, { id }))
}

const saveLoading = ref(false)

const saveBot = () => {
  if (!isFormValid.value) return
  saveLoading.value = true

  if (isCreating.value) {
    sendAction(websocket, BOT_CREATE, {
      name: formBot.value.name.trim(),
      description: formBot.value.description,
      style: formBot.value.style,
      active: formBot.value.active,
    })
  } else if (selectedBot.value?.id != null) {
    sendAction(websocket, BOT_UPDATE, {
      id: selectedBot.value.id,
      name: formBot.value.name.trim(),
      description: formBot.value.description,
      style: formBot.value.style,
      active: formBot.value.active,
    })
  }

  resetForm()
}

const resetForm = () => {
  showModal.value = false
  isCreating.value = false
  selectedBot.value = null
  baselineBot.value = null
  saveLoading.value = false
  formBot.value = {
    id: null,
    name: '',
    description: null,
    style: null,
    topics: null,
    personality: null,
    active: true,
    presence: 'offline',
  }
}
</script>
