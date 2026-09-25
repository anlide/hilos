<!-- The chat moderation admin page (PAGE_ADMIN_MODERATOR) at /hilos/app/moderator:
the moderator prompt-pieces table reached from the dashboard's "Chat
administration" section. The heading, the lead and the breadcrumb come from the
page catalog on the backend through the framework's HilosAdminPage shell. A free CRUD table (not cataloged) — add, edit, or delete
a prompt piece; each piece belongs to a moderation rule section (name / message).
The table controller and the row view-model live with the page
(adminModeratorPage.ts), the create/update/delete submits in
adminModeratorActions.ts. Authoritative-backend: a submit dispatches a tracked
action and the dialog closes on its `::success` reply (useTrackedAction, step
7.4); a failure surfaces in the dialog. The edit and the delete dialogs merge
against the live row through the shared row-edit helper (rowEdit.ts,
conflict-resolution.md) and say what happened elsewhere on one line of room held
in advance (HilosEditNotice); Save stays locked while nothing changed. Bootstrap
classes only (styling-rules.md). -->
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

import { PAGE_ADMIN_MODERATOR } from '../../pages/keys'
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

// The row the open dialog holds in focus, which the server follows wherever it
// goes; undefined once the row is gone. The edit form and the delete dialog both
// read it: one dialog is open at a time, and it is the one holding the focus.
const focusedRow = useSignal(moderatorPiecesTable.focusedRow)

// Bind the server-windowed table to the connection on mount, request the first
// window, and unbind on unmount.
onMounted(startModeratorPiecesTable)
onUnmounted(disposeModeratorPiecesTable)

/** The labels of the edited fields as the form shows them. */
const FIELD_LABELS: Record<keyof ModeratorPieceInput, string> = {
  section: 'Section',
  promptPiece: 'Prompt piece',
}

/** A section as its badge in the table cell reads it. */
const SECTION_LABELS: Record<ModeratorSection, string> = {
  name_rule: 'name rule',
  message_rule: 'message rule',
}

/**
 * A field's live value as the table cell shows it: the section by its badge,
 * a text as is and nothing as "—".
 */
function incomingText(
  live: RowEditState<ModeratorPieceInput>,
  field: keyof ModeratorPieceInput,
): string {
  if (field === 'section') {
    return SECTION_LABELS[live.fields.section.incoming]
  }
  const text = live.fields.promptPiece.incoming

  return text === '' ? '—' : text
}

/**
 * The one line the edit dialog says about the other side, naming the fields
 * it is about in the order of the form.
 */
function noticeText(live: RowEditState<ModeratorPieceInput>): string {
  const notice = live.notice
  switch (notice?.kind) {
    case 'deleted':
      return 'Deleted elsewhere — your text stays to copy.'
    case 'conflict':
      return notice.fields
        .map(
          (field) =>
            `${FIELD_LABELS[field]} changed elsewhere to "${incomingText(live, field)}".`,
        )
        .join(' ')
    case 'updated':
      return `Updated just now: ${notice.fields.map((field) => FIELD_LABELS[field]).join(', ')}`
    default:
      return ''
  }
}

/** The edited fields of a row, in the order of the form — the helper names them in this order. */
function editFields(row: ModeratorPieceRow): ModeratorPieceInput {
  return { section: row.section, promptPiece: row.promptPiece }
}

