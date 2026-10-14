import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosDaemonEnvPage } from '@hilos/angular'

import { hilosDaemonEnvContext } from './hilosDaemonContext.js'

/** Bind this project's context to the framework Daemon screen. */
@Component({
  selector: 'app-env',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosDaemonEnvPage],
  template: `<hilos-daemon-env-page [context]="context" />`,
})
export class DaemonEnv {
  protected readonly context = hilosDaemonEnvContext
}
