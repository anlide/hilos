<!-- HilosBackupPage — the framework Hilos backup page (HilosPages.BACKUP): the
stored-backup list inside the admin shell, with its row actions. The list is live
— rows arrive over the socket from the backup runtime index plus the single
in-progress backup, so an in-progress row shows a live progress bar until it
completes and merges into the index. The bar is drawn from the phase anchors the
row carries and a page-wide one-second clock, and falls back to the indeterminate
striped bar on a run the backend cannot estimate. Its actions (create with a scope
picker, per-row delete, per-row keep toggle, per-row restore) are the core
headless's (createHilosBackupsActions); each dispatches a tracked action and
surfaces the backend's failure (authoritative-backend). Restore is the
destructive one: it is offered as a button only where the backend says so
(everywhere but production), it confirms by typing the archive id, and while it
runs the addressed progress frames are the only live thing on the page — the
node is frozen and the table sends nothing. All table logic and the row view-model
are the core headless's too; this view owns only the markup, so a project mounts
it by passing its HilosBackupsContext. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  BACKUP_CHECKSUM_STATE_FIELD,
  BACKUP_SHIP_STATE_FIELD,
  BACKUP_CREATED_AT_FIELD,
  BACKUP_DURATION_SECONDS_FIELD,
  BACKUP_ENV_FIELD,
  BACKUP_KEEP_FIELD,
  BACKUP_RESTORE_OUTCOME_FIELD,
  BACKUP_SCOPE_FIELD,
  BACKUP_SIZE_BYTES_FIELD,
  BACKUP_STATUS_FIELD,
  backupMigrationBehind,
  backupMigrationNotes,
  backupProgressPercent,
  backupRowAnchors,
  createBackupProgressClock,
  createHilosBackupsActions,
  createHilosBackupsRestoreGate,
  createHilosBackupsTable,
  createHilosRestoreProgress,
  formatBackupChecksum,
  formatBackupShipping,
  formatBackupDuration,
  formatBackupProgressLabel,
  formatBackupSize,
  formatRestoreCliCommand,
  copyToClipboard,
  hasBackupFailureDetail,
  hasRestoreOutcome,
  HILOS_BACKUP_SCOPES,
  HilosPages,
  isBackupChecksumMismatch,
  isBackupShipFailed,
  isBackupDeletable,
  isBackupInProgress,
  isBackupKeepable,
  isBackupMigrationRefused,
  isBackupRestorable,
  isBackupSubsystemBusy,
  offersBackupRestore,
  type HilosBackupRow,
  type HilosBackupsContext,
  type HilosTableColumnOf,
} from '@hilos/core'
import { computed, onMounted, onUnmounted, ref } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosModal from '../../HilosModal.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import LoadingButton from '../../LoadingButton.vue'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

const props = defineProps<{
  /** The project context: scope stores, the connection, and the action lifecycle. */
  context: HilosBackupsContext
}>()

const backups = createHilosBackupsTable(props.context)
const backupsTable = backups.controller
const {
  sendBackupCreate,
  sendBackupDelete,
  sendBackupSetKeep,
  sendBackupRestore,
} = createHilosBackupsActions(props.context)

// What this installation offers for restoring, and the live frames a restore this
// tab started sends back while the node is frozen.
const restoreGate = useSignal(createHilosBackupsRestoreGate(props.context))
const restoreProgress = createHilosRestoreProgress(props.context.connection)
const restoreStatus = useSignal(restoreProgress.status)
const rows = useSignal(backupsTable.rows)

// One ticker for the whole page: a percentage moves with wall time, while the socket
// only speaks on a change of phase, so every bar here redraws from this signal.
const progressClock = createBackupProgressClock()
const progressNow = useSignal(progressClock.now)
const restorePercent = computed(() =>
  restoreStatus.value === null
    ? null
    : backupProgressPercent(restoreStatus.value, progressNow.value),
)
const restoreProgressLabel = computed(() =>
  restoreStatus.value === null
    ? ''
    : formatBackupProgressLabel(restoreStatus.value, progressNow.value),
)
const subsystemBusy = computed(() =>
  isBackupSubsystemBusy(
    rows.value.map((entry) => entry.row),
    restoreStatus.value,
  ),
)

// Bind the server-windowed table to the connection on mount, request the first
// window, and unbind on unmount.
onMounted(() => {
  backups.start()
  restoreProgress.start()
})
onUnmounted(() => {
  backups.dispose()
  restoreProgress.dispose()
  progressClock.dispose()
})

