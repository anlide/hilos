import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosDaemonNodePage } from '@hilos/angular/admin/daemon'

import { hilosDaemonContext } from './hilosDaemonContext.js'

/** Bind the project’s existing context to one node’s Overview. */
@Component({
  selector: 'app-daemon-node',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosDaemonNodePage],
  template: `<hilos-daemon-node-page [context]="context" />`,
})
export class DaemonNode {
  protected readonly context = hilosDaemonContext
}
