// The Hilos rotation-history page (HilosPages.LOGS_ROTATIONS): a thin project
// binding of the framework HilosLogsRotationsPage to this app's context. The rule
// panel, the windowed batch table and the carry-off dialog with its confirmation are
// the framework's; the project supplies only the context (this one carries the action
// lifecycle too) and registers the hilosLogRotations table against this page on its
// backend.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosLogsRotationsPage } from '@hilos/angular'

import { hilosLogsContext } from './hilosLogsContext'

@Component({
  selector: 'app-log-rotations',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLogsRotationsPage],
  template: `<hilos-logs-rotations-page [context]="context" />`,
})
export class LogRotations {
  protected readonly context = hilosLogsContext
}
