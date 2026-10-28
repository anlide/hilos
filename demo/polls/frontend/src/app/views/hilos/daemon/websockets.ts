import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosDaemonWebsocketsPage } from '@hilos/angular/admin/daemon'

import { hilosDaemonContext } from './hilosDaemonContext.js'

/** Bind the project’s existing context to one node’s WebSocket count. */
@Component({
  selector: 'app-daemon-websockets',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosDaemonWebsocketsPage],
  template: `<hilos-daemon-websockets-page [context]="context" />`,
})
export class DaemonWebsockets {
  protected readonly context = hilosDaemonContext
}
