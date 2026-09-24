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
  describeHilosSecondFactorSetting,
  HILOS_SECOND_FACTOR_REQUIRED_COPY,
  HILOS_SECOND_FACTOR_REQUIRED_VALUES,
  HILOS_SECOND_FACTOR_SETTING_COPY,
  HilosPages,
  HilosSecondFactorSettingKey,
  type HilosTwoFactorContext,
  type HilosTwoFactorSettingRow,
} from '@hilos/core'
import { onMounted, onUnmounted, ref } from 'vue'

import HilosActionError from '../../HilosActionError.vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosModal from '../../HilosModal.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import LoadingButton from '../../LoadingButton.vue'
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

    <HilosModal
      v-model="editOpen"
      :title="editRow ? labelOf(editRow) : 'Edit setting'"
      @cancel="closeEdit"
    >
      <HilosActionError :action="editAction" />
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
