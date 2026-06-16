<!-- The chat bots admin page (PAGE_ADMIN_BOTS) at /hilos/admin_bots: the library
bots table reached from the dashboard's "Chat administration" section. The bots
table is a free CRUD table (not cataloged) — add, edit, or delete a bot; the row
shows live agent presence (online/offline) from the runtime status slot. The table
controller and the row view-model live with the page (adminBotsPage.ts), the
create/update/delete submits in adminBotsActions.ts. Authoritative-backend: a
submit dispatches a tracked action and the dialog closes on its `::success` reply
(useTrackedAction, step 7.4) — robust even when the edit changed nothing; a
failure surfaces in the dialog. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  HilosModal,
  HilosTable,
  LoadingButton,
  useSignal,
  useTrackedAction,
} from '@hilos/vue'
import { type HilosTableColumn } from '@hilos/vue'
import { computed, ref } from 'vue'

import {
  sendBotCreate,
  sendBotDelete,
  sendBotUpdate,
  type BotInput,
} from './adminBotsActions'
import { botsTable } from './adminBotsPage'
import { type BotRow } from './types/tables/BotRow'

defineOptions({ name: 'AdminBotsPage' })

const columns: HilosTableColumn[] = [
  { key: 'name', label: 'Name', sortable: true },
  { key: 'description', label: 'Description' },
  { key: 'presence', label: 'Status', sortable: true },
  { key: 'active', label: 'Active', sortable: true },
  { key: 'actions', label: '', headerClass: 'text-end' },
]

const viewRows = useSignal(botsTable.rows)

const allRows = computed(() => viewRows.value.map((view) => view.row))

// Create/edit dialog: one shared form, distinguished by mode.
const formOpen = ref(false)
const formMode = ref<'create' | 'edit'>('create')
const formId = ref<number | null>(null)
const fName = ref('')
const fDescription = ref('')
const fStyle = ref('')
const fTopics = ref('')
const fPersonality = ref('')
const fActive = ref(true)
const {
  loading: formLoading,
  busy: formBusy,
  error: formError,
  run: runFormAction,
  clearError: clearFormError,
} = useTrackedAction()

// Delete dialog.
const deleteOpen = ref(false)
const deleteRow = ref<BotRow | null>(null)
const {
  loading: deleteLoading,
  busy: deleteBusy,
  error: deleteError,
  run: runDeleteAction,
  clearError: clearDeleteError,
} = useTrackedAction()

/** The form's current fields as a bot input, trimmed and null-normalized. */
function currentInput(): BotInput {
  return {
    name: fName.value.trim(),
    description: fDescription.value.trim() || null,
    style: fStyle.value.trim() || null,
    topics: fTopics.value.trim() || null,
    personality: fPersonality.value.trim() || null,
    active: fActive.value,
  }
}

/** Whether a table row already carries exactly the submitted input. */
function matchesInput(row: BotRow, input: BotInput): boolean {
  return (
    row.name === input.name &&
    row.description === input.description &&
    row.style === input.style &&
    row.topics === input.topics &&
    row.personality === input.personality &&
    row.active === input.active
  )
}

// A create is dirty once any field is filled; an edit once it differs from the
// bot's current live row. confirm-on-close only guards a dirty form.
const formDirty = computed(() => {
  const input = currentInput()
  if (formMode.value === 'create') {
    return !!(
      input.name ||
      input.description ||
      input.style ||
      input.topics ||
      input.personality
    )
  }
  const live = allRows.value.find((row) => row.id === formId.value)

  return live === undefined || !matchesInput(live, input)
})

function openCreate(): void {
  clearFormError()
  formMode.value = 'create'
  formId.value = null
  fName.value = ''
  fDescription.value = ''
  fStyle.value = ''
  fTopics.value = ''
  fPersonality.value = ''
  fActive.value = true
  formOpen.value = true
}

function openEdit(row: BotRow): void {
  clearFormError()
  formMode.value = 'edit'
  formId.value = row.id
  fName.value = row.name
  fDescription.value = row.description ?? ''
  fStyle.value = row.style ?? ''
  fTopics.value = row.topics ?? ''
  fPersonality.value = row.personality ?? ''
  fActive.value = row.active
  formOpen.value = true
}

function closeForm(): void {
  formOpen.value = false
}

// Authoritative-backend: dispatch the tracked action, close only when its
// `::success` reply resolves; a failure stays open with the reason shown.
async function submitForm(): Promise<void> {
  const input = currentInput()
  if (!input.name || formBusy.value) {
    return
  }
  const handle =
    formMode.value === 'create'
      ? sendBotCreate(input)
      : formId.value !== null
        ? sendBotUpdate(formId.value, input)
        : null
  if (handle === null) {
    return
  }
  if (await runFormAction(handle)) {
    closeForm()
  }
}

function openDelete(row: BotRow): void {
  clearDeleteError()
  deleteRow.value = row
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
  if (await runDeleteAction(sendBotDelete(row.id))) {
    closeDelete()
  }
}
</script>

