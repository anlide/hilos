// Mount the framework Status works page with this project's context.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosStatusWorksPage } from '@hilos/angular/admin/status'

import { hilosStatusContext } from './hilosStatusContext.js'

@Component({
  selector: 'app-status-works',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosStatusWorksPage],
  template: `<hilos-status-works-page [context]="context" />`,
})
export class StatusWorks {
  protected readonly context = hilosStatusContext
}
