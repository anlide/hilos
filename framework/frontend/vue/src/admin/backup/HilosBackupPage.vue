<!-- HilosBackupPage — the framework Hilos backup page (HilosPages.BACKUP): the
stored-backup list inside the admin shell, with its row actions. The list is live
— rows arrive over the socket from the backup runtime index, and a run in flight is
not one of them: it stands as the bar above the table, with the caption the core
assembles out of the bar's own figures (HIL-820). Its actions (create with a scope
picker, per-row delete, per-row keep toggle, per-row restore) are the core
headless's (createHilosBackupsActions); each dispatches a tracked action and
surfaces the backend's failure (authoritative-backend). Restore is the
destructive one: it is offered as a button only where the backend says so
(everywhere but production), it confirms by typing the archive id, and while it
runs the addressed progress frames are the only live thing on the page — the
node is frozen and the table sends nothing. Once it ends, the node stands in a
verification window, and the one browser that started the restore is offered the
block that closes it — the backend answers that personally in the page-data
section, so a second admin looking at the same page sees nothing. All table logic,
the row view-model, and what the list declares about its frame — columns, search,
the scope and period filters, empty state — are the core headless's too; this view
owns only the markup, so a project mounts it by passing its HilosBackupsContext.
Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  backupMigrationBehind,
  backupMigrationNotes,
  backupProgressPercent,
  createBackupProgressClock,
  BACKUP_CIRCLE_IDENTIFIER_FIELD,
  BACKUP_CIRCLE_ONLINE_FIELD,
  createHilosBackupsActions,
  createHilosBackupsCircleTable,
  createHilosBackupsReopenGate,
  createHilosBackupsRestoreGate,
  createHilosBackupsTable,
  createHilosRestoreProgress,
  formatBackupChecksum,
  formatBackupOutOfReach,
  formatBackupShipping,
  formatBackupDuration,
  formatBackupProgressLabel,
  formatBackupRunCaption,
  formatBackupSize,
  formatRestoreCliCommand,
  formatRestoreOutcomeLine,
  hasBackupFailureDetail,
  hasRestoreOutcome,
  HILOS_BACKUP_CIRCLE_COPY,
  HILOS_BACKUP_REOPEN_COPY,
  HILOS_BACKUP_SCOPES,
  HilosPages,
  isBackupChecksumMismatch,
  isBackupShipFailed,
  isBackupDeletable,
  isBackupKeepable,
  isBackupMigrationRefused,
  isBackupOutOfReach,
  isBackupRestorable,
  isBackupSubsystemBusy,
  offersBackupRestore,
  type HilosBackupCircleRow,
  type HilosBackupRow,
  type HilosBackupsContext,
  type HilosTableColumnOf,
} from '@hilos/core'
import { computed, onMounted, onUnmounted, ref } from 'vue'

import HilosActionError from '../../HilosActionError.vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosLongText from '../../HilosLongText.vue'
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
// The page's second table, on the same page scope under its own key: who the operator
// named to check the system after a restore.
const circle = createHilosBackupsCircleTable(props.context)
const circleTable = circle.controller
const {
  sendBackupCreate,
  sendBackupDelete,
  sendBackupSetKeep,
  sendBackupRestore,
  sendBackupReopen,
  sendBackupCircleAdd,
  sendBackupCircleRemove,
} = createHilosBackupsActions(props.context)

// What this installation offers for restoring, and the live frames a restore this
// tab started sends back while the node is frozen.
const restoreGate = useSignal(createHilosBackupsRestoreGate(props.context))
// Whether THIS browser is the one that may end the verification window a restore left
// the node in. Personal to the subscription, so a second admin's tab reads false.
const reopenOffered = useSignal(createHilosBackupsReopenGate(props.context))
const restoreProgress = createHilosRestoreProgress(props.context.connection)
const restoreStatus = useSignal(restoreProgress.status)