/**
 * How far along the run of this row is, or null when it cannot be told — an
 * installation with no history to estimate from, or a phase this build does not know.
 *
 * @param row The backup row being rendered.
 */
function rowProgressPercent(row: HilosBackupRow): number | null {
  return backupProgressPercent(backupRowAnchors(row), progressNow.value)
}

/**
 * The caption under this row's bar: the phase, the percentage, and the time left,
 * each dropped when the run cannot say it.
 *
 * @param row The backup row being rendered.
 */
function rowProgressLabel(row: HilosBackupRow): string {
  return formatBackupProgressLabel(backupRowAnchors(row), progressNow.value)
}

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
  { key: BACKUP_SHIP_STATE_FIELD, label: 'Copy' },
  {
    key: BACKUP_DURATION_SECONDS_FIELD,
    label: 'Duration',
    sortable: true,
    headerClass: 'text-end',
  },
  { key: BACKUP_STATUS_FIELD, label: 'Status', sortable: true },
  { key: BACKUP_RESTORE_OUTCOME_FIELD, label: 'Restore' },
  { key: BACKUP_KEEP_FIELD, label: 'Keep', headerClass: 'text-center' },
  { key: 'actions', label: '', headerClass: 'text-end' },
]

/**
 * Why an archive cannot be restored right now, or null when it can. The button
 * stays visible and carries this as its title, so the answer arrives before the
 * click rather than as a toast after it.
 *
 * @param row The backup row the button belongs to.
 */
function restoreBlockedReason(row: HilosBackupRow): string | null {
  if (isBackupChecksumMismatch(row)) {
    return 'This archive does not match its recorded checksum'
  }
  // What makes the archive unusable forever comes before what the subsystem is
  // doing right now: waiting for the current run would not make this one restorable.
  if (isBackupMigrationRefused(row)) {
    return (
      row.restoreMigrationNotice ??
      'This archive was taken on newer code; there is no downgrade path'
    )
  }
  // The shared predicate has the last word on whether the archive may be replayed at
  // all: a rule added there and not worded here must still disable the button.
  if (!isBackupRestorable(row)) {
    return 'This archive cannot be restored'
  }

  return subsystemBusy.value
    ? 'The backup subsystem is busy; wait for the current run to end'
    : null
}

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

// Restore dialog: the destructive one. Confirmation is typing the archive's id —
// the one barrier muscle memory cannot pass, and the one that makes the operator
// read WHICH archive they picked, since the likely mistake here is restoring the
// wrong one rather than clicking the wrong button.
const restoreOpen = ref(false)
const restoreRow = ref<HilosBackupRow | null>(null)
const restoreTyped = ref('')
const {
  loading: restoreLoading,
  busy: restoreBusy,
  run: runRestoreAction,
  clearError: clearRestoreError,
} = useTrackedAction()
const restoreConfirmed = computed(
  () => restoreRow.value !== null && restoreTyped.value === restoreRow.value.id,
)

function openRestore(row: HilosBackupRow): void {
  clearRestoreError()
  restoreRow.value = row
  restoreTyped.value = ''
  restoreOpen.value = true
}

function closeRestore(): void {
  restoreOpen.value = false
}

async function submitRestore(): Promise<void> {
  const row = restoreRow.value
  if (!row || restoreBusy.value || !restoreConfirmed.value) {
    return
  }
  if (await runRestoreAction(sendBackupRestore(row.id))) {
    closeRestore()
  }
}

// CLI instruction dialog: what the production surface offers instead of a button.
const cliOpen = ref(false)
const cliRow = ref<HilosBackupRow | null>(null)
const cliCopied = ref(false)

function openCli(row: HilosBackupRow): void {
  cliRow.value = row
  cliCopied.value = false
  cliOpen.value = true
}

async function copyCliCommand(): Promise<void> {
  const row = cliRow.value
  if (!row) {
    return
  }
  cliCopied.value = await copyToClipboard(formatRestoreCliCommand(row))
}

// Restore-outcome dialog: how the last restore of this archive ended, read from the
// row, so it survives the reload the successful path ends with.
const outcomeOpen = ref(false)
const outcomeRow = ref<HilosBackupRow | null>(null)

