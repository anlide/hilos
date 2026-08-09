// HilosBackupPage — the framework Hilos backup page (HilosPages.BACKUP): the
// stored-backup list inside the admin shell, with its row actions. The list is
// live — rows arrive over the socket from the backup runtime index plus the
// single in-progress backup, so an in-progress row shows an indeterminate
// progress bar until it completes and merges into the index. Its actions (create
// with a scope picker, per-row delete, per-row keep toggle) are the core
// headless's (createHilosBackupsActions); each dispatches a tracked action and
// surfaces the backend's failure (authoritative-backend). All table logic and the
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
  BACKUP_DURATION_SECONDS_FIELD,
  BACKUP_STATUS_FIELD,
  BACKUP_KEEP_FIELD,
  createHilosBackupsActions,
  createHilosBackupsTable,
  formatBackupDuration,
  formatBackupChecksum,
  formatBackupSize,
  hasBackupFailureDetail,
  isBackupChecksumMismatch,
  isBackupDeletable,
  isBackupInProgress,
  isBackupKeepable,
} from '@hilos/core'
import type {
  HilosBackupRow,
  HilosBackupsContext,
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
          <td class="text-end">{{ formatDuration(row) }}</td>
          <td style="min-width: 10rem">
            @if (isRunning(row)) {
              <div class="progress" role="status">
                <div
                  class="progress-bar progress-bar-striped progress-bar-animated"
                  style="width: 100%"
                >
                  In progress
                </div>
              </div>
            } @else if (row.finished === true) {
              <span class="badge text-bg-success">{{ row.status }}</span>
            } @else {
              <span class="badge text-bg-danger">{{ row.status }}</span>
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

  protected readonly backups = computed(() =>
    createHilosBackupsTable(this.context()),
  )
  private readonly actions = computed(() =>
    createHilosBackupsActions(this.context()),
  )

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

  constructor() {
    // Bind the server-windowed table to the connection and request the first
    // window once the context input is bound; unbind on destroy or context swap.
    effect((onCleanup) => {
      const backups = this.backups()
      backups.start()
      onCleanup(() => backups.dispose())
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

  /** Whether the backup is the single in-progress row (renders a live progress bar). */
  protected isRunning(row: HilosBackupRow): boolean {
    return isBackupInProgress(row)
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
}
