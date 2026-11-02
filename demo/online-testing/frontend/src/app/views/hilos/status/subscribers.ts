// Mount the framework Status subscribers page with this project's context.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosStatusSubscribersPage } from '@hilos/angular/admin/status'

import { hilosStatusContext } from './hilosStatusContext.js'

@Component({
  selector: 'app-status-subscribers',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosStatusSubscribersPage],
  template: `<hilos-status-subscribers-page [context]="context" />`,
})
export class StatusSubscribers {
  protected readonly context = hilosStatusContext
}