// The ticker of the restore panel: its percentage moves with wall time, while its
// addressed frame only speaks on a change of phase. The create run needs none of this —
// its figures arrive counted on the table's bar.
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
// The run in flight is the table's own bar, which lives outside the window: the button
// therefore stays honest on every page of the list, not only on the one the run was on.
const tableProgress = useSignal(backupsTable.progress.table)
const subsystemBusy = computed(() =>
  isBackupSubsystemBusy(tableProgress.value, restoreStatus.value),
)

// Bind the server-windowed table to the connection on mount, request the first
// window, and unbind on unmount.
onMounted(() => {
  backups.start()
  circle.start()
  restoreProgress.start()
})
onUnmounted(() => {
  backups.dispose()
  circle.dispose()
  restoreProgress.dispose()
  progressClock.dispose()
})

const circleColumns: HilosTableColumnOf<HilosBackupCircleRow>[] = [
  { key: BACKUP_CIRCLE_IDENTIFIER_FIELD, label: 'Address', sortable: true },
  { key: BACKUP_CIRCLE_ONLINE_FIELD, label: 'Signed in' },
  {
    key: 'actions',
    label: '',
    headerClass: 'text-end',
    // The remove button and its confirmation name the address.
    reads: [BACKUP_CIRCLE_IDENTIFIER_FIELD],
  },
]

/**
 * Why an archive cannot be restored right now, or null when it can. The button
 * stays visible and a live "why" button beside it opens this sentence, so the
 * answer arrives before the click rather than as a toast after it. It is not the
 * button's title: a disabled button gets no mouse events, so its title never shows
 * (docs/agents/frontend/accessibility.md).
 *
 * @param row The backup row the button belongs to.
 */
