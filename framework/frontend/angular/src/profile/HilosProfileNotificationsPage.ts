import {
  ChangeDetectionStrategy,
  Component,
  effect,
  input,
} from '@angular/core'
import {
  startHilosNotificationPreferences,
  type HilosProfileNotificationsContext,
} from '@hilos/core'
import { HilosNotificationPreferences } from '../HilosNotificationPreferences.js'
import { HilosPageHeading } from '../HilosPageHeading.js'

/** The notification channels section of the profile. */
@Component({
  selector: 'hilos-profile-notifications-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosNotificationPreferences, HilosPageHeading],
  template: `<section data-id="profile-notifications-view">
    <hilos-page-heading />
    <hilos-notification-preferences [connection]="context().connection" />
  </section>`,
})
export class HilosProfileNotificationsPage {
  readonly context = input.required<HilosProfileNotificationsContext>()
  constructor() {
    effect((onCleanup) => {
      onCleanup(startHilosNotificationPreferences(this.context()))
    })
  }
}
