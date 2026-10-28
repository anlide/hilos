import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  CHANGE_LOG_TABLE_COPY,
  CHANGE_LOG_TABLE_SECTION,
  CHANGE_LOG_TABLES_UNTRACKED_NOTE,
  formatChangeLogTableActivity,
  formatChangeLogTableOwner,
  readHilosChangeLogTable,
  type HilosChangeLogReceiptContext,
  type HilosChangeLogTableDetail,
} from '@hilos/core/admin/change-log'
import { HilosPages, hilosTimeZone } from '@hilos/core/base'

import {
  HilosAdminPage,
  hilosSignal,
  mirrorHilosSignal,
} from '@hilos/angular/base'

@Component({
  selector: 'hilos-change-log-table-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage],
  template: ` <hilos-admin-page [page]="page">
    @if (table(); as current) {
      <div
        class="d-flex flex-wrap align-items-center gap-2 border rounded-3 p-3 mb-3"
        data-id="hilos-change-log-table-header"
      >
        <i class="bi bi-table text-body-secondary" aria-hidden="true"></i>
        <code class="fs-6 fw-semibold text-break">{{ current.name }}</code>
        <span class="small text-body-secondary">{{
          owner(current.owner)
        }}</span>
        @if (current.journaled) {
          <span
            class="badge bg-success-subtle text-success-emphasis border border-success-subtle ms-auto"
          >
            <i class="bi bi-journal-check me-1" aria-hidden="true"></i>
            {{ copy.journaled }}
          </span>
          <div class="w-100 small text-body-secondary">
            {{ activity(current) }}
          </div>
        } @else {
          <span class="badge bg-body-secondary text-body border ms-auto">
            {{ text.untracked }}
          </span>
        }
      </div>
      @if (!current.journaled) {
        <p class="alert alert-secondary small py-2">{{ copy.untracked }}</p>
      }
    } @else {
      <p class="text-body-secondary">—</p>
    }
  </hilos-admin-page>`,
})
export class HilosChangeLogTablePage {
  protected readonly page = HilosPages.CHANGE_LOG_TABLE

  /** The project's connection, page scope and tracked actions. */
  readonly context = input.required<HilosChangeLogReceiptContext>()
  protected readonly copy = CHANGE_LOG_TABLE_COPY
  protected readonly text = { untracked: CHANGE_LOG_TABLES_UNTRACKED_NOTE }
  protected readonly owner = formatChangeLogTableOwner
  private readonly timeZone = hilosSignal(hilosTimeZone)
  protected readonly activity = (table: HilosChangeLogTableDetail): string => {
    this.timeZone()
    return formatChangeLogTableActivity(table)
  }
  private readonly pageTable = signal<unknown>(undefined)
  protected readonly table = computed(() =>
    readHilosChangeLogTable(this.pageTable()),
  )
  constructor() {
    effect((onCleanup) => {
      onCleanup(
        mirrorHilosSignal(
          this.context().scopes.pageDataSignal(CHANGE_LOG_TABLE_SECTION),
          this.pageTable,
        ),
      )
    })
  }
}
