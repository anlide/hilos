// HilosBackupPage — the framework Hilos backup page (HilosPages.BACKUP): the
// stored-backup list inside the admin shell, with its row actions. The list is
// live — rows arrive over the socket from the backup runtime index plus the
// single in-progress backup, so an in-progress row shows a live progress bar until
// it completes and merges into the index. The bar is drawn from the phase anchors
// the row carries and a page-wide one-second clock, and falls back to the
// indeterminate striped bar on a run the backend cannot estimate. Its actions (create
// with a scope picker, per-row delete, per-row keep toggle, per-row restore) are
// the core headless's (createHilosBackupsActions); each dispatches a tracked action
// and surfaces the backend's failure (authoritative-backend). Restore is the
// destructive one: it is offered as a button only where the backend says so
// (everywhere but production), it confirms by typing the archive id, and while it
// runs the addressed progress frames are the only live thing on the page — the node
// is frozen and the table sends nothing. All table logic and the
// row view-model are the core headless's too; this view owns only the markup, so a
// project mounts it by passing its HilosBackupsContext. Bootstrap classes only
// (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  HILOS_BACKUP_SCOPES,
  HilosPages,
  BACKUP_CREATED_AT_FIELD,
  BACKUP_ENV_FIELD,
  BACKUP_SCOPE_FIELD,
  BACKUP_SIZE_BYTES_FIELD,
  BACKUP_CHECKSUM_STATE_FIELD,
  BACKUP_SHIP_STATE_FIELD,
  BACKUP_DURATION_SECONDS_FIELD,
  BACKUP_STATUS_FIELD,
  BACKUP_RESTORE_OUTCOME_FIELD,
  BACKUP_KEEP_FIELD,
  backupMigrationBehind,
  backupMigrationNotes,
  backupProgressPercent,
  backupRowAnchors,
  createBackupProgressClock,
  createHilosBackupsActions,
  createHilosBackupsRestoreGate,
  createHilosBackupsTable,
  createHilosRestoreProgress,
  formatBackupDuration,
  formatBackupChecksum,
  formatBackupShipping,
  formatBackupProgressLabel,
  formatBackupSize,
  formatRestoreCliCommand,
  hasBackupFailureDetail,
  hasRestoreOutcome,
  isBackupChecksumMismatch,
  isBackupShipFailed,
  isBackupDeletable,
  isBackupInProgress,
  isBackupKeepable,
  isBackupMigrationRefused,
  isBackupRestorable,
  isBackupSubsystemBusy,
  offersBackupRestore,
  subscribeSignal,
} from '@hilos/core'
import type {
  HilosBackupRestoreGate,
  HilosBackupRow,
  HilosBackupsContext,
  HilosRestoreStatus,
  HilosTableColumnOf,
} from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { createHilosTrackedAction } from '../../hilosTrackedAction.js'

