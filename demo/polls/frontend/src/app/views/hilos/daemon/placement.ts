import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosDaemonPlacementPage } from '@hilos/angular/admin/daemon'

import { hilosDaemonContext } from './hilosDaemonContext.js'

/** Bind this project's context to the framework Agent placement screen. */
@Component({
  selector: 'app-daemon-placement',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosDaemonPlacementPage],
  template: `<hilos-daemon-placement-page [context]="context" />`,
})
export class DaemonPlacement {
  protected readonly context = hilosDaemonContext
}
