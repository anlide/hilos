<!-- The chat bots admin page (PAGE_ADMIN_BOTS) at /hilos/app/bots: the library
bots table reached from the dashboard's "Chat administration" section. The
heading, the lead and the breadcrumb come from the page catalog on the backend
through the framework's HilosAdminPage shell. The bots
table is a free CRUD table (not cataloged) — add, edit, or delete a bot; the row
shows live agent presence (online/offline) from the runtime status slot. The table
controller and the row view-model live with the page (adminBotsPage.ts), the
create/update/delete submits in adminBotsActions.ts. Authoritative-backend: a
submit dispatches a tracked action and the dialog closes on its `::success` reply
(useTrackedAction, step 7.4); a failure surfaces in the dialog. The edit dialog
is the core row-edit session over the focused row (createHilosRowEdit,
rowEditSession.ts, conflict-resolution.md) and says what happened elsewhere on
one line of room held in advance (HilosEditNotice); Save stays locked while
nothing changed. The create shares its form and has no snapshot to merge
against; the delete dialog reads the same focused row. Bootstrap classes only (styling-rules.md). -->
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
import {
  createHilosRowEdit,
  createSignal,
  hilosTableRowEditSource,
} from '@hilos/core'
import { computed, onMounted, onUnmounted, ref } from 'vue'

import {
  sendBotCreate,
  sendBotDelete,
  sendBotUpdate,
  type BotInput,
} from './adminBotsActions'
import { PAGE_ADMIN_BOTS } from '../../pages/keys'
import { createBotsTable } from './adminBotsPage'
import { type BotRow } from './types/tables/BotRow'

defineOptions({ name: 'AdminBotsPage' })

const bots = createBotsTable({ openAdd: openCreate })

// The row the open dialog holds in focus, which the server follows wherever it
// goes; undefined once the row is gone. The edit form and the delete dialog both
// read it: one dialog is open at a time, and it is the one holding the focus.
const focusedRow = useSignal(bots.controller.focusedRow)

// Bind the server-windowed table to the connection on mount, request the first
// window, and unbind on unmount.
onMounted(bots.start)
onUnmounted(bots.dispose)

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

/** The form of the create and edit dialog: every field as text, and the switch. */
interface BotForm {
  name: string
  description: string
  style: string
  topics: string
  personality: string
  active: boolean
}

const EMPTY_FORM: BotForm = {
  name: '',
  description: '',
  style: '',
  topics: '',
  personality: '',
  active: true,
}

/** The form as a bot input, trimmed and null-normalized. */
function inputOf(form: BotForm): BotInput {
  return {
    name: form.name.trim(),
    description: form.description.trim() || null,
    style: form.style.trim() || null,
    topics: form.topics.trim() || null,
    personality: form.personality.trim() || null,
    active: form.active,
  }
}

/** The form as the edit dialog opens it on a row. */
function formOf(row: BotRow): BotForm {
  return {
    name: row.name,
    description: row.description ?? '',
    style: row.style ?? '',
    topics: row.topics ?? '',
    personality: row.personality ?? '',
    active: row.active,
  }
}

// Create/edit dialog: one shared form, distinguished by mode. The edit is the
// core row-edit session over the focused row; the create shares its form and
// has no snapshot to merge against (conflict-resolution.md, "Ask in a modal").
// resolveBotRow already normalizes an empty optional to null, the way inputOf()
// does, so an untouched field never reads as changed.
const botForm = createSignal<BotForm>(EMPTY_FORM)
const editor = createHilosRowEdit<BotRow, BotInput, BotForm>(
  hilosTableRowEditSource(bots.controller, (row) => String(row.id)),
  {
    formSignal: botForm,
    fields: editFields,
    form: formOf,
    draft: inputOf,
    // A value taken from the other side lands in its field the way the form
    // opened with it.
    take: (form, taken) => ({
      ...form,
      ...(taken.name !== undefined ? { name: taken.name } : {}),
      ...(taken.description !== undefined
        ? { description: taken.description ?? '' }
        : {}),
      ...(taken.style !== undefined ? { style: taken.style ?? '' } : {}),
      ...(taken.topics !== undefined ? { topics: taken.topics ?? '' } : {}),
      ...(taken.personality !== undefined
        ? { personality: taken.personality ?? '' }
        : {}),
      ...(taken.active !== undefined ? { active: taken.active } : {}),
    }),
    valid: (form) => form.name.trim() !== '',
    // The one line about the other side names the fields it is about in the
    // order of the form.
    notice: {
      conflict: (state) =>
        (state.notice?.fields ?? [])
          .map(
            (field) =>
              `${FIELD_LABELS[field]} changed elsewhere to "${cellText(state.fields[field].incoming)}".`,
          )
          .join(' '),
      updated: (state) =>
        `Updated just now: ${(state.notice?.fields ?? []).map((field) => FIELD_LABELS[field]).join(', ')}`,
    },
  },
)
onMounted(editor.start)
onUnmounted(editor.dispose)
const form = useSignal(botForm)
const editing = useSignal(editor.opened)
const creating = ref(false)
const formOpen = computed({
  get: () => creating.value || editing.value,
  set: (next: boolean) => {
    if (!next) closeForm()
  },
})
const formMode = computed(() => (editing.value ? 'edit' : 'create'))
/** One field of the shared form as a model the inputs write through. */
function field<K extends keyof BotForm>(key: K) {
  return computed({
    get: () => form.value[key],
    set: (next: BotForm[K]) => botForm.set({ ...botForm.get(), [key]: next }),
  })
}
const fName = field('name')
const fDescription = field('description')
const fStyle = field('style')
const fTopics = field('topics')
const fPersonality = field('personality')
const fActive = field('active')
const live = useSignal(editor.state)
const canSave = useSignal(editor.canSave)
const saveLabel = useSignal(editor.saveLabel)
const formNoticeText = useSignal(editor.noticeText)
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

