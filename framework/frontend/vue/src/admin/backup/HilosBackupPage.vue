<!-- HilosBackupPage — the framework Hilos backup page (HilosPages.BACKUP): the
stored-backup list inside the admin shell, with its row actions. The list is live
— rows arrive over the socket from the backup runtime index plus the single
in-progress backup, so an in-progress row shows an indeterminate progress bar
until it completes and merges into the index. Its actions (create with a scope
picker, per-row delete, per-row keep toggle) are the core headless's
(createHilosBackupsActions); each dispatches a tracked action and surfaces the
backend's failure (authoritative-backend). All table logic and the row view-model
are the core headless's too; this view owns only the markup, so a project mounts
it by passing its HilosBackupsContext. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  BACKUP_CHECKSUM_STATE_FIELD,
  BACKUP_CREATED_AT_FIELD,
  BACKUP_DURATION_SECONDS_FIELD,
  BACKUP_ENV_FIELD,
  BACKUP_KEEP_FIELD,
  BACKUP_SCOPE_FIELD,
  BACKUP_SIZE_BYTES_FIELD,
  BACKUP_STATUS_FIELD,
  createHilosBackupsActions,
  createHilosBackupsTable,
  formatBackupChecksum,
  formatBackupDuration,
  formatBackupSize,
  hasBackupFailureDetail,
  HILOS_BACKUP_SCOPES,
  HilosPages,
  isBackupChecksumMismatch,
  isBackupDeletable,
  isBackupInProgress,
  isBackupKeepable,
  type HilosBackupRow,
  type HilosBackupsContext,
  type HilosTableColumnOf,
} from '@hilos/core'
import { onMounted, onUnmounted, ref } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosModal from '../../HilosModal.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import LoadingButton from '../../LoadingButton.vue'
import { useTrackedAction } from '../../useTrackedAction.js'

const props = defineProps<{
  /** The project context: scope stores, the connection, and the action lifecycle. */
  context: HilosBackupsContext
}>()

const backups = createHilosBackupsTable(props.context)
const backupsTable = backups.controller
const { sendBackupCreate, sendBackupDelete, sendBackupSetKeep } =
  createHilosBackupsActions(props.context)

// Bind the server-windowed table to the connection on mount, request the first
// window, and unbind on unmount.
onMounted(() => backups.start())
onUnmounted(() => backups.dispose())

const columns: HilosTableColumnOf<HilosBackupRow>[] = [
  { key: BACKUP_CREATED_AT_FIELD, label: 'Date', sortable: true },
  { key: BACKUP_ENV_FIELD, label: 'Environment', sortable: true },
  { key: BACKUP_SCOPE_FIELD, label: 'Scope', sortable: true },
  {
    key: BACKUP_SIZE_BYTES_FIELD,
    label: 'Size',
    sortable: true,
    headerClass: 'text-end',
  },
  { key: BACKUP_CHECKSUM_STATE_FIELD, label: 'Checksum' },
  {
    key: BACKUP_DURATION_SECONDS_FIELD,
    label: 'Duration',
    sortable: true,
    headerClass: 'text-end',
  },
  { key: BACKUP_STATUS_FIELD, label: 'Status', sortable: true },
  { key: BACKUP_KEEP_FIELD, label: 'Keep', headerClass: 'text-center' },
  { key: 'actions', label: '', headerClass: 'text-end' },
]

// Create toolbar: pick a scope and start a backup as a tracked action.
const createScope = ref(HILOS_BACKUP_SCOPES[0].value)
const {
  loading: createLoading,
  busy: createBusy,
  run: runCreateAction,
} = useTrackedAction()

async function submitCreate(): Promise<void> {
  if (createBusy.value) {
    return
  }
  await runCreateAction(sendBackupCreate(createScope.value))
}

// Keep toggle: a per-row switch dispatched as a tracked action; the row stays
// authoritative (the switch reflects the live row's keep, never an optimistic flip).
const { busy: keepBusy, run: runKeepAction } = useTrackedAction()
const keepPendingId = ref<string | null>(null)

async function toggleKeep(row: HilosBackupRow): Promise<void> {
  if (keepBusy.value) {
    return
  }
  keepPendingId.value = row.id
  await runKeepAction(sendBackupSetKeep(row.id, !row.keep))
  keepPendingId.value = null
}

// Delete dialog: a completed backup only (never the in-progress row).
const deleteOpen = ref(false)
const deleteRow = ref<HilosBackupRow | null>(null)
const {
  loading: deleteLoading,
  busy: deleteBusy,
  run: runDeleteAction,
  clearError: clearDeleteError,
} = useTrackedAction()

function openDelete(row: HilosBackupRow): void {
  clearDeleteError()
  deleteRow.value = row
  deleteOpen.value = true
}

function closeDelete(): void {
  deleteOpen.value = false
}

// Failure-detail dialog: a read-only view of a failed backup's stored reason. It
// holds a snapshot of the row it opened on, so a parallel delete or rotation does
// not close it; the text is already in the row, so there is no server request.
const detailsOpen = ref(false)
const detailsRow = ref<HilosBackupRow | null>(null)

function openDetails(row: HilosBackupRow): void {
  detailsRow.value = row
  detailsOpen.value = true
}

