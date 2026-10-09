<!-- HilosSecurity2faPage — the framework two-step verification admin page
(HilosPages.SECURITY_2FA, HIL-494): the six second-factor settings, one row
each — who must use it, the days a device is trusted, the size of a set of
backup codes, and the removal wait with its bounds. Each row shows its value in
words and a pencil; the pencil opens a modal (the modal-only editing rule), and
a value a setting's rule refuses stays in the modal with the refusal above it.
The table, the row view-model and the edit round-trip are the core headless's
(createHilosSecurityTwoFactorTable / createHilosSecurityTwoFactorActions), and
so are the words; this view owns only the markup, so a project mounts it by
passing its HilosTwoFactorContext. The modal is the core row-edit session over
the focused row (createHilosTwoFactorSettingEdit, rowEditSession.ts,
conflict-resolution.md), saying what happened elsewhere on one line of room held
in advance (HilosEditNotice); this view binds the input. The screen is built from text: the mockup's node
is a debt (D-115). Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosSecurityTwoFactorActions,
  createHilosSecurityTwoFactorTable,
  createHilosTwoFactorSettingEdit,
  describeHilosSecondFactorSetting,
  HILOS_SECOND_FACTOR_REQUIRED_COPY,
  HILOS_SECOND_FACTOR_REQUIRED_VALUES,
  HILOS_SECOND_FACTOR_SETTING_COPY,
  HilosPages,
  HilosSecondFactorSettingKey,
  type HilosTwoFactorContext,
  type HilosTwoFactorSettingRow,
} from '@hilos/core'
import { computed, onMounted, onUnmounted } from 'vue'

import ConflictActions from '../../ConflictActions.vue'
import ConflictHeader from '../../ConflictHeader.vue'
import HilosActionError from '../../HilosActionError.vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosEditNotice from '../../HilosEditNotice.vue'
import HilosHiddenMark from '../../HilosHiddenMark.vue'
import HilosHideable from '../../HilosHideable.vue'
import HilosModal from '../../HilosModal.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import LoadingButton from '../../LoadingButton.vue'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

const props = defineProps<{
  /** The project context: connection, scope stores, and the action lifecycle. */
  context: HilosTwoFactorContext
}>()

const settings = createHilosSecurityTwoFactorTable(props.context)
const actions = createHilosSecurityTwoFactorActions(props.context)

onMounted(() => {
  settings.start()
  editor.start()
})
onUnmounted(() => {
  editor.dispose()
  settings.dispose()
})

/**
 * The name of a setting on the screen.
 *
 * @param row The row of the setting.
 */
function labelOf(row: HilosTwoFactorSettingRow): string {
  return HILOS_SECOND_FACTOR_SETTING_COPY[row.rowKey]?.label ?? row.rowKey
}

// The edit modal: one setting at a time, as the core window has it; this view
// binds the list or the number input.
const editor = createHilosTwoFactorSettingEdit(settings.controller, actions)
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
const editHidden = computed(() => editForm.value.hidden)
// The number input's model: v-model on a number input hands back a number, and
// the value is text everywhere else (the form, the session, the wire).
const editValue = computed({
  get: () => editForm.value.text,
  set: (typed: string | number) => editor.patchForm({ text: String(typed) }),
})
const editValueText = editValue
const editAction = useTrackedAction()
const {
  loading: editLoading,
  busy: editBusy,
  run: runEdit,
  clearError: clearEditError,
} = editAction
const editNotice = computed(() => live.value.notice?.kind ?? null)
const editTitle = computed(() =>
  editRow.value ? labelOf(editRow.value) : 'Edit setting',
)

function openEdit(row: HilosTwoFactorSettingRow): void {
  // The window takes the row into focus, so the modal edits the latest
  // committed row and follows it from here; a row that is gone declines to open.
  clearEditError()
  editor.open(row.rowKey)
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
// `::success` reply; a refusal stays in the modal.
function submitEdit(): void {
  void editor.save(runEdit)
}
</script>

<template>
  <HilosAdminPage :page="HilosPages.SECURITY_2FA">
    <HilosViewportTable :controller="settings.controller">
      <template #cell-rowKey="{ row }">
        <div class="fw-semibold">{{ labelOf(row) }}</div>
        <div class="small text-body-secondary">
          {{ HILOS_SECOND_FACTOR_SETTING_COPY[row.rowKey]?.hint }}
        </div>
      </template>
      <template #cell-value="{ row }">
        <span :data-id="`hilos-2fa-value-${row.rowKey}`">
          <HilosHideable :value="row.value">
            <template #default="{ value }">
              {{ describeHilosSecondFactorSetting(row.rowKey, value) }}
            </template>
          </HilosHideable>
        </span>
      </template>
      <template #cell-actions="{ row }">
        <button
          type="button"
          class="btn btn-sm btn-outline-primary"
          title="Edit"
          :aria-label="`Edit ${labelOf(row)}`"
          :data-id="`hilos-2fa-edit-${row.rowKey}`"
          @click="openEdit(row)"
        >
          <i class="bi bi-pencil" aria-hidden="true"></i>
        </button>
      </template>
    </HilosViewportTable>

    <HilosModal
      v-model="editOpen"
      :confirm-on-close="live.dirty"
      :aria-label="editTitle"
      @cancel="closeEdit"
    >
      <template #header>
        <ConflictHeader :title="editTitle" :conflict="live.conflict" />
      </template>
      <HilosActionError :action="editAction" details-title="Couldn't save" />
      <form v-if="editRow" @submit.prevent="submitEdit">
        <template v-if="editHidden">
          <div class="form-label">{{ labelOf(editRow) }}</div>
          <HilosHiddenMark />
        </template>
        <template v-else>
          <label class="form-label" for="hilos-2fa-input">
            {{ labelOf(editRow) }}
          </label>
          <select
            v-if="editRow.rowKey === HilosSecondFactorSettingKey.required"
            id="hilos-2fa-input"
            v-model="editValue"
            class="form-select"
            data-id="hilos-2fa-input"
            data-autofocus
          >
            <option
              v-for="value in HILOS_SECOND_FACTOR_REQUIRED_VALUES"
              :key="value"
              :value="value"
            >
              {{ HILOS_SECOND_FACTOR_REQUIRED_COPY[value] }}
            </option>
          </select>
          <input
            v-else
            id="hilos-2fa-input"
            v-model="editValueText"
            type="number"
            inputmode="numeric"
            class="form-control"
            data-id="hilos-2fa-input"
            data-autofocus
          />
        </template>
        <p class="form-text mb-0">
          {{ HILOS_SECOND_FACTOR_SETTING_COPY[editRow.rowKey]?.hint }}
          Default:
          <HilosHideable :value="editRow.defaultValue">
            <template #default="{ value }">
              {{ describeHilosSecondFactorSetting(editRow.rowKey, value) }}
            </template> </HilosHideable
          >.
        </p>
        <HilosEditNotice
          :kind="editNotice"
          :text="editNoticeText"
          data-id="hilos-2fa-edit-notice"
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
              data-id="hilos-2fa-save"
              @click="onSave"
            >
              {{ editSaveLabel }}
            </LoadingButton>
          </template>
        </ConflictActions>
      </template>
    </HilosModal>
  </HilosAdminPage>
</template>
