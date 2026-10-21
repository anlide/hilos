import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosCommunicationsChannelPage } from '@hilos/angular/admin/communications'

import { hilosCommunicationsContext } from './hilosCommunicationsContext.js'

@Component({
  selector: 'app-channel',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosCommunicationsChannelPage],
  template: `<hilos-communications-channel-page [context]="context" />`,
})
export class Channel {
  protected readonly context = hilosCommunicationsContext
}
