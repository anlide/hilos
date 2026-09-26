<!-- The chat bots admin page (PAGE_ADMIN_BOTS) at /hilos/app/bots: the library
bots table reached from the dashboard's "Chat administration" section. The
heading, the lead and the breadcrumb come from the page catalog on the backend
through the framework's HilosAdminPage shell. The bots
table is a free CRUD table (not cataloged) — add, edit, or delete a bot; the row
shows live agent presence (online/offline) from the runtime status slot. The table
controller and the row view-model live with the page (adminBotsPage.ts), the
create/update/delete submits in adminBotsActions.ts. Authoritative-backend: a
submit dispatches a tracked action and the dialog closes on its `::success` reply
(useTrackedAction, step 7.4); a failure surfaces in the dialog. The edit and the
delete dialogs merge against the live row through the shared row-edit helper
(rowEdit.ts, conflict-resolution.md) and say what happened elsewhere on one line
of room held in advance (HilosEditNotice); Save stays locked while nothing
changed. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  ConflictActions,
  ConflictHeader,
  HilosActionError,
  HilosAdminPage,
  HilosEditNotice,
  HilosModal,
  HilosViewportTable,
  LoadingButton,
  useSignal,
  useTrackedAction,
} from '@hilos/vue'
import { type HilosTableColumn } from '@hilos/vue'
import {
  keepMineRowEdit,
  openRowEdit,
  resolveRowEdit,
  takeTheirsRowEdit,
  type RowEditBaseline,
  type RowEditState,
  type RowEditStep,
} from '@hilos/core'
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'

import {
  sendBotCreate,
  sendBotDelete,
  sendBotUpdate,
  type BotInput,
} from './adminBotsActions'
import { PAGE_ADMIN_BOTS } from '../../pages/keys'
import { botsTable, disposeBotsTable, startBotsTable } from './adminBotsPage'
import { type BotRow } from './types/tables/BotRow'

defineOptions({ name: 'AdminBotsPage' })

const columns: HilosTableColumn[] = [
  { key: 'name', label: 'Name', sortable: true },
  { key: 'description', label: 'Description' },
  // Sort key is the backend row field (`status`: joined/left), which groups the
  // same as the rendered online/offline presence.
  { key: 'status', label: 'Status', sortable: true },
  { key: 'active', label: 'Active', sortable: true },
  { key: 'actions', label: '', headerClass: 'text-end' },
]

// The row the open dialog holds in focus, which the server follows wherever it
// goes; undefined once the row is gone. The edit form and the delete dialog both
// read it: one dialog is open at a time, and it is the one holding the focus.
const focusedRow = useSignal(botsTable.focusedRow)

// Bind the server-windowed table to the connection on mount, request the first
// window, and unbind on unmount.
onMounted(startBotsTable)
onUnmounted(disposeBotsTable)

/** The labels of the edited fields as the form shows them. */
const FIELD_LABELS: Record<keyof BotInput, string> = {
  name: 'Name',
  description: 'Description',
  style: 'Style',
  topics: 'Topics',
  personality: 'Personality',
  active: 'Active',
}

/** A field's value as the table cell shows it: nothing reads "—", the switch reads active / off. */
function cellText(value: BotInput[keyof BotInput]): string {
  if (typeof value === 'boolean') {
    return value ? 'active' : 'off'
  }

  return value === null || value === '' ? '—' : value
}

/**
 * The one line the edit dialog says about the other side, naming the fields
 * it is about in the order of the form.
 */
function noticeText(live: RowEditState<BotInput>): string {
  const notice = live.notice
  switch (notice?.kind) {
    case 'deleted':
      return 'Deleted elsewhere — your text stays to copy.'
    case 'conflict':
      return notice.fields
        .map(
          (field) =>
            `${FIELD_LABELS[field]} changed elsewhere to "${cellText(live.fields[field].incoming)}".`,
        )
        .join(' ')
    case 'updated':
      return `Updated just now: ${notice.fields.map((field) => FIELD_LABELS[field]).join(', ')}`
    default:
      return ''
  }
}

/** The edited fields of a row, in the order of the form — the helper names them in this order. */
function editFields(row: BotRow): BotInput {
  return {
    name: row.name,
    description: row.description,
    style: row.style,
    topics: row.topics,
    personality: row.personality,
    active: row.active,
  }
}

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
const editBaseline = ref<RowEditBaseline<BotInput>>(
  openRowEdit<BotInput>({
    name: '',
    description: null,
    style: null,
    topics: null,
    personality: null,
    active: true,
  }),
)
const formAction = useTrackedAction()
const {
  loading: formLoading,
  busy: formBusy,
  run: runFormAction,
  clearError: clearFormError,
} = formAction

