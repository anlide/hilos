<!-- HilosSettingsPage — the framework Hilos settings page (HilosPages.SETTINGS):
the cataloged settings table inside the admin shell. Every row is a catalog key
merged with its persisted override, so the key set is fixed — there is no free
"add a setting" (data-model.md, "Cataloged tables"). A row's own actions are the
only mutations: set a custom value on an on-default key (add-by-key), edit or
reset an override, or delete an orphan. The table, the row view-model, and the
add/update/delete round-trips are the core headless's (createHilosSettingsTable /
createHilosSettingsActions); this view owns only the markup, so a project mounts
it by passing its HilosSettingsContext and declares the catalog on its backend.
Authoritative-backend: a submit dispatches a tracked action and the dialog closes
on its `::success` reply (useTrackedAction, step 7.4); a failure surfaces in the
dialog. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosSettingsActions,
  createHilosSettingsTable,
  HilosPages,
  isOrphanSetting,
  isPersistedSetting,
  type HilosSettingRow,
  type HilosSettingsContext,
  type HilosTableColumn,
} from '@hilos/core'
import { computed, onMounted, onUnmounted, ref } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosModal from '../../HilosModal.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import LoadingButton from '../../LoadingButton.vue'
import { useTrackedAction } from '../../useTrackedAction.js'
import HilosSettingValueCell from './HilosSettingValueCell.vue'

const props = defineProps<{
  /** The project context: scope stores and the action lifecycle. */
  context: HilosSettingsContext
}>()

const settings = createHilosSettingsTable(props.context)
const settingsTable = settings.controller
const { sendSettingAdd, sendSettingUpdate, sendSettingDelete } =
  createHilosSettingsActions(props.context, settings.controller)

// Bind the server-windowed table to the connection on mount, request the first
// window, and unbind on unmount.
onMounted(() => settings.start())
onUnmounted(() => settings.dispose())

const columns: HilosTableColumn[] = [
  { key: 'key', label: 'Key', sortable: true },
  { key: 'value', label: 'Value', sortable: true },
  { key: 'actions', label: '', headerClass: 'text-end' },
]

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
const editValue = ref('')
const editUseCustom = ref(false)
const {
  loading: editLoading,
  busy: editBusy,
  error: editError,
  run: runEditAction,
  clearError: clearEditError,
} = useTrackedAction()
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
const editDirty = computed(
  () => !!editRow.value && editOverride.value !== editRow.value.overrideValue,
)

// Delete dialog: orphan keys only (not in the catalog).
const deleteOpen = ref(false)
const deleteRow = ref<HilosSettingRow | null>(null)
const {
  loading: deleteLoading,
  busy: deleteBusy,
  error: deleteError,
  run: runDeleteAction,
  clearError: clearDeleteError,
} = useTrackedAction()

function openEdit(row: HilosSettingRow): void {
  // Flush pending so the dialog edits the latest committed row; a row removed by
  // someone else (now a placeholder) declines to open.
  const fresh = settings.controller.applyAndResolve(row.key)
  if (!fresh) {
    return
  }
  clearEditError()
  editRow.value = fresh
  editUseCustom.value = isPersistedSetting(fresh)
  editValue.value = fresh.overrideValue ?? fresh.value ?? ''
  editOpen.value = true
}

function closeEdit(): void {
  editOpen.value = false
}

// Authoritative-backend: dispatch the tracked action, close on its `::success`
// reply; a failure stays open with the reason shown.
async function submitEdit(): Promise<void> {
  const row = editRow.value
  if (!row || editBusy.value) {
    return
  }
  const next = editOverride.value
  if (next === row.overrideValue) {
    closeEdit()

    return
  }
  // A persisted row updates in place; an on-default catalog key adds a custom value.
  const handle = isPersistedSetting(row)
    ? sendSettingUpdate(row.key, next)
    : sendSettingAdd(row.key, next ?? '')
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
  if (!row || deleteBusy.value) {
    return
  }
  if (await runDeleteAction(sendSettingDelete(row.key))) {
    closeDelete()
  }
}
</script>

<template>
  <HilosAdminPage :page="HilosPages.SETTINGS">
    <HilosViewportTable
      label="Settings"
      :controller="settingsTable"
      :columns="columns"
      searchable
      search-placeholder="Search settings…"
      empty-text="No settings yet."
    >
      <template #row="{ row }">
        <td>
          <code>{{ row.key }}</code>
        </td>
        <td style="max-width: 18rem">
          <HilosSettingValueCell
            :value="row.value"
            :type="row.type"
            :value-source="row.valueSource"
            :default-reference-key="row.defaultReferenceKey"
          />
        </td>
        <td class="text-end">
          <div class="d-flex gap-1 justify-content-end">
            <button
              type="button"
              class="btn btn-sm btn-outline-primary"
              :title="isPersistedSetting(row) ? 'Edit' : 'Set custom value'"
              :aria-label="
                isPersistedSetting(row) ? 'Edit' : 'Set custom value'
              "
              :data-id="`hilos-settings-edit-${row.key}`"
              @click="openEdit(row)"
            >
              <i
                :class="
                  isPersistedSetting(row) ? 'bi bi-pencil' : 'bi bi-plus-lg'
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
        </td>
      </template>
    </HilosViewportTable>

    <HilosModal
      v-model="editOpen"
      :title="editRow ? `Edit · ${editRow.key}` : 'Edit setting'"
      :confirm-on-close="editDirty"
      @cancel="closeEdit"
    >
      <div
        v-if="editError"
        class="alert alert-danger"
        role="alert"
        data-id="hilos-settings-error"
      >
        {{ editError }}
      </div>
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
            />
          </template>
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
        <LoadingButton
          class="btn-primary"
          :loading="editLoading"
          :disabled="!editDirty || editBusy"
          data-id="hilos-settings-edit-save"
          @click="submitEdit"
        >
          Save
        </LoadingButton>
      </template>
    </HilosModal>

    <HilosModal
      v-model="deleteOpen"
      :title="deleteRow ? `Delete · ${deleteRow.key}` : 'Delete setting'"
      :close-on-backdrop="!deleteBusy"
      :close-on-esc="!deleteBusy"
      @cancel="closeDelete"
    >
      <div
        v-if="deleteError"
        class="alert alert-danger"
        role="alert"
        data-id="hilos-settings-delete-error"
      >
        {{ deleteError }}
      </div>
      <p class="mb-0 text-body-secondary">
        This removes the orphan row from the database. Orphan keys are not in
        the catalog.
      </p>
      <p v-if="deleteRow" class="mb-0 mt-2">
        <code>{{ deleteRow.key }}</code>
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
          data-id="hilos-settings-delete-confirm"
          @click="submitDelete"
        >
          Delete
        </LoadingButton>
      </template>
    </HilosModal>
  </HilosAdminPage>
</template>
