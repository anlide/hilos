<!-- The chat moderation admin page (PAGE_ADMIN_MODERATOR) at /hilos/admin_moderator:
the moderator prompt-pieces table reached from the dashboard's "Chat
administration" section. A free CRUD table (not cataloged) — add, edit, or delete
a prompt piece; each piece belongs to a moderation rule section (name / message).
The table controller and the row view-model live with the page
(adminModeratorPage.ts), the create/update/delete submits in
adminModeratorActions.ts. Authoritative-backend: a submit dispatches a tracked
action and the dialog closes on its `::success` reply (useTrackedAction, step
7.4) — robust even when the edit changed nothing; a failure surfaces in the
dialog. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  HilosActionError,
  HilosModal,
  HilosViewportTable,
  LoadingButton,
  useSignal,
  useTrackedAction,
} from '@hilos/vue'
import { type HilosTableColumn } from '@hilos/vue'
import { computed, onMounted, onUnmounted, ref } from 'vue'

import {
  sendModeratorPieceCreate,
  sendModeratorPieceDelete,
  sendModeratorPieceUpdate,
  type ModeratorPieceInput,
} from './adminModeratorActions'
import {
  disposeModeratorPiecesTable,
  moderatorPiecesTable,
  startModeratorPiecesTable,
} from './adminModeratorPage'
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

// Live (non-placeholder) rows, used to dirty-check an edit against the latest row.
const allRows = computed(() =>
  viewRows.value
    .map((view) => view.row)
    .filter((row): row is ModeratorPieceRow => row !== null),
)

// Bind the server-windowed table to the connection on mount, request the first
// window, and unbind on unmount.
onMounted(startModeratorPiecesTable)
onUnmounted(disposeModeratorPiecesTable)

// Create/edit dialog: one shared form, distinguished by mode.
const formOpen = ref(false)
const formMode = ref<'create' | 'edit'>('create')
const formId = ref<number | null>(null)
const fSection = ref<ModeratorSection>('message_rule')
const fPromptPiece = ref('')
const formAction = useTrackedAction()
const {
  loading: formLoading,
  busy: formBusy,
  run: runFormAction,
  clearError: clearFormError,
} = formAction

// Delete dialog.
const deleteOpen = ref(false)
const deleteRow = ref<ModeratorPieceRow | null>(null)
const deleteAction = useTrackedAction()
const {
  loading: deleteLoading,
  busy: deleteBusy,
  run: runDeleteAction,
  clearError: clearDeleteError,
} = deleteAction

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
// piece's current live row. confirm-on-close only guards a dirty form.
const formDirty = computed(() => {
  const input = currentInput()
  if (formMode.value === 'create') {
    return !!input.promptPiece
  }
  const live = allRows.value.find((row) => row.id === formId.value)

  return live === undefined || !matchesInput(live, input)
})

function openCreate(): void {
  clearFormError()
  formMode.value = 'create'
  formId.value = null
  fSection.value = 'message_rule'
  fPromptPiece.value = ''
  formOpen.value = true
}

function openEdit(row: ModeratorPieceRow): void {
  // Flush pending so the form edits the latest committed row; a row removed by
  // someone else (now a placeholder) declines to open.
  const fresh = moderatorPiecesTable.applyAndResolve(String(row.id))
  if (!fresh) {
    return
  }
  clearFormError()
  formMode.value = 'edit'
  formId.value = fresh.id
  fSection.value = fresh.section
  fPromptPiece.value = fresh.promptPiece
  formOpen.value = true
}

function closeForm(): void {
  formOpen.value = false
}

// Authoritative-backend: dispatch the tracked action, close only when its
// `::success` reply resolves; a failure stays open with the reason shown.
async function submitForm(): Promise<void> {
  const input = currentInput()
  if (!input.promptPiece || formBusy.value) {
    return
  }
  const handle =
    formMode.value === 'create'
      ? sendModeratorPieceCreate(input)
      : formId.value !== null
        ? sendModeratorPieceUpdate(formId.value, input)
        : null
  if (handle === null) {
    return
  }
  if (await runFormAction(handle)) {
    closeForm()
  }
}

function openDelete(row: ModeratorPieceRow): void {
  // Flush pending; a row already removed by someone else does not open a delete.
  const fresh = moderatorPiecesTable.applyAndResolve(String(row.id))
  if (!fresh) {
    return
  }
  clearDeleteError()
  deleteRow.value = fresh
  deleteOpen.value = true
}

function closeDelete(): void {
  deleteOpen.value = false
}

async function submitDelete(): Promise<void> {
  const row = deleteRow.value
  if (!row || deleteBusy.value) {
    return
  }
  if (await runDeleteAction(sendModeratorPieceDelete(row.id))) {
    closeDelete()
  }
}
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

    <HilosViewportTable
      label="Prompt pieces"
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
    </HilosViewportTable>

    <HilosModal
      v-model="formOpen"
      :title="formMode === 'create' ? 'Add prompt piece' : 'Edit prompt piece'"
      :confirm-on-close="formDirty"
      @cancel="closeForm"
    >
      <HilosActionError :action="formAction" />
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
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="formBusy"
          @click="requestClose"
        >
          Cancel
        </button>
        <LoadingButton
          class="btn-primary"
          :loading="formLoading"
          :disabled="!fPromptPiece.trim() || formBusy"
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
      :close-on-backdrop="!deleteBusy"
      :close-on-esc="!deleteBusy"
      @cancel="closeDelete"
    >
      <HilosActionError :action="deleteAction" />
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
          :disabled="deleteBusy"
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
