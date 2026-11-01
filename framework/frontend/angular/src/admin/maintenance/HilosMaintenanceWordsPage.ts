// HilosMaintenanceWordsPage — the Maintenance Words tab inside the admin shell
// (HIL-1682): the words the system announces protected-mode work with, as a
// read-only table on its own address under Maintenance's shared section head.
// Angular counterpart of the Vue Words page (HIL-1677): the table's window is
// bound to Words, the first window arrives with the page's own answer, and an
// operation without an entry of its own says so through the table's merged row
// message. The rows and the words belong to the core headless; a project
// supplies only HilosMaintenanceContext. Bootstrap classes only.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
} from '@angular/core'
import { HilosPages } from '@hilos/core/base'
import {
  createHilosMaintenanceWordsTable,
  MAINTENANCE_WORDS_BANNER_MESSAGE_FIELD,
  MAINTENANCE_WORDS_MESSAGE_FIELD,
  MAINTENANCE_WORDS_OPERATION_FIELD,
  MAINTENANCE_WORDS_TITLE_FIELD,
  type HilosMaintenanceContext,
} from '@hilos/core/admin/maintenance'

import {
  HilosAdminPage,
  HilosTableCell,
  HilosViewportTable,
} from '@hilos/angular/base'

/**
 * The protected-mode words as a standard read-only table: no search, sorting,
 * writing or dialogs.
 */
@Component({
  selector: 'hilos-maintenance-words-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosTableCell, HilosViewportTable],
  template: `
    <hilos-admin-page
      [page]="page"
      headKind="section"
      tabsLabel="Maintenance pages"
    >
      <hilos-viewport-table
        dataId="hilos-maintenance-words-table"
        [controller]="words().controller"
      >
        <ng-template [hilosTableCell]="fields.operation" let-row>
          <span
            [attr.data-id]="'hilos-maintenance-words-row-' + row.operation"
            >{{ row.operation }}</span
          >
        </ng-template>
        <ng-template [hilosTableCell]="fields.title" let-row>
          <span class="text-break">{{ row.title }}</span>
        </ng-template>
        <ng-template [hilosTableCell]="fields.message" let-row>
          <span class="text-break">{{ row.message }}</span>
        </ng-template>
        <ng-template [hilosTableCell]="fields.bannerMessage" let-row>
          <span class="text-break">{{ row.bannerMessage }}</span>
        </ng-template>
      </hilos-viewport-table>
    </hilos-admin-page>
  `,
})
export class HilosMaintenanceWordsPage {
  /** The project context: scope stores, the connection and the action lifecycle. */
  readonly context = input.required<HilosMaintenanceContext>()

  protected readonly page = HilosPages.MAINTENANCE_WORDS
  protected readonly fields = {
    operation: MAINTENANCE_WORDS_OPERATION_FIELD,
    title: MAINTENANCE_WORDS_TITLE_FIELD,
    message: MAINTENANCE_WORDS_MESSAGE_FIELD,
    bannerMessage: MAINTENANCE_WORDS_BANNER_MESSAGE_FIELD,
  } as const

  protected readonly words = computed(() =>
    createHilosMaintenanceWordsTable(
      this.context(),
      HilosPages.MAINTENANCE_WORDS,
    ),
  )

  constructor() {
    // Bind the table to Words — consuming the window of the page's own answer; a
    // context change or leaving the page drops every listener of the table.
    effect((onCleanup) => {
      const words = this.words()
      words.start()
      onCleanup(() => words.dispose())
    })
  }
}
