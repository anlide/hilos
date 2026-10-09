<!-- HilosSettingsPage — the framework Hilos settings page (HilosPages.SETTINGS):
the cataloged settings table inside the admin shell. Every row is a catalog key
merged with its persisted override, so the key set is fixed — there is no free
"add a setting" (data-model.md, "Cataloged tables"). A row's own actions are the
only mutations: set a custom value on an on-default key (add-by-key), edit or
reset an override, or delete an orphan. The ↺ beside the pencil resets through a
confirm dialog built like the orphan delete — never in one click. The table, the row view-model, and the
add/update/delete round-trips are the core headless's (createHilosSettingsTable /
createHilosSettingsActions), and so is what the table declares about its frame —
columns, search, empty state; this view owns only the markup, so a project
mounts it by passing its HilosSettingsContext and declares the catalog on its
backend.
The edit dialog is the core row-edit session over the focused row
(createHilosSettingEdit, rowEditSession.ts, conflict-resolution.md): it merges
against the live row and says what happened elsewhere on one line of room held
in advance (HilosEditNotice); this view binds the switch and the text.
Authoritative-backend: a submit dispatches a tracked action and the dialog closes
on its `::success` reply (useTrackedAction, step 7.4); a failure surfaces as a
toast and leaves the dialog open with the entered value (toasts.md). Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosSettingEdit,
  createHilosSettingsActions,
  createHilosSettingsTable,
  hasCustomValue,
  HilosPages,
  isOrphanSetting,
  type HilosSettingRow,
  type HilosSettingsContext,
} from '@hilos/core'
import { computed, onMounted, onUnmounted, ref } from 'vue'

import ConflictActions from '../../ConflictActions.vue'
import ConflictHeader from '../../ConflictHeader.vue'
import HilosActionError from '../../HilosActionError.vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosEditNotice from '../../HilosEditNotice.vue'
import HilosModal from '../../HilosModal.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import LoadingButton from '../../LoadingButton.vue'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'
import HilosSettingValueCell from './HilosSettingValueCell.vue'

const props = defineProps<{
  /** The project context: scope stores and the action lifecycle. */
  context: HilosSettingsContext
}>()

const settings = createHilosSettingsTable(props.context)
const settingsTable = settings.controller
const actions = createHilosSettingsActions(props.context)
const { sendSettingDelete, sendSettingReset } = actions

// Bind the server-windowed table to the connection on mount, request the first
// window, and unbind on unmount; the edit window listens for the merge's steps
// over the same span.
onMounted(() => {
  settings.start()
  editor.start()
})
onUnmounted(() => {
  editor.dispose()
  settings.dispose()
})

/** Map a setting type to the value input it edits with. */
function inputType(type: string | undefined): 'text' | 'number' | 'checkbox' {
  if (type === 'boolean') {
    return 'checkbox'
  }
  if (type === 'integer' || type === 'float') {
    return 'number'
  }

  return 'text'
}

function inputStep(type: string | undefined): 'any' | undefined {
  return type === 'float' ? 'any' : undefined
}

// Edit dialog: one row's custom value (or a reset back to the catalog default),
// as the core window has it; this view binds the switch and the text.
const editor = createHilosSettingEdit(settingsTable, actions)
const opened = useSignal(editor.opened)
const editOpen = computed({
  get: () => opened.value,
  set: (next: boolean) => {
    if (!next) editor.close()
  },
})
const editRow = useSignal(editor.row)
const editForm = useSignal(editor.form)
const live = useSignal(editor.state)
const editNoticeText = useSignal(editor.noticeText)
const editSaveLabel = useSignal(editor.saveLabel)
const canSave = useSignal(editor.canSave)
const editAction = useTrackedAction()
const {
  loading: editLoading,
  busy: editBusy,
  run: runEditAction,
  clearError: clearEditError,
} = editAction
const editInputType = computed(() => inputType(editRow.value?.type))
const editStep = computed(() => inputStep(editRow.value?.type))
const editHidden = computed(() => editForm.value.hidden)
const editUseCustom = computed({
  get: () => editForm.value.useCustom,
  set: (on: boolean) => editor.patchForm({ useCustom: on }),
})
// A number input hands back a number, while the form and the wire hold text.
const editValue = computed({
  get: () => editForm.value.text,
  set: (typed: string | number) => editor.patchForm({ text: String(typed) }),
})
const editValueBool = computed({
  get: () => editForm.value.text === '1',
  set: (on: boolean) => editor.patchForm({ text: on ? '1' : '0' }),
})
const editDirty = computed(() => live.value.dirty)
const editTitle = computed(() =>
  editRow.value ? `Edit · ${editRow.value.key}` : 'Edit setting',
)
const editNotice = computed(() => live.value.notice?.kind ?? null)
// The live row the open delete or reset dialog is about: the row the table
// holds in focus, which the server follows wherever it goes; undefined once the
// row is gone.
const liveRow = useSignal(settingsTable.focusedRow)

