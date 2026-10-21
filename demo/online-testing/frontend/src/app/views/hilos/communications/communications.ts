import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosCommunicationsPage } from '@hilos/angular/admin/communications'

import { hilosCommunicationsContext } from './hilosCommunicationsContext.js'

@Component({
  selector: 'app-communications',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosCommunicationsPage],
  template: `<hilos-communications-page [context]="context" />`,
})
export class Communications {
  protected readonly context = hilosCommunicationsContext
}
