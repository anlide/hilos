<!-- The chat moderation admin page (PAGE_ADMIN_MODERATOR) at /hilos/admin_moderator:
the moderator prompt-pieces table reached from the dashboard's "Chat
administration" section. A free CRUD table (not cataloged) — add, edit, or delete
a prompt piece; each piece belongs to a moderation rule section (name / message).
The table controller and the row view-model live with the page
(adminModeratorPage.ts), the create/update/delete submits in
adminModeratorActions.ts. Authoritative-backend: a submit blocks the button and
the dialog closes only when the change echoes back over the live table (no
optimism); a failure surfaces in the banner. Bootstrap classes only
(styling-rules.md). -->
<script setup lang="ts">
import { HilosModal, HilosTable, LoadingButton, useSignal } from '@hilos/vue'
import { type HilosTableColumn } from '@hilos/vue'
import { computed, ref, watch } from 'vue'

import {
  clearModeratorError,
  moderatorError,
  sendModeratorPieceCreate,
  sendModeratorPieceDelete,
  sendModeratorPieceUpdate,
  type ModeratorPieceInput,
} from './adminModeratorActions'
import { moderatorPiecesTable } from './adminModeratorPage'
import {
  type ModeratorPieceRow,
  type ModeratorSection,
} from './types/tables/ModeratorPieceRow'

defineOptions({ name: 'AdminModeratorPage' })

const columns: HilosTableColumn[] = [
  { key: 'section', label: 'Section', sortable: true },
  { key: 'promptPiece', label: 'Prompt piece' },
  { key: 'actions', label: '', headerClass: 'text-end' },
]

const viewRows = useSignal(moderatorPiecesTable.rows)
const error = useSignal(moderatorError)

const allRows = computed(() => viewRows.value.map((view) => view.row))

// Create/edit dialog: one shared form, distinguished by mode.
const formOpen = ref(false)
const formMode = ref<'create' | 'edit'>('create')
const formId = ref<number | null>(null)
const fSection = ref<ModeratorSection>('message_rule')
const fPromptPiece = ref('')
const formLoading = ref(false)

// Delete dialog.
const deleteOpen = ref(false)
const deleteRow = ref<ModeratorPieceRow | null>(null)
const deleteLoading = ref(false)

// Authoritative-backend success: close once the change echoes over the table.
const pendingCreateCount = ref<number | null>(null)
const pendingEdit = ref<{ id: number; input: ModeratorPieceInput } | null>(null)
const pendingDeleteId = ref<number | null>(null)

/** The form's current fields as a piece input, prompt trimmed. */
function currentInput(): ModeratorPieceInput {
  return { section: fSection.value, promptPiece: fPromptPiece.value.trim() }
}

/** Whether a table row already carries exactly the submitted input. */
function matchesInput(
  row: ModeratorPieceRow,
  input: ModeratorPieceInput,
): boolean {
  return row.section === input.section && row.promptPiece === input.promptPiece
}

// A create is dirty once the prompt is filled; an edit once it differs from the
// piece's current live row (which updates on a successful save, so the dialog goes
// clean again once the backend echoes the change — letting the success watcher's
// close go through; confirm-on-close only guards a dirty form).
const formDirty = computed(() => {
  const input = currentInput()
  if (formMode.value === 'create') {
    return !!input.promptPiece
  }
  const live = allRows.value.find((row) => row.id === formId.value)

  return live === undefined || !matchesInput(live, input)
})

function openCreate(): void {
  clearModeratorError()
  formMode.value = 'create'
  formId.value = null
  fSection.value = 'message_rule'
  fPromptPiece.value = ''
  formLoading.value = false
  formOpen.value = true
}

function openEdit(row: ModeratorPieceRow): void {
  clearModeratorError()
  formMode.value = 'edit'
  formId.value = row.id
  fSection.value = row.section
  fPromptPiece.value = row.promptPiece
  formLoading.value = false
  formOpen.value = true
}

function closeForm(): void {
  formOpen.value = false
  formLoading.value = false
}

function submitForm(): void {
  const input = currentInput()
  if (!input.promptPiece || formLoading.value) {
    return
  }
  if (formMode.value === 'create') {
    pendingCreateCount.value = allRows.value.length
    const sent = sendModeratorPieceCreate(input)
    formLoading.value = sent
    if (!sent) {
      pendingCreateCount.value = null
    }
  } else if (formId.value !== null) {
    pendingEdit.value = { id: formId.value, input }
    const sent = sendModeratorPieceUpdate(formId.value, input)
    formLoading.value = sent
    if (!sent) {
      pendingEdit.value = null
    }
  }
}

function openDelete(row: ModeratorPieceRow): void {
  clearModeratorError()
  deleteRow.value = row
  deleteLoading.value = false
  deleteOpen.value = true
}

function closeDelete(): void {
  deleteOpen.value = false
  deleteLoading.value = false
}

function submitDelete(): void {
  const row = deleteRow.value
  if (!row || deleteLoading.value) {
    return
  }
  pendingDeleteId.value = row.id
  deleteLoading.value = sendModeratorPieceDelete(row.id)
  if (!deleteLoading.value) {
    pendingDeleteId.value = null
  }
}

