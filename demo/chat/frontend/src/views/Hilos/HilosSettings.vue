<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div class="col-12 col-lg-10 mx-auto d-flex flex-column h-100 min-h-0">
      <client-only>
      <div class="card d-flex flex-column flex-grow-1 min-h-0 overflow-hidden">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">Settings</h5>
          <TableRefreshToolbarButton
            v-if="tableState"
            :loading="refreshLoading"
            aria-label="Refresh"
            @click="refreshTable"
          />
        </div>
        <div class="card-body overflow-auto">
          <Table
            :items="displayRows"
            item-key="key"
            :colspan="3"
            :placeholder-when-empty="!connectionStore.isConnected"
            :searchable="true"
            search-placeholder="Search settings..."
            :search-fields="['key', 'value']"
            :sortable="true"
            :sortable-fields="['key']"
            :paginated="false"
            :show-actions="true"
            :show-add-button="true"
            :show-edit-button="true"
            :show-delete-button="true"
            :pending-changes="pendingChanges"
            :change-markers="changeMarkers"
            @add="handleAdd"
            @edit="handleEdit"
            @delete="handleDelete"
            @update-snapshot="handleApplyChanges"
          >
            <template #header="{ sort, handleSort, isFieldSortable }">
              <th>
                <button
                  v-if="isFieldSortable('key')"
                  class="btn btn-link p-0 text-decoration-none text-body fw-bold"
                  @click="handleSort('key')"
                >
                  Key
                  <span v-if="sort.field === 'key'">
                    {{ sort.direction === 'asc' ? '↑' : '↓' }}
                  </span>
                </button>
                <span v-else>Key</span>
              </th>
              <th>Value</th>
              <th>Actions</th>
            </template>
            <template #row="row">
              <td><code>{{ row.item.key }}</code></td>
              <td class="align-middle" style="max-width: 220px">
                <template v-if="row.item.type === 'boolean'">
                  <div class="form-check d-inline-flex align-items-center mb-0 user-select-none">
                    <input
                      type="checkbox"
                      class="form-check-input mt-0"
                      :checked="row.item.value === '1'"
                      disabled
                      tabindex="-1"
                      :aria-label="row.item.value === '1' ? 'Enabled' : 'Disabled'"
                    />
                  </div>
                </template>
                <template v-else-if="row.item.type === 'integer'">
                  <span
                    class="fst-italic text-truncate d-inline-block w-100"
                    :title="formatValue(row.item.value, row.item.type)"
                  >
                    {{ formatValue(row.item.value, row.item.type) }}
                  </span>
                </template>
                <template v-else>
                  <span
                    v-if="isEmptyStringSetting(row.item.value, row.item.type)"
                    class="badge rounded-pill d-inline-flex align-items-center justify-content-center px-2 py-1 bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"
                    role="img"
                    aria-label="Empty string"
                    title="Empty string"
                  >
                    <i class="bi bi-file-earmark" aria-hidden="true"></i>
                  </span>
                  <span
                    v-else
                    class="text-truncate d-inline-block w-100"
                    :title="String(row.item.value ?? '')"
                  >
                    {{ formatValue(row.item.value, row.item.type) }}
                  </span>
                </template>
              </td>
              <td>
                <div class="d-flex gap-1">
                  <button
                    v-if="row.showEditButton"
                    type="button"
                    class="btn btn-sm btn-outline-primary"
                    :title="row.item.id == null ? 'Add value' : 'Edit'"
                    :aria-label="row.item.id == null ? 'Add value' : 'Edit'"
                    @click="row.handleEdit"
                  >
                    <i
                      :class="row.item.id == null ? 'bi bi-plus-lg' : 'bi bi-pencil'"
                      aria-hidden="true"
                    ></i>
                  </button>
                  <button
                    v-if="row.showDeleteButton && isOrphan(row.item)"
                    type="button"
                    class="btn btn-sm btn-outline-danger"
                    title="Delete orphan (keys not in catalog)"
                    aria-label="Delete"
                    @click="row.handleDelete"
                  >
                    <i class="bi bi-trash" aria-hidden="true"></i>
                  </button>
                </div>
              </td>
            </template>
            <template #empty>
              <p class="mb-0">No settings found</p>
            </template>
          </Table>
        </div>
      </div>

  <Modal
    v-model="showModal"
    :title="modalTitle"
    modal-name="hilos-settings-modal"
    :modal-type="isCreating ? 'add' : 'edit'"
    :confirm-on-close="isFormDirty"
    @cancel="resetForm"
    @ok="saveSetting"
  >
    <form @submit.prevent="saveSetting">
      <div v-if="isCreating" class="mb-3">
        <label class="form-label" for="setting-key">Key</label>
        <select id="setting-key" v-model="formSetting.key" class="form-select" required data-autofocus>
          <option value="" disabled>Select a key</option>
          <option
            v-for="k in availableCatalogKeys"
            :key="k"
            :value="k"
          >
            {{ k }}
          </option>
          <option v-if="availableCatalogKeys.length === 0" value="" disabled>
            No keys available (all in use)
          </option>
        </select>
      </div>
      <div class="mb-0">
        <template v-if="valueInputType === 'checkbox'">
          <div class="form-check">
            <input
              id="setting-value"
              v-model="formSettingValueBool"
              type="checkbox"
              class="form-check-input"
            />
            <label class="form-check-label text-break" for="setting-value">{{ valueFieldLabel }}</label>
          </div>
        </template>
        <template v-else>
          <label class="form-label" for="setting-value">{{ valueFieldLabel }}</label>
          <input
            id="setting-value"
            v-model="formSetting.value"
            :type="valueInputType"
            class="form-control"
            :required="!isCreating"
          />
        </template>
      </div>
    </form>
    <template #actions="{ requestClose }">
      <button type="button" class="btn btn-secondary" @click="requestClose">Cancel</button>
      <LoadingButton
        type="button"
        variant="btn-primary"
        :loading="saveLoading"
        :disabled="!isFormValid"
        :loading-delay="300"
        @click="saveSetting"
      >
        {{ isCreating ? 'Create' : 'Save' }}
      </LoadingButton>
    </template>
  </Modal>

  <Modal
    v-model="showDeleteModal"
    :title="deleteModalTitle"
    modal-name="hilos-settings-delete-modal"
    modal-type="delete"
    :close-on-backdrop="!deleteLoading"
    :close-on-esc="!deleteLoading"
    @cancel="resetDeleteModal"
  >
    <p class="mb-0 text-body-secondary">
      This removes the orphan row from the database.
    </p>
    <p v-if="deleteTarget?.key" class="mb-0 mt-2">
      <span class="text-break"><code>{{ deleteTarget.key }}</code></span>
    </p>
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-secondary"
        :disabled="deleteLoading"
        @click="requestClose"
      >
        Cancel
      </button>
      <LoadingButton
        type="button"
        variant="btn-danger"
        :loading="deleteLoading"
        :disabled="!deleteTarget?.key"
        :loading-delay="300"
        @click="confirmDeleteSetting"
      >
        Delete
      </LoadingButton>
    </template>
  </Modal>
  <template #placeholder>
    <div class="card flex-grow-1 overflow-auto">
      <div class="card-header"><h5 class="mb-0">Settings</h5></div>
      <div class="card-body">
        <p class="mb-0 text-body-secondary">Access denied for guests.</p>
      </div>
    </div>
  </template>
  </client-only>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useWebSocket } from '@hilos/sdk/plugins/websocket'
