import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
} from '@angular/core'
import {
  CHANGE_LOG_COLUMN_MODES,
  CHANGE_LOG_COLUMN_MODES_TITLE,
  CHANGE_LOG_TABLES_CHANGES_FIELD,
  CHANGE_LOG_TABLES_COLUMNS_FIELD,
  CHANGE_LOG_TABLES_LAST_CHANGE_FIELD,
  CHANGE_LOG_TABLES_NAME_FIELD,
  CHANGE_LOG_TABLES_NOTE,
  CHANGE_LOG_TABLES_OPEN_LABEL,
  CHANGE_LOG_TABLES_OWNER_FIELD,
  CHANGE_LOG_TABLES_UNTRACKED_NOTE,
  CHANGE_LOG_TABLE_ROUTE_PARAM,
  createHilosChangeLogTablesTable,
  formatChangeLogColumnCounts,
  formatChangeLogTableChanges,
  formatChangeLogTableLastChange,
  formatChangeLogTableOwner,
  type HilosChangeLogContext,
  type HilosChangeLogTablesRow,
} from '@hilos/core/admin/change-log'
import { HILOS_TABLE_ACTIONS_KEY, HilosPages } from '@hilos/core/base'

import {
  HilosAdminPage,
  HilosLink,
  HilosTableCell,
  HilosViewportTable,
  HILOS_ROUTER,
} from '@hilos/angular/base'

/** The tracked tables viewport and the column-mode reference. */
@Component({
  selector: 'hilos-change-log-tables-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosLink, HilosTableCell, HilosViewportTable],
  template: `
    <hilos-admin-page [page]="page">
      <p class="text-body-secondary">{{ text.note }}</p>

      <hilos-viewport-table [controller]="tables().controller">
        <ng-template [hilosTableCell]="keys.name" let-row>
          <code
            class="text-break"
            [class.text-body-secondary]="!row.journaled"
            >{{ row.name }}</code
          >
          @if (!row.journaled) {
            <div class="small text-body-secondary">{{ text.untracked }}</div>
          }
        </ng-template>
        <ng-template [hilosTableCell]="keys.owner" let-row>
          <span [class.text-body-secondary]="!row.journaled">{{
            owner(row.owner)
          }}</span>
        </ng-template>
        <ng-template [hilosTableCell]="keys.columns" let-row>
          <span [class.text-body-secondary]="!row.journaled">{{
            columnCounts(row.columns)
          }}</span>
        </ng-template>
        <ng-template [hilosTableCell]="keys.changes" let-row>
          <span [class.text-body-secondary]="!row.journaled">{{
            changes(row.changesLast24h)
          }}</span>
        </ng-template>
        <ng-template [hilosTableCell]="keys.lastChange" let-row>
          <span [class.text-body-secondary]="!row.journaled">{{
            lastChange(row.lastChangeAt)
          }}</span>
        </ng-template>
        <ng-template [hilosTableCell]="keys.actions" let-row>
          @let path = tablePath(row);
          @if (path !== undefined) {
            <a
              [hilosLink]="path"
              class="btn btn-sm btn-outline-secondary"
              [attr.data-id]="'hilos-change-log-open-table-' + row.name"
              [attr.aria-label]="text.open + ' ' + row.name"
              >{{ text.open }}</a
            >
          }
        </ng-template>
      </hilos-viewport-table>

      <section class="mt-4" data-id="hilos-change-log-column-modes">
        <h2 class="h5">{{ text.modesTitle }}</h2>
        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3">
          @for (mode of modes; track mode.key) {
            <div class="col">
              <div class="border rounded-3 p-3 h-100">
                <span [class]="'badge text-wrap mb-2 ' + mode.tone">
                  <i
                    [class]="'bi ' + mode.icon + ' me-1'"
                    aria-hidden="true"
                  ></i>
                  {{ mode.label }}
                </span>
                <p class="small text-body-secondary mb-0">
                  {{ mode.description }}
                </p>
              </div>
            </div>
          }
        </div>
      </section>
    </hilos-admin-page>
  `,
})
export class HilosChangeLogTablesPage {
  /** The project's connection and page scope. */
  readonly context = input.required<HilosChangeLogContext>()

  protected readonly page = HilosPages.CHANGE_LOG_TABLES
  protected readonly text = {
    note: CHANGE_LOG_TABLES_NOTE,
    untracked: CHANGE_LOG_TABLES_UNTRACKED_NOTE,
    open: CHANGE_LOG_TABLES_OPEN_LABEL,
    modesTitle: CHANGE_LOG_COLUMN_MODES_TITLE,
  } as const
  protected readonly keys = {
    name: CHANGE_LOG_TABLES_NAME_FIELD,
    owner: CHANGE_LOG_TABLES_OWNER_FIELD,
    columns: CHANGE_LOG_TABLES_COLUMNS_FIELD,
    changes: CHANGE_LOG_TABLES_CHANGES_FIELD,
    lastChange: CHANGE_LOG_TABLES_LAST_CHANGE_FIELD,
    actions: HILOS_TABLE_ACTIONS_KEY,
  } as const
  protected readonly modes = CHANGE_LOG_COLUMN_MODES
  protected readonly owner = formatChangeLogTableOwner
  protected readonly columnCounts = formatChangeLogColumnCounts
  protected readonly changes = formatChangeLogTableChanges
  protected readonly lastChange = formatChangeLogTableLastChange

  // The navigator the Open links resolve through; mounted without one, a table
  // offers no Open, as it does in Vue.
  private readonly router = inject(HILOS_ROUTER, { optional: true })

  protected readonly tables = computed(() =>
    createHilosChangeLogTablesTable(this.context()),
  )

  constructor() {
    // Bind the tables window once the context binds, and release it on a new
    // context or when the page is destroyed.
    effect((onCleanup) => {
      const tables = this.tables()
      tables.start()
      onCleanup(() => tables.dispose())
    })
  }

  /**
   * Address the Tracked table page of a journaled table, once the router gives
   * that page an address; resolved once for each drawn actions cell.
   *
   * @param row The tables row whose actions cell is drawn.
   */
  protected tablePath(row: HilosChangeLogTablesRow): string | undefined {
    return row.journaled
      ? this.router?.resolvePath(HilosPages.CHANGE_LOG_TABLE, {
          [CHANGE_LOG_TABLE_ROUTE_PARAM]: row.name,
        })
      : undefined
  }
}