// Success is state-driven: a create lands once the table grows; an edit once the
// row carries the submitted fields; a delete once the row leaves the table.
watch(allRows, (rows) => {
  if (
    pendingCreateCount.value !== null &&
    rows.length > pendingCreateCount.value
  ) {
    pendingCreateCount.value = null
    closeForm()
  }
  if (pendingEdit.value !== null) {
    const pending = pendingEdit.value
    const row = rows.find((candidate) => candidate.id === pending.id)
    if (row && matchesInput(row, pending.input)) {
      pendingEdit.value = null
      closeForm()
    }
  }
  if (
    pendingDeleteId.value !== null &&
    !rows.find((candidate) => candidate.id === pendingDeleteId.value)
  ) {
    pendingDeleteId.value = null
    closeDelete()
  }
})

// A rejected action releases every button and keeps the dialog open to retry.
watch(error, (reason) => {
  if (reason !== null) {
    formLoading.value = false
    deleteLoading.value = false
    pendingCreateCount.value = null
    pendingEdit.value = null
    pendingDeleteId.value = null
  }
})
</script>

<template>
  <section data-id="admin-moderator-view">
    <div class="d-flex justify-content-between align-items-start gap-2 mb-4">
      <div class="d-flex flex-column gap-1">
        <h1 class="h4 mb-0">Moderation</h1>
        <p class="mb-0 text-body-secondary">
          Moderator prompt pieces: the rule fragments the moderator agent
          applies to names and messages.
        </p>
      </div>
      <button
        type="button"
        class="btn btn-primary flex-shrink-0"
        data-id="admin-moderator-add"
        @click="openCreate"
      >
        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add piece
      </button>
    </div>

    <div
      v-if="error"
      class="alert alert-danger"
      role="alert"
      data-id="admin-moderator-error"
    >
      {{ error }}
    </div>

    <HilosTable
      :controller="moderatorPiecesTable"
      :columns="columns"
      searchable
      search-placeholder="Search prompt pieces…"
      empty-text="No prompt pieces yet."
    >
      <template #row="{ row }">
        <td>
          <span
            v-if="row.section === 'name_rule'"
            class="badge rounded-pill bg-info-subtle text-info-emphasis border border-info-subtle"
            >name rule</span
          >
          <span
            v-else
            class="badge rounded-pill bg-primary-subtle text-primary-emphasis border border-primary-subtle"
            >message rule</span
          >
        </td>
        <td style="max-width: 28rem">
          <span class="text-truncate d-block" :title="row.promptPiece">{{
            row.promptPiece
          }}</span>
        </td>
        <td class="text-end">
          <div class="d-flex gap-1 justify-content-end">
            <button
              type="button"
              class="btn btn-sm btn-outline-primary"
              title="Edit"
              aria-label="Edit"
              :data-id="`admin-moderator-edit-${row.id}`"
              @click="openEdit(row)"
            >
              <i class="bi bi-pencil" aria-hidden="true"></i>
            </button>
            <button
              type="button"
              class="btn btn-sm btn-outline-danger"
              title="Delete"
              aria-label="Delete"
              :data-id="`admin-moderator-delete-${row.id}`"
              @click="openDelete(row)"
            >
              <i class="bi bi-trash" aria-hidden="true"></i>
            </button>
          </div>
        </td>
      </template>
    </HilosTable>

    <HilosModal
      v-model="formOpen"
      :title="formMode === 'create' ? 'Add prompt piece' : 'Edit prompt piece'"
      :confirm-on-close="formDirty"
      @cancel="closeForm"
    >
      <form @submit.prevent="submitForm">
        <div class="mb-3">
          <label class="form-label" for="admin-moderator-section"
            >Section</label
          >
          <select
            id="admin-moderator-section"
            v-model="fSection"
            class="form-select"
            data-id="admin-moderator-section"
          >
            <option value="name_rule">Name rule</option>
            <option value="message_rule">Message rule</option>
          </select>
        </div>
        <div class="mb-0">
          <label class="form-label" for="admin-moderator-prompt"
            >Prompt piece</label
          >
          <textarea
            id="admin-moderator-prompt"
            v-model="fPromptPiece"
            class="form-control"
            rows="4"
            required
            data-id="admin-moderator-prompt"
          ></textarea>
        </div>
      </form>
      <template #actions="{ requestClose }">
        <button type="button" class="btn btn-secondary" @click="requestClose">
          Cancel
        </button>
        <LoadingButton
          class="btn-primary"
          :loading="formLoading"
          :disabled="!fPromptPiece.trim()"
          data-id="admin-moderator-save"
          @click="submitForm"
        >
          Save
        </LoadingButton>
      </template>
    </HilosModal>

    <HilosModal
      v-model="deleteOpen"
      title="Delete prompt piece"
      :close-on-backdrop="!deleteLoading"
      :close-on-esc="!deleteLoading"
      @cancel="closeDelete"
    >
      <p class="mb-0 text-body-secondary">
        This permanently removes the prompt piece from the moderation rules.
      </p>
      <p v-if="deleteRow" class="mb-0 mt-2 text-truncate">
        {{ deleteRow.promptPiece }}
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
          class="btn-danger"
          :loading="deleteLoading"
          data-id="admin-moderator-delete-confirm"
          @click="submitDelete"
        >
          Delete
        </LoadingButton>
      </template>
    </HilosModal>
  </section>
</template>