// What the form's refusal details are headed with: adding and saving fail
// differently, and the panel names which one did.
const formRefusalTitle = computed(() =>
  editing.value ? "Couldn't save" : "Couldn't add the bot",
)
// Everything the session says holds for an edit only: an add compares against
// nothing and keeps its own rules below.
const formConflict = computed(() => editing.value && live.value.conflict)
const formNotice = computed(() =>
  editing.value ? (live.value.notice?.kind ?? null) : null,
)

// A create is dirty once any field is filled; an edit once the draft differs
// from the live row. confirm-on-close only guards a dirty form.
const formDirty = computed(() => {
  if (!editing.value) {
    const input = inputOf(form.value)

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

// Save is locked while there is nothing to save: for an add an empty name or a
// save in flight; for an edit whatever the session says — nothing changed, a
// row that is gone, a conflict, a save in flight (rules-and-violations.md,
// section E).
const saveDisabled = computed(() =>
  editing.value ? !canSave.value : !form.value.name.trim() || formBusy.value,
)

function openCreate(): void {
  clearFormError()
  botForm.set(EMPTY_FORM)
  creating.value = true
}

function openEdit(row: BotRow): void {
  // The session takes the row into focus, so the form edits the latest
  // committed row and follows it from here; a row removed by someone else (now a
  // placeholder) declines to open.
  clearFormError()
  if (editor.open(String(row.id))) {
    creating.value = false
  }
}

function closeForm(): void {
  creating.value = false
  editor.close()
}

function acceptMine(): void {
  editor.keepMine()
}

function acceptTheirs(): void {
  editor.takeTheirs()
}

// Authoritative-backend: the create dispatches the tracked action and closes
// only when its `::success` reply resolves; a failure stays open with the reason
// shown. The edit goes through the session's one door — it refuses, closes an
// unchanged draft without a round trip (rules-and-violations.md, section E), or
// sends the same way and closes on success.
async function submitForm(): Promise<void> {
  if (editing.value) {
    await editor.save((draft, row) =>
      runFormAction(sendBotUpdate(row.id, draft)),
    )

    return
  }
  const input = inputOf(form.value)
  if (!input.name || formBusy.value) {
    return
  }
  if (await runFormAction(sendBotCreate(input))) {
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
  const fresh = bots.controller.focusRow(String(row.id))
  if (!fresh) {
    return
  }
  clearDeleteError()
  deleteRow.value = fresh
  deleteOpen.value = true
}

function closeDelete(): void {
  deleteOpen.value = false
  bots.controller.releaseFocus()
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
      <HilosViewportTable :controller="bots.controller">
        <template #cell-name="{ row }">
          <span class="fw-medium">{{ row.name }}</span>
        </template>
        <template #cell-description="{ row }">
          <span
            class="text-truncate d-block text-body-secondary"
            style="max-width: 18rem"
            :title="row.description ?? ''"
            >{{ row.description ?? '—' }}</span
          >
        </template>
        <template #cell-status="{ row }">
          <span
            v-if="row.status === 'online'"
            class="badge rounded-pill bg-success-subtle text-success-emphasis border border-success-subtle"
            >online</span
          >
          <span
            v-else
            class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"
            >offline</span
          >
        </template>
        <template #cell-active="{ row }">
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
        </template>
        <template #cell-actions="{ row }">
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
          <ConflictActions
            :conflict="formConflict"
            :disable-save="saveDisabled"
            :save-label="saveLabel"
            @save="submitForm"
            @accept-mine="acceptMine"
            @accept-theirs="acceptTheirs"
          >
            <template #cancel-button>
              <button
                type="button"
                class="btn btn-secondary"
                :disabled="formBusy"
                data-id="admin-bots-cancel"
                @click="requestClose"
              >
                Cancel
              </button>
            </template>
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