async function submitDelete(): Promise<void> {
  const row = deleteRow.value
  if (!row || deleteBusy.value) {
    return
  }
  if (await runDeleteAction(sendBackupDelete(row.id))) {
    closeDelete()
  }
}
</script>

<template>
  <HilosAdminPage :page="HilosPages.BACKUP">
    <div class="d-flex flex-wrap align-items-end gap-2 mb-3">
      <div>
        <label class="form-label" for="hilos-backup-create-scope">Scope</label>
        <select
          id="hilos-backup-create-scope"
          v-model="createScope"
          class="form-select"
          :disabled="createBusy"
          data-id="hilos-backup-create-scope"
        >
          <option
            v-for="scope in HILOS_BACKUP_SCOPES"
            :key="scope.value"
            :value="scope.value"
          >
            {{ scope.label }}
          </option>
        </select>
      </div>
      <LoadingButton
        class="btn-primary"
        :loading="createLoading"
        :disabled="createBusy"
        data-id="hilos-backup-create"
        @click="submitCreate"
      >
        Create backup
      </LoadingButton>
    </div>

    <HilosViewportTable
      label="Backups"
      :controller="backupsTable"
      :columns="columns"
      searchable
      search-placeholder="Search backups…"
      empty-text="No backups yet."
    >
      <template #row="{ row }">
        <td class="text-nowrap">{{ row.createdAt || '—' }}</td>
        <td>{{ row.env || '—' }}</td>
        <td>
          <code>{{ row.scope || '—' }}</code>
        </td>
        <td class="text-end">{{ formatBackupSize(row) }}</td>
        <td class="text-nowrap">
          <span
            :class="
              isBackupChecksumMismatch(row)
                ? 'text-danger fw-semibold'
                : undefined
            "
            >{{ formatBackupChecksum(row) }}</span
          >
        </td>
        <td class="text-end">{{ formatBackupDuration(row) }}</td>
        <td style="min-width: 10rem">
          <div v-if="isBackupInProgress(row)" class="progress" role="status">
            <div
              class="progress-bar progress-bar-striped progress-bar-animated"
              style="width: 100%"
            >
              In progress
            </div>
          </div>
          <span
            v-else-if="row.finished === true"
            class="badge text-bg-success"
            >{{ row.status || 'success' }}</span
          >
          <span v-else class="badge text-bg-danger">{{
            row.status || 'error'
          }}</span>
        </td>
        <td class="text-center">
          <div
            v-if="isBackupKeepable(row)"
            class="form-check form-switch d-inline-block m-0"
          >
            <input
              type="checkbox"
              class="form-check-input"
              role="switch"
              :checked="row.keep"
              :disabled="keepBusy && keepPendingId === row.id"
              :aria-label="
                row.keep ? 'Unpin from rotation' : 'Pin out of rotation'
              "
              :title="
                row.keep ? 'Pinned out of rotation' : 'Pin out of rotation'
              "
              :data-id="`hilos-backup-keep-${row.id}`"
              @change.prevent="toggleKeep(row)"
            />
          </div>
          <span v-else class="text-body-secondary">—</span>
        </td>
        <td class="text-end">
          <button
            v-if="hasBackupFailureDetail(row)"
            type="button"
            class="btn btn-sm btn-outline-secondary me-1"
            title="Show failure reason"
            aria-label="Show failure reason"
            :data-id="`hilos-backup-details-${row.id}`"
            @click="openDetails(row)"
          >
            <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
          </button>
          <button
            v-if="isBackupDeletable(row)"
            type="button"
            class="btn btn-sm btn-outline-danger"
            title="Delete backup"
            aria-label="Delete backup"
            :data-id="`hilos-backup-delete-${row.id}`"
            @click="openDelete(row)"
          >
            <i class="bi bi-trash" aria-hidden="true"></i>
          </button>
        </td>
      </template>
    </HilosViewportTable>

    <HilosModal
      v-model="deleteOpen"
      :title="deleteRow ? `Delete · ${deleteRow.id}` : 'Delete backup'"
      :close-on-backdrop="!deleteBusy"
      :close-on-esc="!deleteBusy"
      @cancel="closeDelete"
    >
      <p class="mb-0 text-body-secondary">
        This permanently deletes the backup archive and its metadata. A pinned
        backup is deleted too — the pin only protects it from rotation.
      </p>
      <p v-if="deleteRow" class="mb-0 mt-2">
        <code>{{ deleteRow.id }}</code>
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
          data-id="hilos-backup-delete-confirm"
          @click="submitDelete"
        >
          Delete
        </LoadingButton>
      </template>
    </HilosModal>

    <HilosModal
      v-model="detailsOpen"
      :title="detailsRow ? `Backup failed · ${detailsRow.id}` : 'Backup failed'"
    >
      <pre
        class="mb-0 small text-break"
        style="max-height: 60vh; overflow-y: auto"
        data-id="hilos-backup-details-text"
        >{{ detailsRow?.failureReason }}</pre
      >
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          data-id="hilos-backup-details-close"
          @click="requestClose"
        >
          Close
        </button>
      </template>
    </HilosModal>
  </HilosAdminPage>
</template>