// Delete dialog.
const deleteOpen = ref(false)
const deleteRow = ref<BotRow | null>(null)
const deleteAction = useTrackedAction()
const {
  loading: deleteLoading,
  busy: deleteBusy,
  run: runDeleteAction,
  clearError: clearDeleteError,
} = deleteAction

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

const editing = computed(() => formMode.value === 'edit')
// What the form's refusal details are headed with: adding and saving fail
// differently, and the panel names which one did.
const formRefusalTitle = computed(() =>
  editing.value ? "Couldn't save" : "Couldn't add the bot",
)
// The live row the edit dialog is about, projected onto the edited fields; gone
// once the row is. resolveBotRow already normalizes an empty optional to null,
// the way currentInput() does, so an untouched field never reads as changed. An
// add has no row to follow.
const liveRow = computed(() => (editing.value ? focusedRow.value : undefined))
const live = computed(() =>
  resolveRowEdit(
    liveRow.value ? editFields(liveRow.value) : undefined,
    editBaseline.value,
    currentInput(),
  ),
)
// Everything the helper says holds for an edit only: an add compares against
// nothing and keeps its own rules below.
const formConflict = computed(() => editing.value && live.value.conflict)
const formGone = computed(() => editing.value && live.value.gone)
const formNotice = computed(() =>
  editing.value ? (live.value.notice?.kind ?? null) : null,
)
const formNoticeText = computed(() =>
  editing.value ? noticeText(live.value) : '',
)
const saveLabel = computed(() => (formGone.value ? 'Deleted' : 'Save'))

// A create is dirty once any field is filled; an edit once the draft differs
// from the live row. confirm-on-close only guards a dirty form.
const formDirty = computed(() => {
  if (!editing.value) {
    const input = currentInput()

    return !!(
      input.name ||
      input.description ||
      input.style ||
      input.topics ||
      input.personality
    )
  }

  return live.value.dirty
})

// Save is locked while there is nothing to save: an empty name, a save in
// flight, an edit that changed nothing, a row that is gone
// (rules-and-violations.md, section E).
const saveDisabled = computed(
  () =>
    !fName.value.trim() ||
    formBusy.value ||
    (editing.value && (!live.value.dirty || live.value.gone)),
)

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
  // Flush pending and take the row into focus, so the form edits the latest
  // committed row and follows it from here; a row removed by someone else (now a
  // placeholder) declines to open.
  const fresh = botsTable.focusRow(String(row.id))
  if (!fresh) {
    return
  }
  clearFormError()
  formMode.value = 'edit'
  formId.value = fresh.id
  fName.value = fresh.name
  fDescription.value = fresh.description ?? ''
  fStyle.value = fresh.style ?? ''
  fTopics.value = fresh.topics ?? ''
  fPersonality.value = fresh.personality ?? ''
  fActive.value = fresh.active
  editBaseline.value = openRowEdit<BotInput>(editFields(fresh))
  formOpen.value = true
}

function closeForm(): void {
  formOpen.value = false
  botsTable.releaseFocus()
}

// Put a step of the helper into the form: the snapshot moves, and every value
// the step takes lands in its field the way the form opened with it.
function applyStep(step: RowEditStep<BotInput>): void {
  editBaseline.value = step.baseline
  const take = step.take
  if (take.name !== undefined) {
    fName.value = take.name
  }
  if (take.description !== undefined) {
    fDescription.value = take.description ?? ''
  }
  if (take.style !== undefined) {
    fStyle.value = take.style ?? ''
  }
  if (take.topics !== undefined) {
    fTopics.value = take.topics ?? ''
  }
  if (take.personality !== undefined) {
    fPersonality.value = take.personality ?? ''
  }
  if (take.active !== undefined) {
    fActive.value = take.active
  }
}

// The helper hands a step whenever the other side moved a field the person
// left alone, or both arrived at the same value; the dialog applies it at once.
watch(
  () => live.value.settle,
  (settle) => {
    if (formOpen.value && editing.value && settle) {
      applyStep(settle)
    }
  },
)

function acceptMine(): void {
  editBaseline.value = keepMineRowEdit(live.value, editBaseline.value)
}

function acceptTheirs(): void {
  applyStep(takeTheirsRowEdit(live.value, editBaseline.value))
}