function openOutcome(row: HilosBackupRow): void {
  outcomeRow.value = row
  outcomeOpen.value = true
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

    <div
      v-if="restoreStatus"
      class="alert"
      :class="
        restoreStatus.outcome === 'error'
          ? 'alert-danger'
          : restoreStatus.outcome === 'success'
            ? 'alert-success'
            : 'alert-info'
      "
      role="status"
      data-id="hilos-backup-restore-phase"
    >
      <div class="fw-semibold">
        Restore {{ restoreStatus.backupId }} · {{ restoreStatus.phase }}
      </div>
      <div v-if="restoreStatus.outcome === 'success'" class="small">
        Restored. This page reloads itself as soon as the system reopens.
      </div>
      <div v-else-if="restoreStatus.outcome === 'error'" class="small">
        <div>{{ restoreStatus.failureReason }}</div>
        <div v-if="restoreStatus.databaseTouched">
          The database was already being replaced when this failed.
        </div>
        <div v-if="!restoreStatus.rehydrateComplete">
          The system stays closed: not every process re-read the replaced
          database. Reopen it from the CLI with
          <code>php cli.php protected-mode:open</code>.
        </div>
      </div>
      <template v-else>
        <div
          class="progress mt-2"
          role="progressbar"
          aria-label="Restore progress"
          aria-valuemin="0"
          aria-valuemax="100"
          :aria-valuenow="restorePercent ?? undefined"
          data-id="hilos-backup-progress-bar"
        >
          <div
            :class="
              restorePercent === null
                ? 'progress-bar progress-bar-striped progress-bar-animated'
                : 'progress-bar'
            "
            :style="{ width: `${restorePercent ?? 100}%` }"
          ></div>
        </div>
        <div class="small" data-id="hilos-backup-progress-label">
          {{ restoreProgressLabel }}
        </div>
      </template>
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
        <td class="text-nowrap">
          <span
            :class="
              isBackupShipFailed(row) ? 'text-danger fw-semibold' : undefined
            "
            :title="row.shipError ?? undefined"
            >{{ formatBackupShipping(row) }}</span
          >
        </td>
        <td class="text-end">{{ formatBackupDuration(row) }}</td>
        <td style="min-width: 10rem">
          <template v-if="isBackupInProgress(row)">
            <div
              class="progress"
              role="progressbar"
              aria-label="Backup progress"
              aria-valuemin="0"
              aria-valuemax="100"
              :aria-valuenow="rowProgressPercent(row) ?? undefined"
              data-id="hilos-backup-progress-bar"
            >
              <div
                :class="
                  rowProgressPercent(row) === null
                    ? 'progress-bar progress-bar-striped progress-bar-animated'
                    : 'progress-bar'
                "
                :style="{ width: `${rowProgressPercent(row) ?? 100}%` }"
              ></div>
            </div>
            <div class="small" data-id="hilos-backup-progress-label">
              {{ rowProgressLabel(row) }}
            </div>
          </template>
          <span
            v-else-if="row.finished === true"
            class="badge text-bg-success"
            >{{ row.status }}</span
          >
          <span v-else class="badge text-bg-danger">{{ row.status }}</span>
        </td>
        <td class="text-nowrap">
          <button
            v-if="hasRestoreOutcome(row)"
            type="button"
            class="btn btn-sm p-0 border-0 bg-transparent"
            :title="`Show how the restore of ${row.id} ended`"
            :data-id="`hilos-backup-restore-outcome-${row.id}`"
            @click="openOutcome(row)"
          >
            <span
              class="badge"
              :class="
                row.restoreOutcome === 'success'
                  ? 'text-bg-success'
                  : 'text-bg-danger'
              "
              >{{ row.restoreOutcome }}</span
            >
          </button>
          <span v-else-if="row.restorePhase" class="badge text-bg-info">{{
            row.restorePhase
          }}</span>
          <!-- What happened to this archive outranks what could: the badge speaks
          only where no restore of it has anything to report. -->
          <span
            v-else-if="isBackupMigrationRefused(row)"
            class="badge text-bg-danger"
            :data-id="`hilos-backup-migration-${row.id}`"
            >incompatible</span
          >
          <span
            v-else-if="backupMigrationBehind(row) !== null"
            class="badge text-bg-warning"
            :data-id="`hilos-backup-migration-${row.id}`"
            >+{{ backupMigrationBehind(row) }} migrations</span
          >
          <span v-else class="text-body-secondary">—</span>
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
          <template v-if="offersBackupRestore(row)">
            <button
              v-if="restoreGate.uiEnabled"
              type="button"
              class="btn btn-sm btn-outline-warning me-1"
              :disabled="restoreBlockedReason(row) !== null"
              :title="restoreBlockedReason(row) ?? 'Restore this backup'"
              aria-label="Restore this backup"
              :data-id="`hilos-backup-restore-${row.id}`"
              @click="openRestore(row)"
            >
              <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
            </button>
            <button
              v-else
              type="button"
              class="btn btn-sm btn-outline-secondary me-1"
              title="How to restore this backup"
              aria-label="How to restore this backup"
              :data-id="`hilos-backup-restore-cli-${row.id}`"
              @click="openCli(row)"
            >
              <i class="bi bi-terminal" aria-hidden="true"></i>
            </button>
          </template>
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

    <HilosModal
      v-model="restoreOpen"
      :title="restoreRow ? `Restore · ${restoreRow.id}` : 'Restore backup'"
      :close-on-backdrop="!restoreBusy"
      :close-on-esc="!restoreBusy"
      @cancel="closeRestore"
    >
      <p class="mb-2">
        This overwrites every database of this installation with the contents of
        the archive. Everyone else is shown a maintenance screen until it ends,
        and if the system does not come back on its own it is reopened from the
        CLI.
      </p>
      <p v-if="restoreRow" class="mb-2 text-body-secondary">
        Archive taken in
        <code>{{ restoreRow.env || 'an unnamed environment' }}</code> → this
        installation is <code>{{ restoreGate.targetEnv || 'unnamed' }}</code>
      </p>
      <ul
        v-if="restoreRow && backupMigrationNotes(restoreRow).length > 0"
        class="mb-2 ps-3 text-body-secondary"
        data-id="hilos-backup-migration-notes"
      >
        <li v-for="note in backupMigrationNotes(restoreRow)" :key="note">
          {{ note }}
        </li>
      </ul>
      <label class="form-label" for="hilos-backup-restore-id">
        Type the archive id to confirm
      </label>
      <input
        id="hilos-backup-restore-id"
        v-model="restoreTyped"
        type="text"
        class="form-control"
        autocomplete="off"
        :disabled="restoreBusy"
        :placeholder="restoreRow?.id"
        data-id="hilos-backup-restore-id"
      />
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="restoreBusy"
          @click="requestClose"
        >
          Cancel
        </button>
        <LoadingButton
          class="btn-warning"
          :loading="restoreLoading"
          :disabled="!restoreConfirmed"
          data-id="hilos-backup-restore-confirm"
          @click="submitRestore"
        >
          Restore
        </LoadingButton>
      </template>
    </HilosModal>

    <HilosModal
      v-model="cliOpen"
      :title="cliRow ? `How to restore · ${cliRow.id}` : 'How to restore'"
    >
      <p class="mb-2 text-body-secondary">
        Restoring is not offered from the browser on this environment. Run this
        on the machine that hosts the installation:
      </p>
      <pre
        class="mb-0 small text-break"
        data-id="hilos-backup-restore-cli-text"
        >{{ cliRow ? formatRestoreCliCommand(cliRow) : '' }}</pre
      >
      <!-- The same lines the button's title carries where there is a button: an
      operator on production learns of an incompatible archive here, not from the
      command refusing after they have walked to the terminal. -->
      <ul
        v-if="cliRow && backupMigrationNotes(cliRow).length > 0"
        class="mt-2 mb-0 ps-3 text-body-secondary"
        data-id="hilos-backup-migration-cli-notes"
      >
        <li v-for="note in backupMigrationNotes(cliRow)" :key="note">
          {{ note }}
        </li>
      </ul>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          data-id="hilos-backup-restore-cli-copy"
          @click="copyCliCommand"
        >
          {{ cliCopied ? 'Copied' : 'Copy' }}
        </button>
        <button type="button" class="btn btn-primary" @click="requestClose">
          Close
        </button>
      </template>
    </HilosModal>

    <HilosModal
      v-model="outcomeOpen"
      :title="
        outcomeRow ? `Restore · ${outcomeRow.id}` : 'Restore of this backup'
      "
    >
      <p class="mb-2">
        Finished {{ outcomeRow?.restoreFinishedAt || '—' }} ·
        <span class="fw-semibold">{{ outcomeRow?.restoreOutcome }}</span>
      </p>
      <p v-if="outcomeRow?.restoreDatabaseTouched" class="mb-2">
        The database was already being replaced when this run ended.
      </p>
      <pre
        class="mb-0 small text-break"
        style="max-height: 60vh; overflow-y: auto"
        data-id="hilos-backup-restore-outcome-text"
        >{{ outcomeRow?.restoreFailureReason || 'No failure recorded.' }}</pre
      >
      <template #actions="{ requestClose }">
        <button type="button" class="btn btn-secondary" @click="requestClose">
          Close
        </button>
      </template>
    </HilosModal>
  </HilosAdminPage>
</template>
