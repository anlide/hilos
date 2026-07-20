// HilosBackupPage — the framework Hilos backup page (HilosPages.BACKUP): the
// stored-backup list inside the admin shell. Read-only and live — the rows arrive
// over the socket from the backup runtime index plus the single in-progress
// backup, so an in-progress row shows an indeterminate progress bar until it
// completes and merges into the index. All table logic and the row view-model are
// the core headless's (createHilosBackupsTable / HilosBackupRow); this view owns
// only the column set and the cell markup, so a project mounts it by passing its
// HilosBackupsContext. Row actions (create / delete / keep) are a separate page
// (HIL-333). Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
} from '@angular/core'
import { HilosPages, createHilosBackupsTable } from '@hilos/core'
import type {
  HilosBackupRow,
  HilosBackupsContext,
  HilosTableColumn,
} from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'

const COLUMNS: HilosTableColumn[] = [
  { key: 'createdAt', label: 'Date', sortable: true },
  { key: 'env', label: 'Environment', sortable: true },
  { key: 'scope', label: 'Scope', sortable: true },
  { key: 'sizeBytes', label: 'Size', sortable: true, headerClass: 'text-end' },
  {
    key: 'durationSeconds',
    label: 'Duration',
    sortable: true,
    headerClass: 'text-end',
  },
  { key: 'status', label: 'Status', sortable: true },
]

/** The framework backup admin page: the searchable, sortable backup list. */
@Component({
  selector: 'hilos-backup-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosViewportTable],
  template: `
    <hilos-admin-page [page]="page">
      <hilos-viewport-table
        label="Backups"
        [controller]="backups().controller"
        [columns]="columns"
        [searchable]="true"
        searchPlaceholder="Search backups…"
        emptyText="No backups yet."
      >
        <ng-template #row let-row>
          <td class="text-nowrap">
            {{ row.createdAt || '—' }}
            @if (row.keep) {
              <span class="badge text-bg-warning ms-1" title="Pinned out of rotation"
                >keep</span
              >
            }
          </td>
          <td>{{ row.env || '—' }}</td>
          <td><code>{{ row.scope || '—' }}</code></td>
          <td class="text-end">{{ formatSize(row) }}</td>
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
              <span class="badge text-bg-success">{{ row.status || 'success' }}</span>
            } @else {
              <span class="badge text-bg-danger">{{ row.status || 'error' }}</span>
            }
          </td>
        </ng-template>
      </hilos-viewport-table>
    </hilos-admin-page>
  `,
})
export class HilosBackupPage {
  /** The project context: scope stores and the live connection. */
  readonly context = input.required<HilosBackupsContext>()

  protected readonly page = HilosPages.BACKUP
  protected readonly columns = COLUMNS
  protected readonly backups = computed(() =>
    createHilosBackupsTable(this.context()),
  )

  constructor() {
    // Bind the server-windowed table to the connection and request the first
    // window once the context input is bound; unbind on destroy or context swap.
    effect((onCleanup) => {
      const backups = this.backups()
      backups.start()
      onCleanup(() => backups.dispose())
    })
  }

  /** Whether the backup is the single in-progress row (renders a live progress bar). */
  protected isRunning(row: HilosBackupRow): boolean {
    return row.finished === false
  }

  /** Human-readable archive size; an in-progress backup has no size yet. */
  protected formatSize(row: HilosBackupRow): string {
    if (this.isRunning(row) || row.sizeBytes <= 0) {
      return '—'
    }
    const units = ['B', 'KB', 'MB', 'GB', 'TB']
    let size = row.sizeBytes
    let unit = 0
    while (size >= 1024 && unit < units.length - 1) {
      size /= 1024
      unit += 1
    }

    return `${unit === 0 ? size : size.toFixed(1)} ${units[unit]}`
  }

  /** Human-readable capture duration; an in-progress backup has no duration yet. */
  protected formatDuration(row: HilosBackupRow): string {
    if (this.isRunning(row) || row.durationSeconds <= 0) {
      return '—'
    }
    const seconds = row.durationSeconds
    if (seconds < 60) {
      return `${seconds}s`
    }

    return `${Math.floor(seconds / 60)}m ${seconds % 60}s`
  }
}