// Authoritative-backend: dispatch the tracked action, close only when its
// `::success` reply resolves; a failure stays open with the reason shown. An
// edit that changed nothing closes without a round trip — there is nothing to
// save (rules-and-violations.md, section E).
async function submitForm(): Promise<void> {
  const input = currentInput()
  if (!input.name || formBusy.value) {
    return
  }
  if (!editing.value) {
    if (await runFormAction(sendBotCreate(input))) {
      closeForm()
    }

    return
  }
  if (formId.value === null || live.value.gone) {
    return
  }
  if (!live.value.dirty) {
    closeForm()

    return
  }
  if (await runFormAction(sendBotUpdate(formId.value, input))) {
    closeForm()
  }
}

// The live row the delete dialog is about: its name is read live, and the row
// the dialog opened with stays on screen once it is gone.
const deleteLive = computed(() =>
  deleteRow.value ? focusedRow.value : undefined,
)
const deleteShown = computed(() => deleteLive.value ?? deleteRow.value)
// Gone elsewhere. Our own delete in flight is not that: its echo makes the row
// a placeholder before the `::success` reply lands
// (TableViewportController.applyOwnDelta), and that placeholder is our doing.
const deleteGone = computed(
  () =>
    deleteRow.value !== null &&
    !deleteBusy.value &&
    deleteLive.value === undefined,
)
const deleteLabel = computed(() => (deleteGone.value ? 'Deleted' : 'Delete'))

function openDelete(row: BotRow): void {
  // Flush pending and take the row into focus; a row already removed by someone
  // else does not open a delete.
  const fresh = botsTable.focusRow(String(row.id))
  if (!fresh) {
    return
  }
  clearDeleteError()
  deleteRow.value = fresh
  deleteOpen.value = true
}

function closeDelete(): void {
  deleteOpen.value = false
  botsTable.releaseFocus()
}

async function submitDelete(): Promise<void> {
  const row = deleteRow.value
  if (!row || deleteBusy.value || deleteGone.value) {
    return
  }
  if (await runDeleteAction(sendBotDelete(row.id))) {
    closeDelete()
  }
}
</script>

<template>
  <HilosAdminPage :page="PAGE_ADMIN_BOTS">
    <section data-id="admin-bots-view">
      <div class="d-flex justify-content-end mb-4">
        <button
          type="button"
          class="btn btn-primary flex-shrink-0"
          data-id="admin-bots-add"
          @click="openCreate"
        >
          <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add bot
        </button>
      </div>

      <HilosViewportTable
        label="Bots"
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
      </HilosViewportTable>

      <HilosModal
        v-model="formOpen"
        :confirm-on-close="formDirty"
        @cancel="closeForm"
      >
        <template #header>
          <ConflictHeader
            :title="formMode === 'create' ? 'Add bot' : `Edit · ${fName}`"
            :conflict="formConflict"
          />
        </template>
        <HilosActionError
          :action="formAction"
          :details-title="formRefusalTitle"
        />
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
              data-autofocus
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
            <label class="form-check-label" for="admin-bots-active"
              >Active</label
            >
          </div>
          <HilosEditNotice
            v-if="editing"
            :kind="formNotice"
            :text="formNoticeText"
            data-id="admin-bots-edit-notice"
          />
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
          <ConflictActions
            :conflict="formConflict"
            :disable-save="saveDisabled"
            :mergeable="false"
            :save-label="saveLabel"
            @save="submitForm"
            @accept-mine="acceptMine"
            @accept-theirs="acceptTheirs"
          >
            <template #save-button="{ disabled, onSave }">
              <LoadingButton
                class="btn-primary"
                :loading="formLoading"
                :disabled="disabled"
                data-id="admin-bots-save"
                @click="onSave"
              >
                {{ saveLabel }}
              </LoadingButton>
            </template>
          </ConflictActions>
        </template>
      </HilosModal>

      <HilosModal
        v-model="deleteOpen"
        :title="deleteShown ? `Delete · ${deleteShown.name}` : 'Delete bot'"
        :close-on-backdrop="!deleteBusy"
        :close-on-esc="!deleteBusy"
        initial-focus="dialog"
        @cancel="closeDelete"
      >
        <HilosActionError
          :action="deleteAction"
          details-title="Couldn't delete the bot"
        />
        <p class="mb-0 text-body-secondary">
          This permanently removes the bot and stops its agent.
        </p>
        <p v-if="deleteShown" class="mb-0 mt-2 fw-medium">
          {{ deleteShown.name }}
        </p>
        <HilosEditNotice
          :kind="deleteGone ? 'deleted' : null"
          text="Deleted elsewhere."
          data-id="admin-bots-delete-notice"
        />
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
            :disabled="deleteGone"
            data-id="admin-bots-delete-confirm"
            @click="submitDelete"
          >
            {{ deleteLabel }}
          </LoadingButton>
        </template>
      </HilosModal>
    </section>
  </HilosAdminPage>
</template>
