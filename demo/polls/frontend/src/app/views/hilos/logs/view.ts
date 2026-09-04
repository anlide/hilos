// The Hilos log viewer (HilosPages.LOGS_VIEW): a thin project binding of the
// framework HilosLogsViewPage to this app's context. The three-slot address, the
// read, the live tail and every state of the pane are the framework's; the project
// supplies only the context (the connection and the action lifecycle the lines are
// read over) and owns no table for this page.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosLogsViewPage } from '@hilos/angular'

import { hilosLogViewerContext } from './hilosLogsContext'

@Component({
  selector: 'app-log-viewer',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLogsViewPage],
  template: `<hilos-logs-view-page [context]="context" />`,
})
export class LogViewer {
  protected readonly context = hilosLogViewerContext
}
