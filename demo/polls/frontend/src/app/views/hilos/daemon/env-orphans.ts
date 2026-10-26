import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosDaemonEnvOrphansPage } from '@hilos/angular/admin/daemon'

import { hilosDaemonEnvContext } from './hilosDaemonContext.js'

/** Bind this project's context to the framework Daemon orphans screen. */
@Component({
  selector: 'app-env-orphans',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosDaemonEnvOrphansPage],
  template: `<hilos-daemon-env-orphans-page [context]="context" />`,
})
export class DaemonEnvOrphans {
  protected readonly context = hilosDaemonEnvContext
}