const COLUMNS: HilosTableColumnOf<HilosBackupRow>[] = [
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

/** The framework backup admin page: the searchable list with create / delete / keep. */
@Component({
  selector: 'hilos-backup-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosViewportTable, HilosModal, LoadingButton],
  template: `
    <hilos-admin-page [page]="page">
      <div class="d-flex flex-wrap align-items-end gap-2 mb-3">
        <div>
          <label class="form-label" for="hilos-backup-create-scope"
            >Scope</label
          >
          <select
            id="hilos-backup-create-scope"
            class="form-select"
            [disabled]="create.busy()"
            data-id="hilos-backup-create-scope"
            [value]="createScope()"
            (change)="onScope($event)"
          >
            @for (scope of scopes; track scope.value) {
              <option [value]="scope.value">{{ scope.label }}</option>
            }
          </select>
        </div>
        <button
          hilosLoadingButton
          class="btn-primary"
          [loading]="create.loading()"
          [disabled]="create.busy()"
          data-id="hilos-backup-create"
          (click)="submitCreate()"
        >
          Create backup
        </button>
      </div>

      @if (restoreStatus(); as status) {
        <div
          class="alert"
          [class.alert-danger]="status.outcome === 'error'"
          [class.alert-success]="status.outcome === 'success'"
          [class.alert-info]="status.outcome === null"
          role="status"
          data-id="hilos-backup-restore-phase"
        >
          <div class="fw-semibold">
            Restore {{ status.backupId }} · {{ status.phase }}
          </div>
          @if (status.outcome === 'success') {
            <div class="small">
              Restored. This page reloads itself as soon as the system reopens.
            </div>
          } @else if (status.outcome === 'error') {
            <div class="small">
              <div>{{ status.failureReason }}</div>
              @if (status.databaseTouched) {
                <div>
                  The database was already being replaced when this failed.
                </div>
              }
              @if (!status.rehydrateComplete) {
                <div>
                  The system stays closed: not every process re-read the
                  replaced database. Reopen it from the CLI with
                  <code>php cli.php protected-mode:open</code>.
                </div>
              }
            </div>
          } @else {
            <div
              class="progress mt-2"
              role="progressbar"
              aria-label="Restore progress"
              aria-valuemin="0"
              aria-valuemax="100"
              [attr.aria-valuenow]="restorePercent(status)"
              data-id="hilos-backup-progress-bar"
            >
              <div
                [class]="progressBarClass(restorePercent(status))"
                [style.width.%]="restorePercent(status) ?? 100"
              ></div>
            </div>
            <div class="small" data-id="hilos-backup-progress-label">
              {{ restoreProgressLabel(status) }}
            </div>
          }
        </div>
      }

      <hilos-viewport-table
        label="Backups"
        [controller]="backups().controller"
        [columns]="columns"
        [searchable]="true"
        searchPlaceholder="Search backups…"
        emptyText="No backups yet."
      >
        <ng-template #row let-row>
          <td class="text-nowrap">{{ row.createdAt || '—' }}</td>
          <td>{{ row.env || '—' }}</td>
          <td>
            <code>{{ row.scope || '—' }}</code>
          </td>
          <td class="text-end">{{ formatSize(row) }}</td>
          <td class="text-nowrap">
            <span [class]="checksumClass(row)">{{ formatChecksum(row) }}</span>
          </td>
          <td class="text-nowrap">
            <span [class]="shippingClass(row)" [title]="row.shipError ?? ''">{{
              formatShipping(row)
            }}</span>
          </td>
          <td class="text-end">{{ formatDuration(row) }}</td>
          <td style="min-width: 10rem">
            @if (isRunning(row)) {
              <div
                class="progress"
                role="progressbar"
                aria-label="Backup progress"
                aria-valuemin="0"
                aria-valuemax="100"
                [attr.aria-valuenow]="rowPercent(row)"
                data-id="hilos-backup-progress-bar"
              >
                <div
                  [class]="progressBarClass(rowPercent(row))"
                  [style.width.%]="rowPercent(row) ?? 100"
                ></div>
              </div>
              <div class="small" data-id="hilos-backup-progress-label">
                {{ rowProgressLabel(row) }}
              </div>
            } @else if (row.finished === true) {
              <span class="badge text-bg-success">{{ row.status }}</span>
            } @else {
              <span class="badge text-bg-danger">{{ row.status }}</span>
            }
          </td>
          <td class="text-nowrap">
            @if (hasRestoreOutcome(row)) {
              <button
                type="button"
                class="btn btn-sm p-0 border-0 bg-transparent"
                [attr.title]="'Show how the restore of ' + row.id + ' ended'"
                [attr.data-id]="'hilos-backup-restore-outcome-' + row.id"
                (click)="openOutcome(row)"
              >
                <span
                  class="badge"
                  [class.text-bg-success]="row.restoreOutcome === 'success'"
                  [class.text-bg-danger]="row.restoreOutcome !== 'success'"
                  >{{ row.restoreOutcome }}</span
                >
              </button>
            } @else if (row.restorePhase) {
              <span class="badge text-bg-info">{{ row.restorePhase }}</span>
            } @else if (isMigrationRefused(row)) {
              <!-- What happened to this archive outranks what could: the badge
              speaks only where no restore of it has anything to report. -->
              <span
                class="badge text-bg-danger"
                [attr.data-id]="'hilos-backup-migration-' + row.id"
                >incompatible</span
              >
            } @else if (migrationBehind(row) !== null) {
              <span
                class="badge text-bg-warning"
                [attr.data-id]="'hilos-backup-migration-' + row.id"
                >+{{ migrationBehind(row) }} migrations</span
              >
            } @else {
              <span class="text-body-secondary">—</span>
            }
          </td>
          <td class="text-center">
            @if (isKeepable(row)) {
              <div class="form-check form-switch d-inline-block m-0">
                <input
                  type="checkbox"
                  class="form-check-input"
                  role="switch"
                  [checked]="row.keep"
                  [disabled]="keep.busy() && keepPendingId() === row.id"
                  [attr.aria-label]="
                    row.keep ? 'Unpin from rotation' : 'Pin out of rotation'
                  "
                  [attr.title]="
                    row.keep ? 'Pinned out of rotation' : 'Pin out of rotation'
                  "
                  [attr.data-id]="'hilos-backup-keep-' + row.id"
                  (change)="toggleKeep(row)"
                />
              </div>
            } @else {
              <span class="text-body-secondary">—</span>
            }
          </td>
          <td class="text-end">
            @if (hasFailureDetail(row)) {
              <button
                type="button"
                class="btn btn-sm btn-outline-secondary me-1"
                title="Show failure reason"
                aria-label="Show failure reason"
                [attr.data-id]="'hilos-backup-details-' + row.id"
                (click)="openDetails(row)"
              >
                <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
              </button>
            }
            @if (offersRestore(row)) {
              @if (restoreGate().uiEnabled) {
                <button
                  type="button"
                  class="btn btn-sm btn-outline-warning me-1"
                  [disabled]="restoreBlockedReason(row) !== null"
                  [attr.title]="
                    restoreBlockedReason(row) ?? 'Restore this backup'
                  "
                  aria-label="Restore this backup"
                  [attr.data-id]="'hilos-backup-restore-' + row.id"
                  (click)="openRestore(row)"
                >
                  <i
                    class="bi bi-arrow-counterclockwise"
                    aria-hidden="true"
                  ></i>
                </button>
              } @else {
                <button
                  type="button"
                  class="btn btn-sm btn-outline-secondary me-1"
                  title="How to restore this backup"
                  aria-label="How to restore this backup"
                  [attr.data-id]="'hilos-backup-restore-cli-' + row.id"
                  (click)="openCli(row)"
                >
                  <i class="bi bi-terminal" aria-hidden="true"></i>
                </button>
              }
            }
            @if (isDeletable(row)) {
              <button
                type="button"
                class="btn btn-sm btn-outline-danger"
                title="Delete backup"
                aria-label="Delete backup"
                [attr.data-id]="'hilos-backup-delete-' + row.id"
                (click)="openDelete(row)"
              >
                <i class="bi bi-trash" aria-hidden="true"></i>
              </button>
            }
          </td>
        </ng-template>
      </hilos-viewport-table>

      <hilos-modal
        [open]="deleteOpen()"
        (openChange)="deleteOpen.set($event)"
        [title]="deleteTitle()"
        [closeOnBackdrop]="!del.busy()"
        [closeOnEsc]="!del.busy()"
      >
        <p class="mb-0 text-body-secondary">
          This permanently deletes the backup archive and its metadata. A pinned
          backup is deleted too — the pin only protects it from rotation.
        </p>
        @if (deleteRow(); as row) {
          <p class="mb-0 mt-2">
            <code>{{ row.id }}</code>
          </p>
        }
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="del.busy()"
            (click)="requestClose()"
          >
            Cancel
          </button>
          <button
            hilosLoadingButton
            class="btn-danger"
            [loading]="del.loading()"
            data-id="hilos-backup-delete-confirm"
            (click)="submitDelete()"
          >
            Delete
          </button>
        </ng-template>
      </hilos-modal>

      <hilos-modal
        [open]="detailsOpen()"
        (openChange)="detailsOpen.set($event)"
        [title]="detailsTitle()"
      >
        <pre
          class="mb-0 small text-break"
          style="max-height: 60vh; overflow-y: auto"
          data-id="hilos-backup-details-text"
          >{{ detailsRow()?.failureReason }}</pre
        >
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            data-id="hilos-backup-details-close"
            (click)="requestClose()"
          >
            Close
          </button>
        </ng-template>
      </hilos-modal>

      <hilos-modal
        [open]="restoreOpen()"
        (openChange)="restoreOpen.set($event)"
        [title]="restoreTitle()"
        [closeOnBackdrop]="!restore.busy()"
        [closeOnEsc]="!restore.busy()"
      >
        <p class="mb-2">
          This overwrites every database of this installation with the contents
          of the archive. Everyone else is shown a maintenance screen until it
          ends, and if the system does not come back on its own it is reopened
          from the CLI.
        </p>
        @if (restoreRow(); as row) {
          <p class="mb-2 text-body-secondary">
            Archive taken in
            <code>{{ row.env || 'an unnamed environment' }}</code> → this
            installation is
            <code>{{ restoreGate().targetEnv || 'unnamed' }}</code>
          </p>
          @if (migrationNotes(row).length > 0) {
            <ul
              class="mb-2 ps-3 text-body-secondary"
              data-id="hilos-backup-migration-notes"
            >
              @for (note of migrationNotes(row); track note) {
                <li>{{ note }}</li>
              }
            </ul>
          }
        }
        <label class="form-label" for="hilos-backup-restore-id"
          >Type the archive id to confirm</label
        >
        <input
          id="hilos-backup-restore-id"
          type="text"
          class="form-control"
          autocomplete="off"
          [disabled]="restore.busy()"
          [attr.placeholder]="restoreRow()?.id"
          data-id="hilos-backup-restore-id"
          [value]="restoreTyped()"
          (input)="onRestoreTyped($event)"
        />
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="restore.busy()"
            (click)="requestClose()"
          >
            Cancel
          </button>
          <button
            hilosLoadingButton
            class="btn-warning"
            [loading]="restore.loading()"
            [disabled]="!restoreConfirmed()"
            data-id="hilos-backup-restore-confirm"
            (click)="submitRestore()"
          >
            Restore
          </button>
        </ng-template>
      </hilos-modal>

      <hilos-modal
        [open]="cliOpen()"
        (openChange)="cliOpen.set($event)"
        [title]="cliTitle()"
      >
        <p class="mb-2 text-body-secondary">
          Restoring is not offered from the browser on this environment. Run
          this on the machine that hosts the installation:
        </p>
        <pre
          class="mb-0 small text-break"
          data-id="hilos-backup-restore-cli-text"
          >{{ cliCommand() }}</pre
        >
        <!-- The same lines the button's title carries where there is a button: an
        operator on production learns of an incompatible archive here, not from the
        command refusing after they have walked to the terminal. -->
        @if (cliRow(); as row) {
          @if (migrationNotes(row).length > 0) {
            <ul
              class="mt-2 mb-0 ps-3 text-body-secondary"
              data-id="hilos-backup-migration-cli-notes"
            >
              @for (note of migrationNotes(row); track note) {
                <li>{{ note }}</li>
              }
            </ul>
          }
        }
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            data-id="hilos-backup-restore-cli-copy"
            (click)="copyCliCommand()"
          >
            {{ cliCopied() ? 'Copied' : 'Copy' }}
          </button>
          <button
            type="button"
            class="btn btn-primary"
            (click)="requestClose()"
          >
            Close
          </button>
        </ng-template>
      </hilos-modal>

      <hilos-modal
        [open]="outcomeOpen()"
        (openChange)="outcomeOpen.set($event)"
        [title]="outcomeTitle()"
      >
        <p class="mb-2">
          Finished {{ outcomeRow()?.restoreFinishedAt || '—' }} ·
          <span class="fw-semibold">{{ outcomeRow()?.restoreOutcome }}</span>
        </p>
        @if (outcomeRow()?.restoreDatabaseTouched) {
          <p class="mb-2">
            The database was already being replaced when this run ended.
          </p>
        }
        <pre
          class="mb-0 small text-break"
          style="max-height: 60vh; overflow-y: auto"
          data-id="hilos-backup-restore-outcome-text"
          >{{
            outcomeRow()?.restoreFailureReason || 'No failure recorded.'
          }}</pre
        >
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            (click)="requestClose()"
          >
            Close
          </button>
        </ng-template>
      </hilos-modal>
    </hilos-admin-page>
  `,
})
export class HilosBackupPage {
  /** The project context: scope stores, the connection, and the action lifecycle. */
  readonly context = input.required<HilosBackupsContext>()

  protected readonly page = HilosPages.BACKUP
  protected readonly columns = COLUMNS
  protected readonly scopes = HILOS_BACKUP_SCOPES
  protected readonly isKeepable = isBackupKeepable
  protected readonly isDeletable = isBackupDeletable
  protected readonly hasFailureDetail = hasBackupFailureDetail
  protected readonly hasRestoreOutcome = hasRestoreOutcome
  protected readonly offersRestore = offersBackupRestore

  protected readonly backups = computed(() =>
    createHilosBackupsTable(this.context()),
  )
  private readonly actions = computed(() =>
    createHilosBackupsActions(this.context()),
  )
  private readonly restoreProgress = computed(() =>
    createHilosRestoreProgress(this.context().connection),
  )

  // One ticker for the whole page: a percentage moves with wall time, while the socket
  // only speaks on a change of phase, so every bar here redraws from this signal.
  protected readonly progressNow = signal(Date.now())

  // Create toolbar: pick a scope and start a backup as a tracked action.
  protected readonly createScope = signal(HILOS_BACKUP_SCOPES[0].value)
  protected readonly create = createHilosTrackedAction()

  // Keep toggle: a per-row switch dispatched as a tracked action; the row stays
  // authoritative (the switch reflects the live row's keep, never an optimistic flip).
  protected readonly keep = createHilosTrackedAction()
  protected readonly keepPendingId = signal<string | null>(null)

  // Delete dialog: a completed backup only (never the in-progress row).
  protected readonly deleteOpen = signal(false)
  protected readonly deleteRow = signal<HilosBackupRow | null>(null)
  protected readonly del = createHilosTrackedAction()

  protected readonly deleteTitle = computed(() => {
    const row = this.deleteRow()

    return row ? `Delete · ${row.id}` : 'Delete backup'
  })

  // Failure-detail dialog: a read-only view of a failed backup's stored reason. It
  // holds a snapshot of the row it opened on, so a parallel delete or rotation does
  // not close it; the text is already in the row, so there is no server request.
  protected readonly detailsOpen = signal(false)
  protected readonly detailsRow = signal<HilosBackupRow | null>(null)
  protected readonly detailsTitle = computed(() => {
    const row = this.detailsRow()

    return row ? `Backup failed · ${row.id}` : 'Backup failed'
  })

  // Restore dialog: the destructive one. Confirmation is typing the archive's id —
  // the one barrier muscle memory cannot pass, and the one that makes the operator
  // read WHICH archive they picked, since the likely mistake here is restoring the
  // wrong one rather than clicking the wrong button.
  protected readonly restoreOpen = signal(false)
  protected readonly restoreRow = signal<HilosBackupRow | null>(null)
  protected readonly restoreTyped = signal('')
  protected readonly restore = createHilosTrackedAction()
  protected readonly restoreTitle = computed(() => {
    const row = this.restoreRow()

    return row ? `Restore · ${row.id}` : 'Restore backup'
  })
  protected readonly restoreConfirmed = computed(() => {
    const row = this.restoreRow()

    return row !== null && this.restoreTyped() === row.id
  })

  // CLI instruction dialog: what the production surface offers instead of a button.
  protected readonly cliOpen = signal(false)
  protected readonly cliRow = signal<HilosBackupRow | null>(null)
  protected readonly cliCopied = signal(false)
  protected readonly cliTitle = computed(() => {
    const row = this.cliRow()

    return row ? `How to restore · ${row.id}` : 'How to restore'
  })
  protected readonly cliCommand = computed(() => {
    const row = this.cliRow()

    return row === null ? '' : formatRestoreCliCommand(row)
  })

  // Restore-outcome dialog: how the last restore of this archive ended, read from
  // the row, so it survives the reload the successful path ends with.
  protected readonly outcomeOpen = signal(false)
  protected readonly outcomeRow = signal<HilosBackupRow | null>(null)
  protected readonly outcomeTitle = computed(() => {
    const row = this.outcomeRow()

    return row ? `Restore · ${row.id}` : 'Restore of this backup'
  })

  // Mirrored from the core selectors, which derive from the context input: what this
  // installation offers for restoring, the addressed frames a restore this tab started
  // sends back while the node is frozen, and the rows the busy check reads.
  protected readonly restoreGate = signal<HilosBackupRestoreGate>({
    uiEnabled: false,
    targetEnv: null,
  })
  protected readonly restoreStatus = signal<HilosRestoreStatus | null>(null)
  private readonly rows = signal<readonly (HilosBackupRow | null)[]>([])
  protected readonly subsystemBusy = computed(() =>
    isBackupSubsystemBusy(this.rows(), this.restoreStatus()),
  )

  constructor() {
    // Bind the server-windowed table to the connection and request the first
    // window once the context input is bound; unbind on destroy or context swap.
    effect((onCleanup) => {
      const backups = this.backups()
      backups.start()
      onCleanup(() => backups.dispose())
    })
    // The progress clock belongs to the page rather than to the context: it ticks off
    // wall time, not off anything that arrives over a connection, so it is built once
    // and torn down with the component.
    effect((onCleanup) => {
      const clock = createBackupProgressClock()
      const unsubscribe = subscribeSignal(clock.now, (value) =>
        this.progressNow.set(value),
      )
      onCleanup(() => {
        unsubscribe()
        clock.dispose()
      })
    })
    // The context arrives via input and carries core signals; build the restore
    // selectors once it binds, mirror them into Angular, and drop the subscriptions
    // if the context is replaced. The frames are addressed to this connection and
    // start arriving the moment it asks for a run.
    effect((onCleanup) => {
      const gate = createHilosBackupsRestoreGate(this.context())
      const progress = this.restoreProgress()
      const windowRows = this.backups().controller.rows
      progress.start()
      this.restoreGate.set(gate.get())
      this.rows.set(windowRows.get().map((entry) => entry.row))
      const subscriptions = [
        subscribeSignal(gate, (value) => this.restoreGate.set(value)),
        subscribeSignal(progress.status, (value) =>
          this.restoreStatus.set(value),
        ),
        subscribeSignal(windowRows, (value) =>
          this.rows.set(value.map((entry) => entry.row)),
        ),
      ]
      onCleanup(() => {
        for (const unsubscribe of subscriptions) {
          unsubscribe()
        }
        progress.dispose()
      })
    })
  }

  protected onScope(event: Event): void {
    this.createScope.set((event.target as HTMLSelectElement).value)
  }

  protected async submitCreate(): Promise<void> {
    if (this.create.busy()) {
      return
    }
    await this.create.run(this.actions().sendBackupCreate(this.createScope()))
  }

  protected async toggleKeep(row: HilosBackupRow): Promise<void> {
    if (this.keep.busy()) {
      return
    }
    this.keepPendingId.set(row.id)
    await this.keep.run(this.actions().sendBackupSetKeep(row.id, !row.keep))
    this.keepPendingId.set(null)
  }

  protected openDelete(row: HilosBackupRow): void {
    this.del.clearError()
    this.deleteRow.set(row)
    this.deleteOpen.set(true)
  }

  protected openDetails(row: HilosBackupRow): void {
    this.detailsRow.set(row)
    this.detailsOpen.set(true)
  }

  // Authoritative-backend: dispatch the tracked action, close on its `::success`
  // reply; a failure stays open with the reason shown.
  protected async submitDelete(): Promise<void> {
    const row = this.deleteRow()
    if (!row || this.del.busy()) {
      return
    }
    if (await this.del.run(this.actions().sendBackupDelete(row.id))) {
      this.deleteOpen.set(false)
    }
  }

  protected onRestoreTyped(event: Event): void {
    this.restoreTyped.set((event.target as HTMLInputElement).value)
  }

  protected openRestore(row: HilosBackupRow): void {
    this.restore.clearError()
    this.restoreRow.set(row)
    this.restoreTyped.set('')
    this.restoreOpen.set(true)
  }

  protected openCli(row: HilosBackupRow): void {
    this.cliRow.set(row)
    this.cliCopied.set(false)
    this.cliOpen.set(true)
  }

  protected openOutcome(row: HilosBackupRow): void {
    this.outcomeRow.set(row)
    this.outcomeOpen.set(true)
  }

  protected async copyCliCommand(): Promise<void> {
    await navigator.clipboard.writeText(this.cliCommand())
    this.cliCopied.set(true)
  }

  // Authoritative-backend: dispatch the tracked action, close on its `::success`
  // reply; a failure stays open with the reason shown.
  protected async submitRestore(): Promise<void> {
    const row = this.restoreRow()
    if (!row || this.restore.busy() || !this.restoreConfirmed()) {
      return
    }
    if (await this.restore.run(this.actions().sendBackupRestore(row.id))) {
      this.restoreOpen.set(false)
    }
  }

  /**
   * Why an archive cannot be restored right now, or null when it can. The button
   * stays visible and carries this as its title, so the answer arrives before the
   * click rather than as a toast after it.
   *
   * @param row The backup row the button belongs to.
   */
  protected restoreBlockedReason(row: HilosBackupRow): string | null {
    if (isBackupChecksumMismatch(row)) {
      return 'This archive does not match its recorded checksum'
    }
    // What makes the archive unusable forever comes before what the subsystem is
    // doing right now: waiting for the current run would not make this one restorable.
    if (this.isMigrationRefused(row)) {
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

    return this.subsystemBusy()
      ? 'The backup subsystem is busy; wait for the current run to end'
      : null
  }

  /** Whether the backup is the single in-progress row (renders a live progress bar). */
  protected isRunning(row: HilosBackupRow): boolean {
    return isBackupInProgress(row)
  }

  /** Whether this archive was taken on newer code and can never be replayed here. */
  protected isMigrationRefused(row: HilosBackupRow): boolean {
    return isBackupMigrationRefused(row)
  }

  /** How many migrations this archive's restore applies afterwards, or null when none. */
  protected migrationBehind(row: HilosBackupRow): number | null {
    return backupMigrationBehind(row)
  }

  /** This archive's per-connection migration lines, one per rendered row. */
  protected migrationNotes(row: HilosBackupRow): readonly string[] {
    return backupMigrationNotes(row)
  }

  /**
   * How far along the run of this row is, or null when it cannot be told — an
   * installation with no history to estimate from, or a phase this build does not know.
   *
   * @param row The backup row being rendered.
   */
  protected rowPercent(row: HilosBackupRow): number | null {
    return backupProgressPercent(backupRowAnchors(row), this.progressNow())
  }

  /**
   * The caption under this row's bar: the phase, the percentage, and the time left.
   *
   * @param row The backup row being rendered.
   */
  protected rowProgressLabel(row: HilosBackupRow): string {
    return formatBackupProgressLabel(backupRowAnchors(row), this.progressNow())
  }

  /**
   * How far along the restore this tab is watching is, or null when it cannot be told.
   *
   * @param status The latest restore frame this connection received.
   */
  protected restorePercent(status: HilosRestoreStatus): number | null {
    return backupProgressPercent(status, this.progressNow())
  }

  /**
   * The caption under the restore panel's bar.
   *
   * @param status The latest restore frame this connection received.
   */
  protected restoreProgressLabel(status: HilosRestoreStatus): string {
    return formatBackupProgressLabel(status, this.progressNow())
  }

  /**
   * The classes of the bar itself: the striped, animated one while the run cannot be
   * estimated, and a plain determinate bar once it can.
   *
   * @param percent How far along the run is, or null when it cannot be told.
   */
  protected progressBarClass(percent: number | null): string {
    return percent === null
      ? 'progress-bar progress-bar-striped progress-bar-animated'
      : 'progress-bar'
  }

  /** Human-readable archive size, shared with the other view layers. */
  protected formatSize(row: HilosBackupRow): string {
    return formatBackupSize(row)
  }

  /** Human-readable capture duration, shared with the other view layers. */
  protected formatDuration(row: HilosBackupRow): string {
    return formatBackupDuration(row)
  }

  /** The checksum cell text, shared with the other view layers. */
  protected formatChecksum(row: HilosBackupRow): string {
    return formatBackupChecksum(row)
  }

  /** Red only for an archive that did not match its recorded checksum. */
  protected checksumClass(row: HilosBackupRow): string {
    return isBackupChecksumMismatch(row) ? 'text-danger fw-semibold' : ''
  }

  /** The copy cell text, shared with the other view layers. */
  protected formatShipping(row: HilosBackupRow): string {
    return formatBackupShipping(row)
  }

  /** Red only for an archive whose last copy off the machine did not make it. */
  protected shippingClass(row: HilosBackupRow): string {
    return isBackupShipFailed(row) ? 'text-danger fw-semibold' : ''
  }
}
