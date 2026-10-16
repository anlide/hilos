// Mount the framework Tracked table page (HilosPages.CHANGE_LOG_TABLE) with
// this project's context.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosChangeLogTablePage } from '@hilos/angular'
import { hilosChangeLogContext } from './hilosChangeLogContext.js'
@Component({
  selector: 'app-change-log-table',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosChangeLogTablePage],
  template: `<hilos-change-log-table-page [context]="context" />`,
})
export class ChangeLogTable {
  protected readonly context = hilosChangeLogContext
}