// Delete dialog: orphan keys only (not in the catalog).
const deleteOpen = ref(false)
const deleteRow = ref<HilosSettingRow | null>(null)
const deleteAction = useTrackedAction()
const {
  loading: deleteLoading,
  busy: deleteBusy,
  run: runDeleteAction,
  clearError: clearDeleteError,
} = deleteAction
const deleteGone = computed(() => liveRow.value === undefined)

// Reset dialog: back to the catalog default, only on confirm. It reads the live
// row it holds in focus, so what it shows follows the other tabs.
const resetOpen = ref(false)
const resetRow = ref<HilosSettingRow | null>(null)
const resetAction = useTrackedAction()
const {
  loading: resetLoading,
  busy: resetBusy,
  run: runResetAction,
  clearError: clearResetError,
} = resetAction
const resetShown = computed(() => liveRow.value ?? resetRow.value)
const resetGone = computed(
  () => liveRow.value === undefined || !hasCustomValue(liveRow.value),
)

function openEdit(row: HilosSettingRow): void {
  // The window takes the row into focus, so the dialog edits the latest
  // committed row and follows it from here; a row removed by someone else (now
  // a placeholder) declines to open.
  clearEditError()
  editor.open(row.key)
}

function closeEdit(): void {
  editor.close()
}

function acceptMine(): void {
  editor.keepMine()
}

function acceptTheirs(): void {
  editor.takeTheirs()
}

// Save and Enter go through the window's one door: it refuses, closes an
// unchanged draft, or dispatches the tracked action and closes on its
// `::success` reply; a failure toasts and stays open so the entered value
// survives.
function submitEdit(): void {
  void editor.save(runEditAction)
}

function openDelete(row: HilosSettingRow): void {
  // Flush pending and take the row into focus; a row already removed by someone
  // else does not open a delete.
  const fresh = settings.controller.focusRow(row.key)
  if (!fresh) {
    return
  }
  clearDeleteError()
  deleteRow.value = fresh
  deleteOpen.value = true
}

function closeDelete(): void {
  deleteOpen.value = false
  settings.controller.releaseFocus()
}

async function submitDelete(): Promise<void> {
  const row = deleteRow.value
  if (!row || deleteBusy.value || deleteGone.value) {
    return
  }
  if (await runDeleteAction(sendSettingDelete(row.key))) {
    closeDelete()
  }
}

function openReset(row: HilosSettingRow): void {
  // Flush pending and take the row into focus; a row already removed by someone
  // else does not open a reset.
  const fresh = settings.controller.focusRow(row.key)
  if (!fresh) {
    return
  }
  clearResetError()
  resetRow.value = fresh
  resetOpen.value = true
}

function closeReset(): void {
  resetOpen.value = false
  settings.controller.releaseFocus()
}

async function submitReset(): Promise<void> {
  const row = resetRow.value
  if (!row || resetBusy.value || resetGone.value) {
    return
  }
  if (await runResetAction(sendSettingReset(row.key))) {
    closeReset()
  }
}
</script>