// Create/edit dialog: one shared form, distinguished by mode.
const formOpen = ref(false)
const formMode = ref<'create' | 'edit'>('create')
const formId = ref<number | null>(null)
const fSection = ref<ModeratorSection>('message_rule')
const fPromptPiece = ref('')
const editBaseline = ref<RowEditBaseline<ModeratorPieceInput>>(
  openRowEdit<ModeratorPieceInput>({
    section: 'message_rule',
    promptPiece: '',
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

const editing = computed(() => formMode.value === 'edit')
// The live row the edit dialog is about, projected onto the edited fields; gone
// once the row is. An add has no row to follow.
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

// A create is dirty once the prompt is filled; an edit once the draft differs
// from the live row. confirm-on-close only guards a dirty form.
const formDirty = computed(() =>
  editing.value ? live.value.dirty : !!currentInput().promptPiece,
)

// Save is locked while there is nothing to save: an empty prompt, a save in
// flight, an edit that changed nothing, a row that is gone
// (rules-and-violations.md, section E).
const saveDisabled = computed(
  () =>
    !fPromptPiece.value.trim() ||
    formBusy.value ||
    (editing.value && (!live.value.dirty || live.value.gone)),
)

function openCreate(): void {
  clearFormError()
  formMode.value = 'create'
  formId.value = null
  fSection.value = 'message_rule'
  fPromptPiece.value = ''
  formOpen.value = true
}

function openEdit(row: ModeratorPieceRow): void {
  // Flush pending and take the row into focus, so the form edits the latest
  // committed row and follows it from here; a row removed by someone else (now a
  // placeholder) declines to open.
  const fresh = moderatorPiecesTable.focusRow(String(row.id))
  if (!fresh) {
    return
  }
  clearFormError()
  formMode.value = 'edit'
  formId.value = fresh.id
  fSection.value = fresh.section
  fPromptPiece.value = fresh.promptPiece
  editBaseline.value = openRowEdit<ModeratorPieceInput>(editFields(fresh))
  formOpen.value = true
}

function closeForm(): void {
  formOpen.value = false
  moderatorPiecesTable.releaseFocus()
}

// Put a step of the helper into the form: the snapshot moves, and every value
// the step takes lands in its field.
function applyStep(step: RowEditStep<ModeratorPieceInput>): void {
  editBaseline.value = step.baseline
  if (step.take.section !== undefined) {
    fSection.value = step.take.section
  }
  if (step.take.promptPiece !== undefined) {
    fPromptPiece.value = step.take.promptPiece
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
  if (!input.promptPiece || formBusy.value) {
    return
  }
  if (!editing.value) {
    if (await runFormAction(sendModeratorPieceCreate(input))) {
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
  if (await runFormAction(sendModeratorPieceUpdate(formId.value, input))) {
    closeForm()
  }
}

// The live row the delete dialog is about: its text is read live, and the row
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

function openDelete(row: ModeratorPieceRow): void {
  // Flush pending and take the row into focus; a row already removed by someone
  // else does not open a delete.
  const fresh = moderatorPiecesTable.focusRow(String(row.id))
  if (!fresh) {
    return
  }
  clearDeleteError()
  deleteRow.value = fresh
  deleteOpen.value = true
}

function closeDelete(): void {
  deleteOpen.value = false
  moderatorPiecesTable.releaseFocus()
}

async function submitDelete(): Promise<void> {
  const row = deleteRow.value
  if (!row || deleteBusy.value || deleteGone.value) {
    return
  }
  if (await runDeleteAction(sendModeratorPieceDelete(row.id))) {
    closeDelete()
  }
}
</script>

<template>
  <HilosAdminPage :page="PAGE_ADMIN_MODERATOR">
    <section data-id="admin-moderator-view">
      <div class="d-flex justify-content-end mb-4">
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
        :confirm-on-close="formDirty"
        @cancel="closeForm"
      >
        <template #header>
          <ConflictHeader
            :title="
              formMode === 'create' ? 'Add prompt piece' : 'Edit prompt piece'
            "
            :conflict="formConflict"
          />
        </template>
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
              data-autofocus
            ></textarea>
          </div>
          <HilosEditNotice
            v-if="editing"
            :kind="formNotice"
            :text="formNoticeText"
            data-id="admin-moderator-edit-notice"
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
                data-id="admin-moderator-save"
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
        title="Delete prompt piece"
        :close-on-backdrop="!deleteBusy"
        :close-on-esc="!deleteBusy"
        initial-focus="dialog"
        @cancel="closeDelete"
      >
        <HilosActionError :action="deleteAction" />
        <p class="mb-0 text-body-secondary">
          This permanently removes the prompt piece from the moderation rules.
        </p>
        <p v-if="deleteShown" class="mb-0 mt-2 text-truncate">
          {{ deleteShown.promptPiece }}
        </p>
        <HilosEditNotice
          :kind="deleteGone ? 'deleted' : null"
          text="Deleted elsewhere."
          data-id="admin-moderator-delete-notice"
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
            data-id="admin-moderator-delete-confirm"
            @click="submitDelete"
          >
            {{ deleteLabel }}
          </LoadingButton>
        </template>
      </HilosModal>
    </section>
  </HilosAdminPage>
</template>
