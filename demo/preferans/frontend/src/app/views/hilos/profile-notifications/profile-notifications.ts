import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosProfileNotificationsPage } from '@hilos/angular/base'

import { hilosProfileNotificationsContext } from './hilosProfileNotificationsContext.js'

@Component({
  selector: 'app-profile-notifications',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosProfileNotificationsPage],
  template: `<hilos-profile-notifications-page [context]="context" />`,
})
export class ProfileNotifications {
  protected readonly context = hilosProfileNotificationsContext
}
