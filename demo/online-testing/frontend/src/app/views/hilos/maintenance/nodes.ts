// Mount the framework Maintenance Nodes tab with this project's context.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosMaintenanceNodesPage } from '@hilos/angular/admin/maintenance'

import { hilosMaintenanceContext } from './hilosMaintenanceContext.js'

@Component({
  selector: 'app-maintenance-nodes',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosMaintenanceNodesPage],
  template: `<hilos-maintenance-nodes-page [context]="context" />`,
})
export class MaintenanceNodes {
  protected readonly context = hilosMaintenanceContext
}
