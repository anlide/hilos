// Mount the framework Tracked table page (HilosPages.CHANGE_LOG_TABLE_COLUMNS) with
// this project's context.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosChangeLogTableColumnsPage } from '@hilos/angular/admin/change-log'
import { hilosChangeLogContext } from './hilosChangeLogContext.js'
@Component({
  selector: 'app-change-log-table-columns',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosChangeLogTableColumnsPage],
  template: `<hilos-change-log-table-columns-page [context]="context" />`,
})
export class ChangeLogTableColumns {
  protected readonly context = hilosChangeLogContext
}
