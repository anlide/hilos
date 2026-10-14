import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosDaemonHttpServerPage } from '@hilos/angular'

import { hilosDaemonHttpContext } from './hilosDaemonContext.js'

/** Bind this project's context to the framework Daemon screen. */
@Component({
  selector: 'app-http',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosDaemonHttpServerPage],
  template: `<hilos-daemon-http-server-page [context]="context" />`,
})
export class DaemonHttp {
  protected readonly context = hilosDaemonHttpContext
}
