import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosDaemonCronPage } from '@hilos/angular/admin/daemon'

import { hilosDaemonCronContext } from './hilosDaemonContext.js'

/** Bind this project's context to the framework Daemon screen. */
@Component({
  selector: 'app-cron',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosDaemonCronPage],
  template: `<hilos-daemon-cron-page [context]="context" />`,
})
export class DaemonCron {
  protected readonly context = hilosDaemonCronContext
}
