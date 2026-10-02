// Mount the framework maintenance section with this project's context.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosMaintenancePage } from '@hilos/angular'

import { hilosMaintenanceContext } from './hilosMaintenanceContext.js'

@Component({
  selector: 'app-maintenance',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosMaintenancePage],
  template: `<hilos-maintenance-page [context]="context" />`,
})
export class Maintenance {
  protected readonly context = hilosMaintenanceContext
}
