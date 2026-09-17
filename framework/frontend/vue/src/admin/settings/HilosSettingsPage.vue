<!-- HilosSettingsPage — the framework Hilos settings page (HilosPages.SETTINGS):
the cataloged settings table inside the admin shell. Every row is a catalog key
merged with its persisted override, so the key set is fixed — there is no free
"add a setting" (data-model.md, "Cataloged tables"). A row's own actions are the
only mutations: set a custom value on an on-default key (add-by-key), edit or
reset an override, or delete an orphan. The table, the row view-model, and the
add/update/delete round-trips are the core headless's (createHilosSettingsTable /
createHilosSettingsActions), and so is what the table declares about its frame —
columns, search, empty state; this view owns only the markup, so a project
mounts it by passing its HilosSettingsContext and declares the catalog on its
backend.
Authoritative-backend: a submit dispatches a tracked action and the dialog closes
on its `::success` reply (useTrackedAction, step 7.4); a failure surfaces as a
toast and leaves the dialog open with the entered value (toasts.md). Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosSettingsActions,
  createHilosSettingsTable,
  hasCustomValue,
  HilosPages,
  isOrphanSetting,
  resolveSettingEdit,
  type HilosSettingRow,
  type HilosSettingsContext,
} from '@hilos/core'
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'

import ConflictActions from '../../ConflictActions.vue'
import ConflictHeader from '../../ConflictHeader.vue'
import HilosActionError from '../../HilosActionError.vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
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
const {
  sendSettingAdd,
  sendSettingUpdate,
  sendSettingDelete,
  sendSettingReset,
} = createHilosSettingsActions(props.context)

// Bind the server-windowed table to the connection on mount, request the first
// window, and unbind on unmount.
onMounted(() => settings.start())
onUnmounted(() => settings.dispose())

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

// Edit dialog: one row's custom value (or a reset back to the catalog default).
const editOpen = ref(false)
const editRow = ref<HilosSettingRow | null>(null)
const editBaseline = ref<string | null>(null)
const editValue = ref('')
const editUseCustom = ref(false)
const editAction = useTrackedAction()
const {
  loading: editLoading,
  busy: editBusy,
  run: runEditAction,
  clearError: clearEditError,
} = editAction
const editInputType = computed(() => inputType(editRow.value?.type))
const editStep = computed(() => inputStep(editRow.value?.type))
const editValueBool = computed({
  get: () => editValue.value === '1',
  set: (on: boolean) => {
    editValue.value = on ? '1' : '0'
  },
})
// The custom value the dialog would persist, normalized to a string: a number
// input yields a number, while the row override and the wire are strings, so an
// un-normalized value would never match the echoed row. Null leaves the default.
const editOverride = computed<string | null>(() =>
  editUseCustom.value ? String(editValue.value) : null,
)
const viewportRows = useSignal(settingsTable.rows)
const live = computed(() =>
  resolveSettingEdit(
    viewportRows.value,
    editRow.value?.key ?? '',
    editBaseline.value,
    editOverride.value,
  ),
)
const editDirty = computed(() => live.value.dirty)
const editTitle = computed(() =>
  editRow.value ? `Edit · ${editRow.value.key}` : 'Edit setting',
)
const editConflictNote = computed(() =>
  live.value.incoming === null
    ? 'The custom value was removed elsewhere and the key is back on its catalog default. Choose how to resolve.'
    : `The value changed elsewhere to "${live.value.incoming}". Choose how to resolve.`,
)
const editSaveLabel = computed(() => (live.value.gone ? 'Deleted' : 'Save'))

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
const deleteLive = computed(() =>
  resolveSettingEdit(
    viewportRows.value,
    deleteRow.value?.key ?? '',
    null,
    null,
  ),
)

function openEdit(row: HilosSettingRow): void {
  // Flush pending so the dialog edits the latest committed row; a row removed by
  // someone else (now a placeholder) declines to open.
  const fresh = settings.controller.applyAndResolve(row.key)
  if (!fresh) {
    return
  }
  clearEditError()
  editRow.value = fresh
  // An orphan has no catalog default behind it and no switch in the dialog, so its
  // value is always its own; a cataloged key opens with the switch on only when it
  // carries a value of its own.
  editUseCustom.value = isOrphanSetting(fresh) || hasCustomValue(fresh)
  editValue.value = fresh.overrideValue ?? fresh.value ?? ''
  editBaseline.value = fresh.overrideValue
  editOpen.value = true
}

