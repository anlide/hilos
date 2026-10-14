import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosDaemonPage } from '@hilos/angular'

import { hilosDaemonContext } from './hilosDaemonContext.js'

/** Bind this project's context to the framework Daemon screen. */
@Component({
  selector: 'app-daemon',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosDaemonPage],
  template: `<hilos-daemon-page [context]="context" />`,
})
export class Daemon {
  protected readonly context = hilosDaemonContext
}
