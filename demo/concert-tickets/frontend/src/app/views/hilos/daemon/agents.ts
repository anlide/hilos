import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosDaemonAgentsPage } from '@hilos/angular/admin/daemon'

import { hilosDaemonAgentsContext } from './hilosDaemonContext.js'

/** Bind this project's context to the framework Daemon screen. */
@Component({
  selector: 'app-agents',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosDaemonAgentsPage],
  template: `<hilos-daemon-agents-page [context]="context" />`,
})
export class DaemonAgents {
  protected readonly context = hilosDaemonAgentsContext
}