import { Table, Modal, LoadingButton } from '@hilos/sdk/components'
import { getTableDisplayRows, getTablePendingChanges, getTableChangeMarkers } from '@hilos/sdk/composables'
import { useTableDeleteMutationModal } from '@/composables/useTableDeleteMutationModal'
import { useTableRefresh } from '@/composables/useTableRefresh'
import TableRefreshToolbarButton from '@/components/TableRefreshToolbarButton.vue'
import { useConnectionStore, useTableStore } from '@hilos/sdk/stores'
import { sendAction } from '@/services/websocketActions'
import { SETTING_ADD, SETTING_UPDATE, SETTING_DELETE } from '@/constants'

interface SettingEntity {
  id: number | null
  key: string
  type: string
  value: string | null
}

const connectionStore = useConnectionStore()
const tableStore = useTableStore()
const websocket = useWebSocket()

const tableKey = 'settings'
const { refreshLoading, refreshTable } = useTableRefresh(tableKey)
const tableState = computed(() => tableStore.tableData[tableKey])
const displayRows = computed(() => getTableDisplayRows<SettingEntity>(tableStore.tableData[tableKey]))
const pendingChanges = computed(() => getTablePendingChanges(tableStore.tableData[tableKey]))
const changeMarkers = computed(() => getTableChangeMarkers(tableStore.tableData[tableKey]))

/** Catalog keys from subscription payload (backend). */
const catalogKeys = computed(() => (tableState.value as { catalogKeys?: string[] })?.catalogKeys ?? [])

/** Catalog keys not yet used in table (for Add modal dropdown). */
const availableCatalogKeys = computed(() => {
  const usedKeys = new Set(displayRows.value.map((r) => r.key))
  return catalogKeys.value.filter((k) => !usedKeys.has(k))
})

/** Orphan = key exists in DB but not in catalog. Only orphans can be deleted. */
const isOrphan = (item: { key: string }) => !catalogKeys.value.includes(item.key)

const handleApplyChanges = () => {
  const { hasDeletes } = tableStore.applyPendingMutations(tableKey)
  if (hasDeletes) {
    refreshTable()
  }
}

