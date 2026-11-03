import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
} from '@angular/core'
import {
  CHANGE_LOG_NOISY_COLUMN_FIELD,
  CHANGE_LOG_NOISY_COLUMNS_NOTE,
  CHANGE_LOG_NOISY_REASON_FIELD,
  createHilosChangeLogNoisyColumnsTable,
  type HilosChangeLogContext,
} from '@hilos/core/admin/change-log'
import { HilosPages } from '@hilos/core/base'

import {
  HilosAdminPage,
  HilosTableCell,
  HilosViewportTable,
} from '@hilos/angular/base'

/** The server-windowed noisy-column list. */
@Component({
  selector: 'hilos-change-log-noisy-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosTableCell, HilosViewportTable],
  template: `
    <hilos-admin-page
      [page]="page"
      headKind="section"
      tabsLabel="Change Log pages"
    >
      <p class="text-body-secondary">{{ text.note }}</p>
      <hilos-viewport-table [controller]="columns().controller">
        <ng-template [hilosTableCell]="keys.column" let-row>
          <code class="text-break">{{ row.table }}.{{ row.column }}</code>
        </ng-template>
        <ng-template [hilosTableCell]="keys.reason" let-row>
          <span class="small text-break">{{ row.reason }}</span>
        </ng-template>
      </hilos-viewport-table>
    </hilos-admin-page>
  `,
})
export class HilosChangeLogNoisyPage {
  /** The project's connection and page scope. */
  readonly context = input.required<HilosChangeLogContext>()

  protected readonly page = HilosPages.CHANGE_LOG_NOISY
  protected readonly text = { note: CHANGE_LOG_NOISY_COLUMNS_NOTE } as const
  protected readonly keys = {
    column: CHANGE_LOG_NOISY_COLUMN_FIELD,
    reason: CHANGE_LOG_NOISY_REASON_FIELD,
  } as const
  protected readonly columns = computed(() =>
    createHilosChangeLogNoisyColumnsTable(this.context()),
  )

  constructor() {
    effect((onCleanup) => {
      const columns = this.columns()
      columns.start()
      onCleanup(() => columns.dispose())
    })
  }
}
