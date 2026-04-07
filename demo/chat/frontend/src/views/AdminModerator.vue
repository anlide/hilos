<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div class="col-12 col-lg-10 mx-auto d-flex flex-column h-100 min-h-0">
      <client-only>
      <div class="card d-flex flex-column flex-grow-1 min-h-0 overflow-hidden">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">Moderator prompt pieces</h5>
          <TableRefreshToolbarButton
            v-if="tableState"
            :loading="refreshLoading"
            aria-label="Refresh"
            @click="refreshTable"
          />
        </div>
        <div class="card-body overflow-auto">
          <Table
            :items="displayRows"
            item-key="id"
            :colspan="4"
            :placeholder-when-empty="!connectionStore.isConnected"
            :searchable="true"
            search-placeholder="Search prompt pieces..."
            :search-fields="['id', 'section', 'promptPiece']"
            :sortable="true"
            :sortable-fields="['id', 'section']"
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
            @update-snapshot="handleApplyChanges"
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
                  v-if="isFieldSortable('section')"
                  class="btn btn-link p-0 text-decoration-none text-body fw-bold"
                  @click="handleSort('section')"
                >
                  Section
                  <span v-if="sort.field === 'section'">
                    {{ sort.direction === 'asc' ? '↑' : '↓' }}
                  </span>
                </button>
                <span v-else>Section</span>
              </th>
              <th>Prompt</th>
              <th>Actions</th>
            </template>
            <template #row="row">
              <td>{{ row.item.id }}</td>
              <td>
                <span class="badge" :class="getSectionBadgeClass(row.item.section)">
                  {{ row.item.section }}
                </span>
              </td>
              <td class="text-truncate" style="max-width: 300px" :title="row.item.promptPiece">
                {{ row.item.promptPiece }}
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
              <p class="mb-0">No moderator prompt pieces found</p>
            </template>
          </Table>
        </div>
      </div>

  <Modal
    v-model="showModal"
    :title="modalTitle"
    modal-name="admin-moderator-modal"
    :modal-type="isCreating ? 'add' : 'edit'"
    :confirm-on-close="isFormDirty"
    @cancel="resetForm"
    @ok="savePiece"
  >
    <form @submit.prevent="savePiece">
      <div class="mb-3">
        <label class="form-label" for="piece-section">Section</label>
        <select id="piece-section" v-model="formPiece.section" class="form-select" required data-autofocus>
          <option value="name_rule">name_rule</option>
          <option value="message_rule">message_rule</option>
        </select>
      </div>
      <div class="mb-0">
        <label class="form-label" for="piece-prompt">Prompt</label>
        <textarea
          id="piece-prompt"
          v-model="formPiece.promptPiece"
          class="form-control"
          rows="6"
          required
        />
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
        @click="savePiece"
      >
        {{ isCreating ? 'Create' : 'Save' }}
      </LoadingButton>
    </template>
  </Modal>

  <Modal
    v-model="showDeleteModal"
    :title="deleteModalTitle"
    modal-name="admin-moderator-delete-modal"
    modal-type="delete"
    :close-on-backdrop="!deleteLoading"
    :close-on-esc="!deleteLoading"
    @cancel="resetDeleteModal"
  >
    <p class="mb-0 text-body-secondary">
      This removes the prompt piece from the database.
    </p>
    <p v-if="deleteTarget" class="mb-0 mt-2 small text-break">
      <span class="badge me-1" :class="getSectionBadgeClass(deleteTarget.section)">{{ deleteTarget.section }}</span>
      <span>{{ deleteTarget.promptPiece }}</span>
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
        @click="confirmDeletePiece"
      >
        Delete
      </LoadingButton>
    </template>
  </Modal>
  <template #placeholder>
    <div class="card flex-grow-1 overflow-auto">
      <div class="card-header"><h5 class="mb-0">Moderator prompt pieces</h5></div>
      <div class="card-body">
        <p class="mb-0 text-body-secondary">Access denied for guests.</p>
      </div>
    </div>
  </template>
  </client-only>
    </div>
  </div>
</template>

<!-- TODO: extract useTableCrud composable after conflict resolution feature is implemented -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { useWebSocket } from '@hilos/sdk/plugins/websocket'
import { Table, Modal, LoadingButton } from '@hilos/sdk/components'
import { getTableDisplayRows, getTablePendingChanges, getTableChangeMarkers } from '@hilos/sdk/composables'
import { useTableDeleteMutationModal } from '@/composables/useTableDeleteMutationModal'
import { useTableRefresh } from '@/composables/useTableRefresh'
import TableRefreshToolbarButton from '@/components/TableRefreshToolbarButton.vue'
import { useConnectionStore, useTableStore } from '@hilos/sdk/stores'
import { sendAction } from '@/services/websocketActions'
import { MODERATOR_PIECE_CREATE, MODERATOR_PIECE_UPDATE, MODERATOR_PIECE_DELETE } from '@/constants'
import type { ModeratorPromptPieceEntity } from '@/types/domain'