<template>
  <HilosAdminPage :page="HilosPages.SETTINGS">
    <HilosViewportTable :controller="settingsTable">
      <template #cell-key="{ row }">
        <code class="text-break">{{ row.key }}</code>
      </template>
      <template #cell-value="{ row }">
        <div style="max-width: 18rem">
          <HilosSettingValueCell
            :value="row.value"
            :type="row.type"
            :value-source="row.valueSource"
            :default-reference-key="row.defaultReferenceKey"
          />
        </div>
      </template>
      <template #cell-actions="{ row }">
        <button
          type="button"
          class="btn btn-sm btn-outline-primary"
          :title="
            hasCustomValue(row) || isOrphanSetting(row)
              ? 'Edit'
              : 'Set custom value'
          "
          :aria-label="
            hasCustomValue(row) || isOrphanSetting(row)
              ? 'Edit'
              : 'Set custom value'
          "
          :data-id="`hilos-settings-edit-${row.key}`"
          @click="openEdit(row)"
        >
          <i
            :class="
              hasCustomValue(row) || isOrphanSetting(row)
                ? 'bi bi-pencil'
                : 'bi bi-plus-lg'
            "
            aria-hidden="true"
          ></i>
        </button>
        <button
          v-if="!isOrphanSetting(row)"
          type="button"
          class="btn btn-sm btn-outline-secondary"
          title="Reset to default"
          aria-label="Reset to default"
          :disabled="!hasCustomValue(row)"
          :data-id="`hilos-settings-reset-${row.key}`"
          @click="openReset(row)"
        >
          <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
        </button>
        <button
          v-if="isOrphanSetting(row)"
          type="button"
          class="btn btn-sm btn-outline-danger"
          title="Delete orphan setting"
          aria-label="Delete orphan setting"
          :data-id="`hilos-settings-delete-${row.key}`"
          @click="openDelete(row)"
        >
          <i class="bi bi-trash" aria-hidden="true"></i>
        </button>
      </template>
    </HilosViewportTable>

    <HilosModal
      v-model="editOpen"
      :confirm-on-close="editDirty"
      @cancel="closeEdit"
    >
      <template #header>
        <ConflictHeader :title="editTitle" :conflict="live.conflict" />
      </template>
      <HilosActionError :action="editAction" details-title="Couldn't save" />
      <form v-if="editRow" @submit.prevent="submitEdit">
        <div v-if="editHidden" class="mb-3">
          <span class="form-label d-block">{{ editRow.key }}</span>
          <HilosSettingValueCell
            :value="editRow.value"
            :type="editRow.type"
            :value-source="editRow.valueSource"
            :default-reference-key="editRow.defaultReferenceKey"
          />
        </div>
        <template v-else>
          <div v-if="!isOrphanSetting(editRow)" class="mb-3">
            <span class="form-label d-block">Catalog default</span>
            <HilosSettingValueCell
              :value="editRow.defaultValue"
              :type="editRow.type"
              :value-source="editRow.valueSource"
              :default-reference-key="editRow.defaultReferenceKey"
            />
          </div>
          <div
            v-if="!isOrphanSetting(editRow)"
            class="form-check form-switch mb-3"
          >
            <input
              id="hilos-settings-edit-custom"
              v-model="editUseCustom"
              type="checkbox"
              class="form-check-input"
              data-id="hilos-settings-edit-custom"
            />
            <label class="form-check-label" for="hilos-settings-edit-custom">
              Custom value
            </label>
          </div>
          <div v-if="editUseCustom" class="mb-0">
            <div v-if="editInputType === 'checkbox'" class="form-check">
              <input
                id="hilos-settings-edit-value"
                v-model="editValueBool"
                type="checkbox"
                class="form-check-input"
                data-id="hilos-settings-edit-value"
                data-autofocus
              />
              <label class="form-check-label" for="hilos-settings-edit-value">
                Enabled
              </label>
            </div>
            <template v-else>
              <label class="form-label" for="hilos-settings-edit-value">
                {{ editRow.key }}
              </label>
              <input
                id="hilos-settings-edit-value"
                v-model="editValue"
                :type="editInputType"
                :step="editStep"
                class="form-control"
                data-id="hilos-settings-edit-value"
                data-autofocus
              />
            </template>
          </div>
        </template>
        <HilosEditNotice
          :kind="editNotice"
          :text="editNoticeText"
          data-id="hilos-settings-edit-notice"
        />
      </form>
      <template #actions="{ requestClose }">
        <ConflictActions
          :conflict="live.conflict"
          :disable-save="!canSave"
          :save-label="editSaveLabel"
          @save="submitEdit"
          @accept-mine="acceptMine"
          @accept-theirs="acceptTheirs"
        >
          <template #cancel-button>
            <button
              type="button"
              class="btn btn-secondary"
              data-id="hilos-settings-edit-cancel"
              :disabled="editBusy"
              @click="requestClose"
            >
              Cancel
            </button>
          </template>
          <template #save-button="{ disabled, onSave }">
            <LoadingButton
              class="btn-primary"
              :loading="editLoading"
              :disabled="disabled"
              data-id="hilos-settings-edit-save"
              @click="onSave"
            >
              {{ editSaveLabel }}
            </LoadingButton>
          </template>
        </ConflictActions>
      </template>
    </HilosModal>

    <HilosModal
      v-model="deleteOpen"
      :title="deleteRow ? `Delete · ${deleteRow.key}` : 'Delete setting'"
      :close-on-backdrop="!deleteBusy"
      :close-on-esc="!deleteBusy"
      initial-focus="dialog"
      @cancel="closeDelete"
    >
      <HilosActionError
        :action="deleteAction"
        details-title="Couldn't delete the setting"
      />
      <p class="mb-0 text-body-secondary">
        This removes the orphan row from the database. Orphan keys are not in
        the catalog.
      </p>
      <p v-if="deleteRow" class="mb-0 mt-2">
        <code class="text-break">{{ deleteRow.key }}</code>
      </p>
      <p
        v-if="deleteGone"
        class="mb-0 mt-2 text-body-secondary"
        data-id="hilos-settings-delete-gone"
      >
        This setting was already deleted elsewhere.
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
          :disabled="deleteBusy || deleteGone"
          data-id="hilos-settings-delete-confirm"
          @click="submitDelete"
        >
          Delete
        </LoadingButton>
      </template>
    </HilosModal>

    <HilosModal
      v-model="resetOpen"
      :title="resetRow ? `Reset · ${resetRow.key}` : 'Reset setting'"
      :close-on-backdrop="!resetBusy"
      :close-on-esc="!resetBusy"
      initial-focus="dialog"
      @cancel="closeReset"
    >
      <HilosActionError
        :action="resetAction"
        details-title="Couldn't reset the setting"
      />
      <dl v-if="resetShown" class="row mb-0">
        <dt class="col-4">Now</dt>
        <dd class="col-8" data-id="hilos-settings-reset-now">
          <HilosSettingValueCell
            :value="resetShown.value"
            :type="resetShown.type"
            :value-source="resetShown.valueSource"
            :default-reference-key="resetShown.defaultReferenceKey"
          />
        </dd>
        <dt class="col-4">Back to</dt>
        <dd class="col-8" data-id="hilos-settings-reset-default">
          <HilosSettingValueCell
            :value="resetShown.defaultValue"
            :type="resetShown.type"
            :value-source="
              resetShown.defaultReferenceKey !== null ? 'reference' : 'default'
            "
            :default-reference-key="resetShown.defaultReferenceKey"
          />
        </dd>
      </dl>
      <p
        v-if="resetGone"
        class="mb-0 mt-2 text-body-secondary"
        data-id="hilos-settings-reset-gone"
      >
        Already reset elsewhere.
      </p>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="resetBusy"
          @click="requestClose"
        >
          Cancel
        </button>
        <LoadingButton
          class="btn-danger"
          :loading="resetLoading"
          :disabled="resetBusy || resetGone"
          data-id="hilos-settings-reset-confirm"
          @click="submitReset"
        >
          Reset
        </LoadingButton>
      </template>
    </HilosModal>
  </HilosAdminPage>
</template>
