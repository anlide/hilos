import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosDaemonEnvMismatchPage } from '@hilos/angular'

import { hilosDaemonEnvMismatchContext } from './hilosDaemonContext.js'

/** Bind this project's context to the framework Daemon screen. */
@Component({
  selector: 'app-env-mismatch',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosDaemonEnvMismatchPage],
  template: `<hilos-daemon-env-mismatch-page [context]="context" />`,
})
export class DaemonEnvMismatch {
  protected readonly context = hilosDaemonEnvMismatchContext
}
