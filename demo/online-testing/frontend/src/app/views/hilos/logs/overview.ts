// The Hilos logs page (HilosPages.LOGS): a thin project binding of the framework
// HilosLogsPage to this app's context. The tiles, the takeout banner, the per-node
// table and the two empty states are the framework's; the project supplies the
// connection and scopes, with the node table already registered on its backend.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosLogsPage } from '@hilos/angular/admin/logs'

import { hilosLogsOverviewContext } from './hilosLogsContext.js'

@Component({
  selector: 'app-logs-overview',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLogsPage],
  template: `<hilos-logs-page [context]="context" />`,
})
export class LogsOverview {
  protected readonly context = hilosLogsOverviewContext
}
