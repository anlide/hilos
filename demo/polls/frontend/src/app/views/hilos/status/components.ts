// Mount the framework Status page with this project's context.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosStatusComponentsPage } from '@hilos/angular/admin/status'

import { hilosStatusContext } from './hilosStatusContext.js'

@Component({
  selector: 'app-status-components',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosStatusComponentsPage],
  template: `<hilos-status-components-page [context]="context" />`,
})
export class StatusComponents {
  protected readonly context = hilosStatusContext
}
