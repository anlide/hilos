import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosCommunicationsChannelConfigurationPage } from '@hilos/angular/admin/communications'

import { hilosCommunicationsContext } from './hilosCommunicationsContext.js'

@Component({
  selector: 'app-channel-configuration',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosCommunicationsChannelConfigurationPage],
  template: `<hilos-communications-channel-configuration-page
    [context]="context"
  />`,
})
export class Configuration {
  protected readonly context = hilosCommunicationsContext
}