function restoreBlockedReason(row: HilosBackupRow): string | null {
  // An archive on another node's disk is out of reach whatever else is true of it.
  const outOfReach = formatBackupOutOfReach(row)
  if (outOfReach !== null) {
    return outOfReach
  }
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

// An archive another node holds stays in the list, muted cell by cell: the row and its
// cells belong to the table, so the mark is worn by a wrapper this page draws inside
// each cell. The controls take none: a button carries its own color, and a wrapper
// around them would fold the controls a card stacks full width into a single item.
function outOfReachClass(row: HilosBackupRow): string | undefined {
  return isBackupOutOfReach(row) ? 'text-body-secondary' : undefined
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

// Delete dialog: every row of this set is a run that ended, so every one can be deleted.
const deleteOpen = ref(false)
const deleteRow = ref<HilosBackupRow | null>(null)
const deleteAction = useTrackedAction()
const {
  loading: deleteLoading,
  busy: deleteBusy,
  run: runDeleteAction,
  clearError: clearDeleteError,
} = deleteAction

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

// Blocked-restore dialog: the sentence behind the "why" button of a dark restore
// button. It shows whatever restoreBlockedReason() returns rather than knowing the
// cases, so a reason added there is shown here without a word changed.
const blockedOpen = ref(false)
const blockedRow = ref<HilosBackupRow | null>(null)

function openBlocked(row: HilosBackupRow): void {
  blockedRow.value = row
  blockedOpen.value = true
}

// Copy-failure dialog: why the last copy of an archive off the machine did not make
// it. The word stays in the cell; the reason is one click away rather than in a title
// no phone and no screen reader reaches.
const shipErrorOpen = ref(false)
const shipErrorRow = ref<HilosBackupRow | null>(null)

function openShipError(row: HilosBackupRow): void {
  shipErrorRow.value = row
  shipErrorOpen.value = true
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
const restoreAction = useTrackedAction()
const {
  loading: restoreLoading,
  busy: restoreBusy,
  run: runRestoreAction,
  clearError: clearRestoreError,
} = restoreAction
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

// Reopen dialog: ending the verification window. Confirmation is the modal itself and
// nothing more — typing the archive id guards restore against picking the WRONG target,
// and reopening has no target to miss; the only mistake left is the click.
const reopenOpen = ref(false)
const reopenAction = useTrackedAction()
const {
  loading: reopenLoading,
  busy: reopenBusy,
  run: runReopenAction,
  clearError: clearReopenError,
} = reopenAction

function openReopen(): void {
  clearReopenError()
  reopenOpen.value = true
}

function closeReopen(): void {
  reopenOpen.value = false
}

async function submitReopen(): Promise<void> {
  if (reopenBusy.value) {
    return
  }
  if (await runReopenAction(sendBackupReopen())) {
    closeReopen()
  }
}

// Circle dialogs. Adding takes one field and removing takes a confirmation, because the
// two mistakes are different: a mistyped address is refused by the server and costs a
// second try, while a removal is silent and only noticed after the next restore.
const circleAddOpen = ref(false)
const circleAddIdentifier = ref('')
const circleAddAction = useTrackedAction()
const {
  loading: circleAddLoading,
  busy: circleAddBusy,
  run: runCircleAddAction,
  clearError: clearCircleAddError,
} = circleAddAction

function openCircleAdd(): void {
  clearCircleAddError()
  circleAddIdentifier.value = ''
  circleAddOpen.value = true
}

function closeCircleAdd(): void {
  circleAddOpen.value = false
}

async function submitCircleAdd(): Promise<void> {
  if (circleAddBusy.value || circleAddIdentifier.value.trim() === '') {
    return
  }
  if (
    await runCircleAddAction(sendBackupCircleAdd(circleAddIdentifier.value))
  ) {
    closeCircleAdd()
  }
}

const circleRemoveOpen = ref(false)
const circleRemoveRow = ref<HilosBackupCircleRow | null>(null)
const circleRemoveAction = useTrackedAction()
const {
  loading: circleRemoveLoading,
  busy: circleRemoveBusy,
  run: runCircleRemoveAction,
  clearError: clearCircleRemoveError,
} = circleRemoveAction

function openCircleRemove(row: HilosBackupCircleRow): void {
  clearCircleRemoveError()
  circleRemoveRow.value = row
  circleRemoveOpen.value = true
}

function closeCircleRemove(): void {
  circleRemoveOpen.value = false
}

async function submitCircleRemove(): Promise<void> {
  const row = circleRemoveRow.value
  if (!row || circleRemoveBusy.value) {
    return
  }
  if (await runCircleRemoveAction(sendBackupCircleRemove(row.memberId))) {
    closeCircleRemove()
  }
}

// CLI instruction dialog: what the production surface offers instead of a button.
const cliOpen = ref(false)
const cliRow = ref<HilosBackupRow | null>(null)

function openCli(row: HilosBackupRow): void {
  cliRow.value = row
  cliOpen.value = true
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
        {{ formatRestoreOutcomeLine(restoreStatus) }}
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

    <div
      v-if="reopenOffered"
      class="alert alert-warning"
      role="status"
      data-id="hilos-backup-reopen-panel"
    >
      <div class="fw-semibold">{{ HILOS_BACKUP_REOPEN_COPY.title }}</div>
      <div class="small">{{ HILOS_BACKUP_REOPEN_COPY.body }}</div>
      <button
        type="button"
        class="btn btn-warning btn-sm mt-2"
        data-id="hilos-backup-reopen"
        @click="openReopen"
      >
        {{ HILOS_BACKUP_REOPEN_COPY.button }}
      </button>
    </div>

    <div class="card mb-3" data-id="hilos-backup-circle-panel">
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-2">
          <div>
            <div class="fw-semibold">{{ HILOS_BACKUP_CIRCLE_COPY.title }}</div>
            <div class="small text-body-secondary">
              {{ HILOS_BACKUP_CIRCLE_COPY.rule }}
            </div>
            <div class="small text-body-secondary">
              {{ HILOS_BACKUP_CIRCLE_COPY.volatile }}
            </div>
          </div>
          <button
            type="button"
            class="btn btn-outline-primary btn-sm text-nowrap"
            data-id="hilos-backup-circle-add"
            @click="openCircleAdd"
          >
            {{ HILOS_BACKUP_CIRCLE_COPY.addButton }}
          </button>
        </div>
        <div class="mt-3">
          <HilosViewportTable
            data-id="hilos-backup-circle-table"
            :label="HILOS_BACKUP_CIRCLE_COPY.title"
            :controller="circleTable"
            :columns="circleColumns"
            :empty-text="HILOS_BACKUP_CIRCLE_COPY.empty"
          >
            <template #row="{ row }">
              <td :data-id="`hilos-backup-circle-row-${row.identifier}`">
                {{ row.identifier }}
              </td>
              <td>
                <span
                  :class="row.online ? 'text-success' : 'text-body-secondary'"
                  :data-id="`hilos-backup-circle-online-${row.identifier}`"
                  >{{
                    row.online
                      ? HILOS_BACKUP_CIRCLE_COPY.online
                      : HILOS_BACKUP_CIRCLE_COPY.offline
                  }}</span
                >
              </td>
              <td class="text-end">
                <button
                  type="button"
                  class="btn btn-sm btn-outline-danger"
                  :title="HILOS_BACKUP_CIRCLE_COPY.removeTitle"
                  :aria-label="HILOS_BACKUP_CIRCLE_COPY.removeTitle"
                  :data-id="`hilos-backup-circle-remove-${row.identifier}`"
                  @click="openCircleRemove(row)"
                >
                  <i class="bi bi-trash" aria-hidden="true"></i>
                </button>
              </td>
            </template>
          </HilosViewportTable>
        </div>
      </div>
    </div>

    <HilosViewportTable :controller="backupsTable">
      <template #table-progress="{ progress }">{{
        formatBackupRunCaption(progress)
      }}</template>
      <template #cell-createdAt="{ row }">
        <span :class="outOfReachClass(row)">
          {{ row.createdAt || '—' }}
          <template v-if="isBackupOutOfReach(row)">
            <span
              class="badge text-bg-secondary"
              :title="formatBackupOutOfReach(row) ?? undefined"
              :data-id="`hilos-backup-holder-${row.id}`"
              >{{ row.holderNode }}</span
            ><span class="visually-hidden">{{
              formatBackupOutOfReach(row)
            }}</span>
          </template>
        </span>
      </template>
      <template #cell-env="{ row }">
        <span :class="outOfReachClass(row)">{{ row.env || '—' }}</span>
      </template>
      <template #cell-scope="{ row }">
        <span :class="outOfReachClass(row)">
          <code>{{ row.scope || '—' }}</code>
        </span>
      </template>
      <template #cell-sizeBytes="{ row }">
        <span :class="outOfReachClass(row)">{{ formatBackupSize(row) }}</span>
      </template>
      <template #cell-checksumState="{ row }">
        <span :class="outOfReachClass(row)">
          <span
            :class="
              isBackupChecksumMismatch(row)
                ? 'text-danger fw-semibold'
                : undefined
            "
            >{{ formatBackupChecksum(row) }}</span
          >
        </span>
      </template>
      <template #cell-shipState="{ row }">
        <span :class="outOfReachClass(row)">
          <span
            :class="
              isBackupShipFailed(row) ? 'text-danger fw-semibold' : undefined
            "
            >{{ formatBackupShipping(row) }}</span
          >
          <button
            v-if="isBackupShipFailed(row) && row.shipError"
            type="button"
            class="btn btn-sm btn-outline-secondary ms-1"
            title="Why the copy failed"
            aria-label="Why the copy failed"
            :data-id="`hilos-backup-ship-why-${row.id}`"
            @click="openShipError(row)"
          >
            <i class="bi bi-question-circle" aria-hidden="true"></i>
          </button>
        </span>
      </template>
      <template #cell-durationSeconds="{ row }">
        <span :class="outOfReachClass(row)">{{
          formatBackupDuration(row)
        }}</span>
      </template>
      <template #cell-status="{ row }">
        <div style="min-width: 10rem" :class="outOfReachClass(row)">
          <span v-if="row.finished === true" class="badge text-bg-success">{{
            row.status
          }}</span>
          <span v-else class="badge text-bg-danger">{{ row.status }}</span>
        </div>
      </template>
      <template #cell-restoreOutcome="{ row }">
        <span :class="outOfReachClass(row)">
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
        </span>
      </template>
      <template #cell-keep="{ row }">
        <div :class="outOfReachClass(row)">
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
              :title="row.keep ? 'Unpin from rotation' : 'Pin out of rotation'"
              :data-id="`hilos-backup-keep-${row.id}`"
              @change.prevent="toggleKeep(row)"
            />
          </div>
          <span v-else class="text-body-secondary">—</span>
        </div>
      </template>
      <template #cell-actions="{ row }">
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
          <template v-if="restoreGate.uiEnabled">
            <button
              v-if="restoreBlockedReason(row) !== null"
              type="button"
              class="btn btn-sm btn-outline-secondary me-1"
              title="Why this backup cannot be restored"
              aria-label="Why this backup cannot be restored"
              :data-id="`hilos-backup-blocked-why-${row.id}`"
              @click="openBlocked(row)"
            >
              <i class="bi bi-question-circle" aria-hidden="true"></i>
            </button>
            <button
              type="button"
              class="btn btn-sm btn-outline-warning me-1"
              :disabled="restoreBlockedReason(row) !== null"
              title="Restore this backup"
              aria-label="Restore this backup"
              :data-id="`hilos-backup-restore-${row.id}`"
              @click="openRestore(row)"
            >
              <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
            </button>
          </template>
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
      </template>
    </HilosViewportTable>

    <HilosModal
      v-model="deleteOpen"
      :title="deleteRow ? `Delete · ${deleteRow.id}` : 'Delete backup'"
      :close-on-backdrop="!deleteBusy"
      :close-on-esc="!deleteBusy"
      initial-focus="dialog"
      @cancel="closeDelete"
    >
      <HilosActionError :action="deleteAction" />
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
      v-model="reopenOpen"
      :title="HILOS_BACKUP_REOPEN_COPY.modalTitle"
      :close-on-backdrop="!reopenBusy"
      :close-on-esc="!reopenBusy"
      initial-focus="dialog"
      @cancel="closeReopen"
    >
      <HilosActionError :action="reopenAction" />
      <p class="mb-0 text-body-secondary">
        {{ HILOS_BACKUP_REOPEN_COPY.modalBody }}
      </p>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="reopenBusy"
          @click="requestClose"
        >
          Cancel
        </button>
        <LoadingButton
          class="btn-warning"
          :loading="reopenLoading"
          data-id="hilos-backup-reopen-confirm"
          @click="submitReopen"
        >
          Reopen
        </LoadingButton>
      </template>
    </HilosModal>

    <HilosModal
      v-model="detailsOpen"
      :title="detailsRow ? `Backup failed · ${detailsRow.id}` : 'Backup failed'"
      initial-focus="dialog"
    >
      <HilosLongText
        kind="prose"
        :text="detailsRow?.failureReason ?? ''"
        data-id="hilos-backup-details-text"
      />
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
      v-model="blockedOpen"
      :title="
        blockedRow ? `Cannot restore · ${blockedRow.id}` : 'Cannot restore'
      "
      initial-focus="dialog"
    >
      <HilosLongText
        kind="prose"
        :text="blockedRow ? (restoreBlockedReason(blockedRow) ?? '') : ''"
        data-id="hilos-backup-blocked-reason-text"
      />
      <template #actions="{ requestClose }">
        <button type="button" class="btn btn-secondary" @click="requestClose">
          Close
        </button>
      </template>
    </HilosModal>

    <HilosModal
      v-model="shipErrorOpen"
      :title="shipErrorRow ? `Copy failed · ${shipErrorRow.id}` : 'Copy failed'"
      initial-focus="dialog"
    >
      <HilosLongText
        kind="prose"
        :text="shipErrorRow?.shipError ?? ''"
        data-id="hilos-backup-ship-error-text"
      />
      <template #actions="{ requestClose }">
        <button type="button" class="btn btn-secondary" @click="requestClose">
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
      <HilosActionError :action="restoreAction" />
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
        data-autofocus
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
      :copy-text="cliRow ? formatRestoreCliCommand(cliRow) : ''"
      initial-focus="dialog"
    >
      <p class="mb-2 text-body-secondary">
        Restoring is not offered from the browser on this environment. Run this
        on the machine that hosts the installation:
      </p>
      <HilosLongText
        kind="output"
        :text="cliRow ? formatRestoreCliCommand(cliRow) : ''"
        data-id="hilos-backup-restore-cli-text"
      />
      <!-- What the "why" dialog of a dark restore button says where there is a
      button: an operator on production learns of an incompatible archive here, not
      from the command refusing after they have walked to the terminal. -->
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
      initial-focus="dialog"
    >
      <p class="mb-2">
        Finished {{ outcomeRow?.restoreFinishedAt || '—' }} ·
        <span class="fw-semibold">{{ outcomeRow?.restoreOutcome }}</span>
      </p>
      <p v-if="outcomeRow?.restoreDatabaseTouched" class="mb-2">
        The database was already being replaced when this run ended.
      </p>
      <HilosLongText
        kind="prose"
        :text="outcomeRow?.restoreFailureReason || 'No failure recorded.'"
        data-id="hilos-backup-restore-outcome-text"
      />
      <template #actions="{ requestClose }">
        <button type="button" class="btn btn-secondary" @click="requestClose">
          Close
        </button>
      </template>
    </HilosModal>

    <HilosModal
      v-model="circleAddOpen"
      :title="HILOS_BACKUP_CIRCLE_COPY.addTitle"
      :close-on-backdrop="!circleAddBusy"
      :close-on-esc="!circleAddBusy"
      @cancel="closeCircleAdd"
    >
      <HilosActionError :action="circleAddAction" />
      <label class="form-label" for="hilos-backup-circle-add-field">
        {{ HILOS_BACKUP_CIRCLE_COPY.addField }}
      </label>
      <input
        id="hilos-backup-circle-add-field"
        v-model="circleAddIdentifier"
        type="text"
        class="form-control"
        autocomplete="off"
        :disabled="circleAddBusy"
        data-id="hilos-backup-circle-add-field"
        data-autofocus
      />
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="circleAddBusy"
          @click="requestClose"
        >
          Cancel
        </button>
        <LoadingButton
          class="btn-primary"
          :loading="circleAddLoading"
          :disabled="circleAddIdentifier.trim() === ''"
          data-id="hilos-backup-circle-add-confirm"
          @click="submitCircleAdd"
        >
          Add
        </LoadingButton>
      </template>
    </HilosModal>

    <HilosModal
      v-model="circleRemoveOpen"
      :title="HILOS_BACKUP_CIRCLE_COPY.removeTitle"
      :close-on-backdrop="!circleRemoveBusy"
      :close-on-esc="!circleRemoveBusy"
      initial-focus="dialog"
      @cancel="closeCircleRemove"
    >
      <HilosActionError :action="circleRemoveAction" />
      <p class="mb-0 text-body-secondary">
        {{ HILOS_BACKUP_CIRCLE_COPY.removeBody }}
      </p>
      <p v-if="circleRemoveRow" class="mb-0 mt-2">
        <code>{{ circleRemoveRow.identifier }}</code>
      </p>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="circleRemoveBusy"
          @click="requestClose"
        >
          Cancel
        </button>
        <LoadingButton
          class="btn-danger"
          :loading="circleRemoveLoading"
          data-id="hilos-backup-circle-remove-confirm"
          @click="submitCircleRemove"
        >
          Remove
        </LoadingButton>
      </template>
    </HilosModal>
  </HilosAdminPage>
</template>
