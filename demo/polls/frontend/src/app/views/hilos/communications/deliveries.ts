import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosCommunicationsDeliveriesPage } from '@hilos/angular/admin/communications'

import { hilosCommunicationsContext } from './hilosCommunicationsContext.js'

@Component({
  selector: 'app-deliveries',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosCommunicationsDeliveriesPage],
  template: `<hilos-communications-deliveries-page [context]="context" />`,
})
export class Deliveries {
  protected readonly context = hilosCommunicationsContext
}
