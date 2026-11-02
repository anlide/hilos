// Mount the framework Status page with this project's context.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosStatusPage } from '@hilos/angular/admin/status'

import { hilosStatusContext } from './hilosStatusContext.js'

@Component({
  selector: 'app-status',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosStatusPage],
  template: `<hilos-status-page [context]="context" />`,
})
export class Status {
  protected readonly context = hilosStatusContext
}