function closeEdit(): void {
  editOpen.value = false
}

function rechargeFromIncoming(): void {
  const row = editRow.value
  if (!row) {
    return
  }
  const incoming = live.value.incoming
  editUseCustom.value = incoming !== null || isOrphanSetting(row)
  editValue.value = incoming ?? row.value ?? ''
  editBaseline.value = incoming
}

watch(
  () => live.value.status,
  (status) => {
    if (!editOpen.value) {
      return
    }
    if (status === 'incoming' || status === 'converged') {
      rechargeFromIncoming()
    }
  },
)

function acceptMine(): void {
  editBaseline.value = live.value.incoming
}

function acceptTheirs(): void {
  rechargeFromIncoming()
}

// Authoritative-backend: dispatch the tracked action, close on its `::success`
// reply; a failure toasts and stays open so the entered value survives.
async function submitEdit(): Promise<void> {
  const row = editRow.value
  if (!row || editBusy.value || live.value.gone) {
    return
  }
  const next = editOverride.value
  if (next === live.value.incoming) {
    closeEdit()

    return
  }
  // The switch turned off means "back to the catalog default", which resets the key
  // by dropping its row. With a value, an orphan updates in place and a cataloged
  // key adds by key (the add is idempotent, so the row need not exist yet).
  let handle
  if (next === null) {
    handle = sendSettingReset(row.key)
  } else {
    handle = isOrphanSetting(row)
      ? sendSettingUpdate(row.key, next)
      : sendSettingAdd(row.key, next)
  }
  if (await runEditAction(handle)) {
    closeEdit()
  }
}

function openDelete(row: HilosSettingRow): void {
  // Flush pending; a row already removed by someone else does not open a delete.
  const fresh = settings.controller.applyAndResolve(row.key)
  if (!fresh) {
    return
  }
  clearDeleteError()
  deleteRow.value = fresh
  deleteOpen.value = true
}

function closeDelete(): void {
  deleteOpen.value = false
}

async function submitDelete(): Promise<void> {
  const row = deleteRow.value
  if (!row || deleteBusy.value || deleteLive.value.gone) {
    return
  }
  if (await runDeleteAction(sendSettingDelete(row.key))) {
    closeDelete()
  }
}
</script>

<template>
  <HilosAdminPage :page="HilosPages.SETTINGS">
    <HilosViewportTable :controller="settingsTable">
      <template #cell-key="{ row }">
        <code>{{ row.key }}</code>
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
        <div class="d-flex gap-1 justify-content-end">
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
        </div>
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
      <HilosActionError :action="editAction" />
      <form v-if="editRow" @submit.prevent="submitEdit">
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
        <div
          v-if="live.conflict"
          class="alert alert-warning mt-2 mb-0"
          data-id="hilos-settings-edit-conflict"
        >
          {{ editConflictNote }}
        </div>
        <div
          v-if="live.gone"
          class="alert alert-warning mt-2 mb-0"
          data-id="hilos-settings-edit-gone"
        >
          This setting was deleted elsewhere. Your text stays here to copy - it
          can no longer be saved.
        </div>
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
        <ConflictActions
          :conflict="live.conflict"
          :disable-save="!editDirty || editBusy || live.gone"
          :mergeable="false"
          :save-label="editSaveLabel"
          @save="submitEdit"
          @accept-mine="acceptMine"
          @accept-theirs="acceptTheirs"
        >
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
      <HilosActionError :action="deleteAction" />
      <p class="mb-0 text-body-secondary">
        This removes the orphan row from the database. Orphan keys are not in
        the catalog.
      </p>
      <p v-if="deleteRow" class="mb-0 mt-2">
        <code>{{ deleteRow.key }}</code>
      </p>
      <p
        v-if="deleteLive.gone"
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
          :disabled="deleteBusy || deleteLive.gone"
          data-id="hilos-settings-delete-confirm"
          @click="submitDelete"
        >
          Delete
        </LoadingButton>
      </template>
    </HilosModal>
  </HilosAdminPage>
</template>
