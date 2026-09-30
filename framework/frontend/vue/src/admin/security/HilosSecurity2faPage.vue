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
  createHilosSecurityStepUpActions,
  createHilosSecurityStepUpTable,
  describeHilosSecondFactorSetting,
  HILOS_SECOND_FACTOR_REQUIRED_COPY,
  HILOS_SECOND_FACTOR_REQUIRED_VALUES,
  HILOS_SECOND_FACTOR_SETTING_COPY,
  HILOS_STEP_UP_ADMIN_COPY,
  HilosPages,
  HilosSecondFactorSettingKey,
  keepMineRowEdit,
  openRowEdit,
  resolveRowEdit,
  takeTheirsRowEdit,
  type HilosTwoFactorContext,
  type HilosTwoFactorSettingRow,
  type HilosStepUpOperationRow,
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
import HilosModal from '../../HilosModal.vue'
import HilosSwitch from '../../HilosSwitch.vue'
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
const operations = createHilosSecurityStepUpTable(props.context)
const { sendOperationSet } = createHilosSecurityStepUpActions(props.context)

onMounted(() => {
  settings.start()
  operations.start()
})
onUnmounted(() => {
  settings.dispose()
  operations.dispose()
})

const { busy: operationBusy, run: runOperation } = useTrackedAction()
const pendingOperationKey = ref<string | null>(null)

async function toggleOperation(
  row: HilosStepUpOperationRow,
  enabled: boolean,
): Promise<void> {
  pendingOperationKey.value = row.operationKey
  try {
    await runOperation(sendOperationSet(row.operationKey, enabled))
  } finally {
    pendingOperationKey.value = null
  }
}

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
  value: string
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
      return liveRow
        ? `Changed elsewhere to "${describeHilosSecondFactorSetting(liveRow.rowKey, liveRow.value)}".`
        : ''
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
    { value: editValue.value.trim() },
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
  editValue.value = fresh.value
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
  if (step.take.value !== undefined) {
    editValue.value = step.take.value
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
  if (!live.value.dirty) {
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
        <span :data-id="`hilos-2fa-value-${row.rowKey}`">{{
          describeHilosSecondFactorSetting(row.rowKey, row.value)
        }}</span>
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

    <HilosViewportTable
      class="mt-4"
      :controller="operations.controller"
      data-id="hilos-step-up-table"
    >
      <template #cell-operationKey="{ row }">
        <span
          class="fw-semibold"
          :data-id="`hilos-step-up-row-${row.operationKey}`"
          >{{ row.label }}</span
        >
      </template>
      <template #cell-owner="{ row }">
        <span class="badge text-bg-light border">
          {{
            row.owner === 'framework'
              ? HILOS_STEP_UP_ADMIN_COPY.framework
              : HILOS_STEP_UP_ADMIN_COPY.project
          }}
        </span>
      </template>
      <template #cell-enabled="{ row }">
        <HilosSwitch
          class="mb-0"
          :checked="row.enabled"
          :busy="pendingOperationKey === row.operationKey"
          :disabled="operationBusy"
          :aria-label="`Require confirmation for ${row.label}`"
          :data-id="`hilos-step-up-switch-${row.operationKey}`"
          @toggle="toggleOperation(row, $event)"
        />
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
        <p class="form-text mb-0">
          {{ HILOS_SECOND_FACTOR_SETTING_COPY[editRow.rowKey]?.hint }}
          Default:
          {{
            describeHilosSecondFactorSetting(
              editRow.rowKey,
              editRow.defaultValue,
            )
          }}.
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
