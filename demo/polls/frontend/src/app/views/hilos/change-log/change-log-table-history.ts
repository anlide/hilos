// Mount the framework Tracked table page (HilosPages.CHANGE_LOG_TABLE_HISTORY) with
// this project's context.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosChangeLogTableHistoryPage } from '@hilos/angular/admin/change-log'
import { hilosChangeLogContext } from './hilosChangeLogContext.js'
@Component({
  selector: 'app-change-log-table-history',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosChangeLogTableHistoryPage],
  template: `<hilos-change-log-table-history-page [context]="context" />`,
})
export class ChangeLogTableHistory {
  protected readonly context = hilosChangeLogContext
}
