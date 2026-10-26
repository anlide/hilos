import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosCommunicationsChannelFramePage } from '@hilos/angular/admin/communications'

import { hilosCommunicationsContext } from './hilosCommunicationsContext.js'

@Component({
  selector: 'app-channel-frame',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosCommunicationsChannelFramePage],
  template: `<hilos-communications-channel-frame-page [context]="context" />`,
})
export class Frame {
  protected readonly context = hilosCommunicationsContext
}
