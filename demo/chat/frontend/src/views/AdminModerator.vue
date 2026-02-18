<template>
  <div class="row">
    <div class="col-12 col-lg-10 mx-auto">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">Moderator prompt pieces</h5>
          <button
            v-if="tableMeta"
            type="button"
            class="btn btn-outline-light btn-sm"
            title="Refresh table from server"
            aria-label="Refresh"
            @click="updateSnapshot"
          >
            <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
            Refresh
          </button>
        </div>
        <div class="card-body">
          <Table
            :items="items"
            item-key="id"
            :colspan="4"
            :searchable="true"
            search-placeholder="Search prompt pieces..."
            :search-fields="['id', 'section', 'promptPiece']"
            :sortable="true"
            :sortable-fields="['id', 'section']"
            :paginated="false"
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
                <div v-if="row.showEditButton" class="d-flex gap-1">
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-primary"
                    title="Edit"
                    aria-label="Edit"
                    @click="row.handleEdit"
                  >
                    <i class="bi bi-pencil" aria-hidden="true"></i>
                    <span class="visually-hidden">Edit</span>
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
    </div>
  </div>

  <Modal
    v-model="showModal"
    :title="modalTitle"
    modal-name="admin-moderator-modal"
    modal-type="edit"
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
import type { ModeratorPromptPieceEntity } from '@/types/domain'

const chatStore = useChatStore()
const websocket = useWebSocket()

const tableKey = 'moderatorPromptPieces'
const tableMeta = computed(() => chatStore.tableData[tableKey])
const items = computed(() => {
  const data = tableMeta.value
  if (!data || !Array.isArray(data.rows)) return []
  return data.rows as ModeratorPromptPieceEntity[]
})

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
const selectedPiece = ref<ModeratorPromptPieceEntity | null>(null)
const formPiece = ref<ModeratorPromptPieceEntity>({
  id: null,
  section: 'message_rule',
  promptPiece: ''
})
const baselinePiece = ref<ModeratorPromptPieceEntity | null>(null)

const modalTitle = computed(() => 'Edit prompt piece')

const clonePiece = (piece: ModeratorPromptPieceEntity): ModeratorPromptPieceEntity => {
  return JSON.parse(JSON.stringify(piece)) as ModeratorPromptPieceEntity
}

const isFormDirty = computed(() => {
  if (!baselinePiece.value) return false
  return JSON.stringify(formPiece.value) !== JSON.stringify(baselinePiece.value)
})

const isFormValid = computed(() => {
  return formPiece.value.promptPiece.trim().length > 0
})

const updateSnapshot = () => {
  sendAction(websocket, TableActionConstants.ACTION_REFRESH_SNAPSHOT, {
    [TableActionConstants.PAYLOAD_KEY_TABLE_KEY]: tableKey,
  })
}

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

const handleEdit = (item: unknown) => {
  if (typeof item !== 'object' || item === null) return
  const piece = item as ModeratorPromptPieceEntity
  selectedPiece.value = piece
  formPiece.value = clonePiece(piece)
  baselinePiece.value = clonePiece(piece)
  showModal.value = true
}

const saveLoading = ref(false)

const savePiece = () => {
  if (!selectedPiece.value || !isFormValid.value) return
  saveLoading.value = true
  // TODO: send update action to backend when API is available
  resetForm()
}

const resetForm = () => {
  showModal.value = false
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
