<template>
  <div class="row">
    <div class="col-12 col-lg-10 mx-auto">
      <client-only>
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">Moderator prompt pieces</h5>
          <button
            v-if="tableState"
            type="button"
            class="btn btn-outline-light btn-sm"
            title="Refresh table from server"
            aria-label="Refresh"
            @click="refreshTable"
          >
            <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
            Refresh
          </button>
        </div>
        <div class="card-body">
          <Table
            :items="displayRows"
            item-key="id"
            :colspan="4"
            :placeholder-when-empty="!chatStore.isConnected"
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
                  v-if="isFieldSortable('section')"
                  class="btn btn-link p-0 text-decoration-none text-dark fw-bold"
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
  <template #placeholder>
    <div class="card">
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

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useWebSocket } from '@hilos/sdk/plugins/websocket'
import { Table, Modal, LoadingButton } from '@hilos/sdk/components'
import { getTableDisplayRows, getTablePendingChanges, getTableChangeMarkers } from '@hilos/sdk/composables'
import { useChatStore } from '@/stores'
import { sendAction } from '@/services/websocketActions'
import { TableActionConstants } from '@hilos/sdk/constants'
import { MODERATOR_PIECE_CREATE, MODERATOR_PIECE_UPDATE, MODERATOR_PIECE_DELETE } from '@/constants'
import type { ModeratorPromptPieceEntity } from '@/types/domain'

const chatStore = useChatStore()
const websocket = useWebSocket()

const tableKey = 'moderatorPromptPieces'
const tableState = computed(() => chatStore.tableData[tableKey])
const displayRows = computed(() => getTableDisplayRows<ModeratorPromptPieceEntity>(chatStore.tableData[tableKey]))
const pendingChanges = computed(() => getTablePendingChanges(chatStore.tableData[tableKey]))
const changeMarkers = computed(() => getTableChangeMarkers(chatStore.tableData[tableKey]))

const refreshTable = () => {
  sendAction(websocket, TableActionConstants.TABLE_REFRESH, {
    [TableActionConstants.PAYLOAD_KEY_TABLE_KEY]: tableKey,
  })
}

const handleApplyChanges = () => {
  const { hasDeletes } = chatStore.applyPendingMutations(tableKey)
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
  sendAction(websocket, MODERATOR_PIECE_DELETE, { id: piece.id })
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
