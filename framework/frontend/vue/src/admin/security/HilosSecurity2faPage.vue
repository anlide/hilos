<!-- HilosSecurity2faPage — the framework two-step verification admin page
(HilosPages.SECURITY_2FA, HIL-494): the six second-factor settings, one row
each — who must use it, the days a device is trusted, the size of a set of
backup codes, and the removal wait with its bounds. Each row shows its value in
words and a pencil; the pencil opens a modal (the modal-only editing rule), and
a value a setting's rule refuses stays in the modal with the refusal above it.
The table, the row view-model and the edit round-trip are the core headless's
(createHilosSecurityTwoFactorTable / createHilosSecurityTwoFactorActions), and
so are the words; this view owns only the markup, so a project mounts it by
passing its HilosTwoFactorContext. The modal holds its row in focus and merges
against it through the shared row-edit helper (rowEdit.ts,
conflict-resolution.md), saying what happened elsewhere on one line of room held
in advance (HilosEditNotice). The screen is built from text: the mockup's node
is a debt (D-115). Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosSecurityTwoFactorActions,
  createHilosSecurityTwoFactorTable,
  describeHilosSecondFactorSetting,
  HIDDEN_VALUE,
  hiddenAsWord,
  HILOS_SECOND_FACTOR_REQUIRED_COPY,
  HILOS_SECOND_FACTOR_REQUIRED_VALUES,
  HILOS_SECOND_FACTOR_SETTING_COPY,
  HilosPages,
  HilosSecondFactorSettingKey,
  isHiddenValue,
  keepMineRowEdit,
  openRowEdit,
  resolveRowEdit,
  takeTheirsRowEdit,
  type Hideable,
  type HilosTwoFactorContext,
  type HilosTwoFactorSettingRow,
  type RowEditBaseline,
  type RowEditNoticeKind,
  type RowEditStep,
} from '@hilos/core'
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'

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
const { sendSettingSet } = createHilosSecurityTwoFactorActions(props.context)

onMounted(() => settings.start())
onUnmounted(() => settings.dispose())

/**
 * The name of a setting on the screen.
 *
 * @param row The row of the setting.
 */
function labelOf(row: HilosTwoFactorSettingRow): string {
  return HILOS_SECOND_FACTOR_SETTING_COPY[row.rowKey]?.label ?? row.rowKey
}

/** The one field the edit modal edits. */
interface SettingEditFields {
  value: Hideable<string>
}

/**
 * The one line the modal says about the other side, for what the helper found;
 * a value in words, the way its cell says it.
 */
function noticeText(
  kind: RowEditNoticeKind | null,
  liveRow: HilosTwoFactorSettingRow | undefined,
): string {
  switch (kind) {
    case 'deleted':
      return 'Deleted elsewhere — your text stays to copy.'
    case 'conflict':
      if (!liveRow) {
        return ''
      }
      return `Changed elsewhere to "${isHiddenValue(liveRow.value) ? hiddenAsWord(liveRow.value) : describeHilosSecondFactorSetting(liveRow.rowKey, liveRow.value)}".`
    case 'updated':
      return 'Updated just now'
    default:
      return ''
  }
}

// The edit modal: one setting at a time, its value as typed until Save.
const editOpen = ref(false)
const editRow = ref<HilosTwoFactorSettingRow | null>(null)
const editValue = ref('')
const editBaseline = ref<RowEditBaseline<SettingEditFields>>(
  openRowEdit<SettingEditFields>({ value: '' }),
)
// The number input's model: v-model on a number input hands back a number, and
// the value is text everywhere else (the row, the helper, the wire).
const editValueText = computed({
  get: () => editValue.value,
  set: (typed: string | number) => {
    editValue.value = String(typed)
  },
})
const editHidden = computed(() =>
  isHiddenValue(editBaseline.value.values.value),
)
const editAction = useTrackedAction()
const {
  loading: editLoading,
  busy: editBusy,
  run: runEdit,
  clearError: clearEditError,
} = editAction

// The live row the open modal is about: the row the table holds in focus, which
// the server follows wherever it goes; undefined once the row is gone.
const liveRow = useSignal(settings.controller.focusedRow)
const live = computed(() =>
  resolveRowEdit(
    liveRow.value ? { value: liveRow.value.value } : undefined,
    editBaseline.value,
    { value: editHidden.value ? HIDDEN_VALUE : editValue.value.trim() },
  ),
)
const editNotice = computed(() => live.value.notice?.kind ?? null)
const editNoticeText = computed(() =>
  noticeText(editNotice.value, liveRow.value),
)
const editSaveLabel = computed(() => (live.value.gone ? 'Deleted' : 'Save'))
const editTitle = computed(() =>
  editRow.value ? labelOf(editRow.value) : 'Edit setting',
)

function openEdit(row: HilosTwoFactorSettingRow): void {
  // Flush pending and take the row into focus, so the modal edits the latest
  // committed row and follows it from here; a row that is gone declines to open.
  const fresh = settings.controller.focusRow(row.rowKey)
  if (!fresh) {
    return
  }
  clearEditError()
  editRow.value = fresh
  if (!isHiddenValue(fresh.value)) {
    editValue.value = fresh.value
  }
  editBaseline.value = openRowEdit<SettingEditFields>({ value: fresh.value })
  editOpen.value = true
}

function closeEdit(): void {
  editOpen.value = false
  settings.controller.releaseFocus()
}

// Put a step of the helper into the modal: the snapshot moves, and a value the
// step takes lands in the input.
function applyStep(step: RowEditStep<SettingEditFields>): void {
  editBaseline.value = step.baseline
  const taken = step.take.value
  if (taken !== undefined && !isHiddenValue(taken)) {
    editValue.value = taken
  }
}

// The helper hands a step whenever the other side moved the value while the
// person left it alone, or both arrived at the same one; the modal applies it
// at once.
watch(
  () => live.value.settle,
  (settle) => {
    if (editOpen.value && settle) {
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

async function submitEdit(): Promise<void> {
  const row = editRow.value
  if (
    row === null ||
    editBusy.value ||
    live.value.gone ||
    live.value.conflict
  ) {
    return
  }
  if (!live.value.dirty || editHidden.value) {
    closeEdit()

    return
  }
  if (await runEdit(sendSettingSet(row.rowKey, editValue.value.trim()))) {
    closeEdit()
  }
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
          :disable-save="!live.dirty || editBusy || live.gone"
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
