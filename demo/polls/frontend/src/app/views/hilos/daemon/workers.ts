import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosDaemonWorkersPage } from '@hilos/angular'

import { hilosDaemonWorkersContext } from './hilosDaemonContext.js'

/** Bind this project's context to the framework Daemon screen. */
@Component({
  selector: 'app-workers',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosDaemonWorkersPage],
  template: `<hilos-daemon-workers-page [context]="context" />`,
})
export class DaemonWorkers {
  protected readonly context = hilosDaemonWorkersContext
}
