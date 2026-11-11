// Mount the framework Tracked table page (HilosPages.CHANGE_LOG_TABLE_TRIGGERS) with
// this project's context.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosChangeLogTableTriggersPage } from '@hilos/angular/admin/change-log'
import { hilosChangeLogContext } from './hilosChangeLogContext.js'
@Component({
  selector: 'app-change-log-table-triggers',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosChangeLogTableTriggersPage],
  template: `<hilos-change-log-table-triggers-page [context]="context" />`,
})
export class ChangeLogTableTriggers {
  protected readonly context = hilosChangeLogContext
}
