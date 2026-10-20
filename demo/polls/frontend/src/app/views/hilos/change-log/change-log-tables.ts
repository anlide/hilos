// Mount the framework Tracked tables page (HilosPages.CHANGE_LOG_TABLES) with
// this project's context.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosChangeLogTablesPage } from '@hilos/angular/admin/change-log'
import { hilosChangeLogContext } from './hilosChangeLogContext.js'
@Component({
  selector: 'app-change-log-tables',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosChangeLogTablesPage],
  template: `<hilos-change-log-tables-page [context]="context" />`,
})
export class ChangeLogTables {
  protected readonly context = hilosChangeLogContext
}