const formatValue = (value: string | null | undefined, type: string): string => {
  if (value === null || value === undefined) return '—'
  if (type === 'boolean') return value === '1' ? 'true' : 'false'
  return String(value)
}

/** Empty string (type string): show placeholder in table, not raw "". */
const isEmptyStringSetting = (value: string | null | undefined, type: string): boolean => {
  if (type !== 'string') return false
  return value === '' || value === null || value === undefined
}

const showModal = ref(false)
const isCreating = ref(false)
const selectedSetting = ref<SettingEntity | null>(null)
const formSetting = ref<{ key: string; value: string | null }>({ key: '', value: null })
const baselineSetting = ref<SettingEntity | null>(null)

/** Boolean binding for checkbox (value stored as '0'/'1' string). */
const formSettingValueBool = computed({
  get: () => formSetting.value.value === '1',
  set: (v: boolean) => { formSetting.value.value = v ? '1' : '0' },
})

/** Input type for value: text, number, or checkbox. */
const valueInputType = computed(() => {
  const t = selectedSetting.value?.type ?? (isCreating.value ? 'string' : 'string')
  if (t === 'boolean') return 'checkbox'
  if (t === 'integer') return 'number'
  return 'text'
})

const modalTitle = computed(() => {
  if (isCreating.value) return 'Add setting'
  const key = selectedSetting.value?.key
  return key ? `Edit · ${key}` : 'Edit setting'
})

/** Label for value control: setting key instead of the word "Value". */
const valueFieldLabel = computed(() => {
  const key = isCreating.value ? formSetting.value.key : selectedSetting.value?.key
  if (key && key.length > 0) return key
  if (isCreating.value) return 'Value (optional, uses default if empty)'
  return 'Value'
})

const cloneSetting = (s: SettingEntity): SettingEntity => JSON.parse(JSON.stringify(s)) as SettingEntity

const isFormDirty = computed(() => {
  if (isCreating.value) return formSetting.value.key.length > 0 || (formSetting.value.value ?? '').length > 0
  if (!baselineSetting.value) return false
  const currentVal = valueInputType.value === 'checkbox' ? (formSettingValueBool.value ? '1' : '0') : (formSetting.value.value ?? '')
  return currentVal !== (baselineSetting.value.value ?? '')
})

const isFormValid = computed(() => {
  if (isCreating.value) return formSetting.value.key.length > 0
  return true
})

const handleAdd = () => {
  isCreating.value = true
  selectedSetting.value = null
  formSetting.value = { key: availableCatalogKeys.value[0] ?? '', value: null }
  baselineSetting.value = null
  showModal.value = true
}

const handleEdit = (item: unknown) => {
  if (typeof item !== 'object' || item === null) return
  const s = item as SettingEntity
  isCreating.value = false
  selectedSetting.value = s
  formSetting.value = { key: s.key, value: s.value ?? null }
  baselineSetting.value = cloneSetting(s)
  showModal.value = true
}

const {
  showDeleteModal,
  deleteTarget,
  deleteLoading,
  resetDeleteModal,
  openDeleteModal,
  confirmDelete,
} = useTableDeleteMutationModal<SettingEntity>(tableKey, (s) => s.key)

const deleteModalTitle = computed(() => {
  const k = deleteTarget.value?.key
  return k ? `Delete · ${k}` : 'Delete setting'
})

const handleDelete = (item: unknown) => {
  if (typeof item !== 'object' || item === null) return
  const s = item as SettingEntity
  if (!s.key) return
  openDeleteModal(s)
}

const confirmDeleteSetting = () => {
  const key = deleteTarget.value?.key
  if (!key) return
  confirmDelete(() => sendAction(websocket, SETTING_DELETE, { key }))
}

const saveLoading = ref(false)

const saveSetting = () => {
  if (!isFormValid.value) return
  saveLoading.value = true

  const value = valueInputType.value === 'checkbox'
    ? (formSettingValueBool.value ? '1' : '0')
    : (formSetting.value.value ?? '')

  if (isCreating.value) {
    sendAction(websocket, SETTING_ADD, {
      key: formSetting.value.key,
      ...(value !== '' ? { value } : {}),
    })
  } else if (selectedSetting.value?.key) {
    if (selectedSetting.value.id == null) {
      sendAction(websocket, SETTING_ADD, {
        key: selectedSetting.value.key,
        ...(value !== '' ? { value } : {}),
      })
    } else {
      sendAction(websocket, SETTING_UPDATE, {
        key: selectedSetting.value.key,
        value,
      })
    }
  }

  resetForm()
}

const resetForm = () => {
  showModal.value = false
  isCreating.value = false
  selectedSetting.value = null
  baselineSetting.value = null
  saveLoading.value = false
  formSetting.value = { key: '', value: null }
}
</script>