<template>
  <section data-id="admin-bots-view">
    <div class="d-flex justify-content-between align-items-start gap-2 mb-4">
      <div class="d-flex flex-column gap-1">
        <h1 class="h4 mb-0">Bots</h1>
        <p class="mb-0 text-body-secondary">
          Library bots: create, edit, and toggle the chat's automated
          participants.
        </p>
      </div>
      <button
        type="button"
        class="btn btn-primary flex-shrink-0"
        data-id="admin-bots-add"
        @click="openCreate"
      >
        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add bot
      </button>
    </div>

    <HilosTable
      :controller="botsTable"
      :columns="columns"
      searchable
      search-placeholder="Search bots…"
      empty-text="No bots yet."
    >
      <template #row="{ row }">
        <td>
          <span class="fw-medium">{{ row.name }}</span>
        </td>
        <td style="max-width: 18rem">
          <span
            class="text-truncate d-block text-body-secondary"
            :title="row.description ?? ''"
            >{{ row.description ?? '—' }}</span
          >
        </td>
        <td>
          <span
            v-if="row.presence === 'online'"
            class="badge rounded-pill bg-success-subtle text-success-emphasis border border-success-subtle"
            >online</span
          >
          <span
            v-else
            class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"
            >offline</span
          >
        </td>
        <td>
          <span
            v-if="row.active"
            class="badge rounded-pill bg-primary-subtle text-primary-emphasis border border-primary-subtle"
            >active</span
          >
          <span
            v-else
            class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"
            >off</span
          >
        </td>
        <td class="text-end">
          <div class="d-flex gap-1 justify-content-end">
            <button
              type="button"
              class="btn btn-sm btn-outline-primary"
              title="Edit"
              aria-label="Edit"
              :data-id="`admin-bots-edit-${row.id}`"
              @click="openEdit(row)"
            >
              <i class="bi bi-pencil" aria-hidden="true"></i>
            </button>
            <button
              type="button"
              class="btn btn-sm btn-outline-danger"
              title="Delete"
              aria-label="Delete"
              :data-id="`admin-bots-delete-${row.id}`"
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
      :title="formMode === 'create' ? 'Add bot' : `Edit · ${fName}`"
      :confirm-on-close="formDirty"
      @cancel="closeForm"
    >
      <div
        v-if="formError"
        class="alert alert-danger"
        role="alert"
        data-id="admin-bots-error"
      >
        {{ formError }}
      </div>
      <form @submit.prevent="submitForm">
        <div class="mb-3">
          <label class="form-label" for="admin-bots-name">Name</label>
          <input
            id="admin-bots-name"
            v-model="fName"
            type="text"
            class="form-control"
            required
            data-id="admin-bots-name"
          />
        </div>
        <div class="mb-3">
          <label class="form-label" for="admin-bots-description"
            >Description</label
          >
          <textarea
            id="admin-bots-description"
            v-model="fDescription"
            class="form-control"
            rows="2"
            data-id="admin-bots-description"
          ></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label" for="admin-bots-style">Style</label>
          <input
            id="admin-bots-style"
            v-model="fStyle"
            type="text"
            class="form-control"
            data-id="admin-bots-style"
          />
        </div>
        <div class="mb-3">
          <label class="form-label" for="admin-bots-topics">Topics</label>
          <input
            id="admin-bots-topics"
            v-model="fTopics"
            type="text"
            class="form-control"
            data-id="admin-bots-topics"
          />
        </div>
        <div class="mb-3">
          <label class="form-label" for="admin-bots-personality"
            >Personality</label
          >
          <textarea
            id="admin-bots-personality"
            v-model="fPersonality"
            class="form-control"
            rows="2"
            data-id="admin-bots-personality"
          ></textarea>
        </div>
        <div class="form-check form-switch mb-0">
          <input
            id="admin-bots-active"
            v-model="fActive"
            type="checkbox"
            class="form-check-input"
            data-id="admin-bots-active"
          />
          <label class="form-check-label" for="admin-bots-active">Active</label>
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
          :disabled="!fName.trim() || formBusy"
          data-id="admin-bots-save"
          @click="submitForm"
        >
          Save
        </LoadingButton>
      </template>
    </HilosModal>

    <HilosModal
      v-model="deleteOpen"
      :title="deleteRow ? `Delete · ${deleteRow.name}` : 'Delete bot'"
      :close-on-backdrop="!deleteBusy"
      :close-on-esc="!deleteBusy"
      @cancel="closeDelete"
    >
      <div
        v-if="deleteError"
        class="alert alert-danger"
        role="alert"
        data-id="admin-bots-delete-error"
      >
        {{ deleteError }}
      </div>
      <p class="mb-0 text-body-secondary">
        This permanently removes the bot and stops its agent.
      </p>
      <p v-if="deleteRow" class="mb-0 mt-2 fw-medium">{{ deleteRow.name }}</p>
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
          data-id="admin-bots-delete-confirm"
          @click="submitDelete"
        >
          Delete
        </LoadingButton>
      </template>
    </HilosModal>
  </section>
</template>
