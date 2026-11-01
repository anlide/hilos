// Mount the framework Maintenance Circle tab with this project's context.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosMaintenanceCirclePage } from '@hilos/angular/admin/maintenance'

import { hilosMaintenanceContext } from './hilosMaintenanceContext.js'

@Component({
  selector: 'app-maintenance-circle',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosMaintenanceCirclePage],
  template: `<hilos-maintenance-circle-page [context]="context" />`,
})
export class MaintenanceCircle {
  protected readonly context = hilosMaintenanceContext
}
