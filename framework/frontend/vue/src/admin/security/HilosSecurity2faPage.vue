<!-- HilosSecurity2faPage — the framework two-step verification admin page
(HilosPages.SECURITY_2FA, HIL-494): the six second-factor settings, one row
each — who must use it, the days a device is trusted, the size of a set of
backup codes, and the removal wait with its bounds. Each row shows its value in
words and a pencil; the pencil opens a modal (the modal-only editing rule), and
a value a setting's rule refuses stays in the modal with the refusal above it.
The table, the row view-model and the edit round-trip are the core headless's
(createHilosSecurityTwoFactorTable / createHilosSecurityTwoFactorActions), and
so are the words; this view owns only the markup, so a project mounts it by
passing its HilosTwoFactorContext. The screen is built from text: the mockup's
node is a debt (D-115). Bootstrap classes only (styling-rules.md). -->
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
  type HilosTwoFactorContext,
  type HilosTwoFactorSettingRow,
  type HilosStepUpOperationRow,
} from '@hilos/core'
import { onMounted, onUnmounted, ref } from 'vue'

import HilosActionError from '../../HilosActionError.vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosModal from '../../HilosModal.vue'
import HilosSwitch from '../../HilosSwitch.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import LoadingButton from '../../LoadingButton.vue'
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

// The edit modal: one setting at a time, its value as typed until Save.
const editOpen = ref(false)
const editRow = ref<HilosTwoFactorSettingRow | null>(null)
const editValue = ref('')
const editAction = useTrackedAction()
const {
  loading: editLoading,
  busy: editBusy,
  run: runEdit,
  clearError: clearEditError,
} = editAction

function openEdit(row: HilosTwoFactorSettingRow): void {
  clearEditError()
  editRow.value = row
  editValue.value = row.value
  editOpen.value = true
}

function closeEdit(): void {
  editOpen.value = false
}

async function submitEdit(): Promise<void> {
  const row = editRow.value
  if (row === null || editBusy.value) {
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
      :title="editRow ? labelOf(editRow) : 'Edit setting'"
      @cancel="closeEdit"
    >
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
          v-model="editValue"
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
      </form>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="editBusy"
          @click="requestClose"
        >
          Cancel
        </button>
        <LoadingButton
          class="btn-primary"
          :loading="editLoading"
          :disabled="editBusy"
          data-id="hilos-2fa-save"
          @click="submitEdit"
        >
          Save
        </LoadingButton>
      </template>
    </HilosModal>
  </HilosAdminPage>
</template>
