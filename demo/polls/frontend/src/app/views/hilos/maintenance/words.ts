// Mount the framework Maintenance Words tab with this project's context.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosMaintenanceWordsPage } from '@hilos/angular/admin/maintenance'

import { hilosMaintenanceContext } from './hilosMaintenanceContext.js'

@Component({
  selector: 'app-maintenance-words',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosMaintenanceWordsPage],
  template: `<hilos-maintenance-words-page [context]="context" />`,
})
export class MaintenanceWords {
  protected readonly context = hilosMaintenanceContext
}
