// Mount the framework Status history page with this project's context.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosStatusHistoryPage } from '@hilos/angular/admin/status'

import { hilosStatusContext } from './hilosStatusContext.js'

@Component({
  selector: 'app-status-history',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosStatusHistoryPage],
  template: `<hilos-status-history-page [context]="context" />`,
})
export class StatusHistory {
  protected readonly context = hilosStatusContext
}