const connectionStore = useConnectionStore()
const tableStore = useTableStore()
const websocket = useWebSocket()

const tableKey = 'moderatorPromptPieces'
const { refreshLoading, refreshTable } = useTableRefresh(tableKey)
const tableState = computed(() => tableStore.tableData[tableKey])
const displayRows = computed(() => getTableDisplayRows<ModeratorPromptPieceEntity>(tableStore.tableData[tableKey]))
const pendingChanges = computed(() => getTablePendingChanges(tableStore.tableData[tableKey]))
const changeMarkers = computed(() => getTableChangeMarkers(tableStore.tableData[tableKey]))

const handleApplyChanges = () => {
  const { hasDeletes } = tableStore.applyPendingMutations(tableKey)
  if (hasDeletes) {
    refreshTable()
  }
}

const showModal = ref(false)
const isCreating = ref(false)
const selectedPiece = ref<ModeratorPromptPieceEntity | null>(null)
const formPiece = ref<ModeratorPromptPieceEntity>({
  id: null,
  section: 'message_rule',
  promptPiece: ''
})
const baselinePiece = ref<ModeratorPromptPieceEntity | null>(null)

const modalTitle = computed(() => isCreating.value ? 'Create prompt piece' : 'Edit prompt piece')

const {
  showDeleteModal,
  deleteTarget,
  deleteLoading,
  resetDeleteModal,
  openDeleteModal,
  confirmDelete,
} = useTableDeleteMutationModal<ModeratorPromptPieceEntity>(tableKey, (p) => p.id ?? null)

const deleteModalTitle = computed(() => {
  const p = deleteTarget.value
  if (!p?.id) return 'Delete prompt piece'
  return `Delete · #${p.id} (${p.section})`
})

const clonePiece = (piece: ModeratorPromptPieceEntity): ModeratorPromptPieceEntity => {
  return JSON.parse(JSON.stringify(piece)) as ModeratorPromptPieceEntity
}

const isFormDirty = computed(() => {
  if (isCreating.value) return formPiece.value.promptPiece.trim().length > 0
  if (!baselinePiece.value) return false
  return JSON.stringify(formPiece.value) !== JSON.stringify(baselinePiece.value)
})

const isFormValid = computed(() => {
  return formPiece.value.promptPiece.trim().length > 0
})

const getSectionBadgeClass = (section: string | null | undefined): string => {
  switch (section) {
    case 'name_rule':
      return 'bg-info'
    case 'message_rule':
      return 'bg-primary'
    default:
      return 'bg-secondary'
  }
}

const handleAdd = () => {
  isCreating.value = true
  selectedPiece.value = null
  formPiece.value = {
    id: null,
    section: 'message_rule',
    promptPiece: '',
  }
  baselinePiece.value = null
  showModal.value = true
}

const handleEdit = (item: unknown) => {
  if (typeof item !== 'object' || item === null) return
  const piece = item as ModeratorPromptPieceEntity
  isCreating.value = false
  selectedPiece.value = piece
  formPiece.value = clonePiece(piece)
  baselinePiece.value = clonePiece(piece)
  showModal.value = true
}

const handleDelete = (item: unknown) => {
  if (typeof item !== 'object' || item === null) return
  const piece = item as ModeratorPromptPieceEntity
  if (piece.id == null) return
  openDeleteModal(piece)
}

const confirmDeletePiece = () => {
  const id = deleteTarget.value?.id
  if (id == null) return
  confirmDelete(() => sendAction(websocket, MODERATOR_PIECE_DELETE, { id }))
}

const saveLoading = ref(false)

const savePiece = () => {
  if (!isFormValid.value) return
  saveLoading.value = true

  if (isCreating.value) {
    sendAction(websocket, MODERATOR_PIECE_CREATE, {
      section: formPiece.value.section,
      promptPiece: formPiece.value.promptPiece.trim(),
    })
  } else if (selectedPiece.value?.id != null) {
    sendAction(websocket, MODERATOR_PIECE_UPDATE, {
      id: selectedPiece.value.id,
      section: formPiece.value.section,
      promptPiece: formPiece.value.promptPiece.trim(),
    })
  }

  resetForm()
}

const resetForm = () => {
  showModal.value = false
  isCreating.value = false
  selectedPiece.value = null
  baselinePiece.value = null
  saveLoading.value = false
  formPiece.value = {
    id: null,
    section: 'message_rule',
    promptPiece: ''
  }
}
</script>
