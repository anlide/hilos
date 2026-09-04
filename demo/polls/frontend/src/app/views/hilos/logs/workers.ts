// The Hilos by-worker page (HilosPages.LOGS_WORKERS): a thin project binding of the
// framework HilosLogsWorkersPage to this app's context. The windowed worker table,
// the node and type filters and the monopolistic distinction are the framework's;
// the project supplies only the context and registers the hilosLogWorkers table
// against this page on its backend.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosLogsWorkersPage } from '@hilos/angular'

import { hilosLogWorkersContext } from './hilosLogsContext'

@Component({
  selector: 'app-log-workers',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLogsWorkersPage],
  template: `<hilos-logs-workers-page [context]="context" />`,
})
export class LogWorkers {
  protected readonly context = hilosLogWorkersContext
}
