import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
} from '@angular/core'
import {
  hilosNotificationAddressSection,
  startHilosNotificationPreferences,
  subscribeSignal,
  type HilosPageCrumb,
  type HilosProfilePageContext,
} from '@hilos/core/base'
import { HilosNotificationPreferences } from '../HilosNotificationPreferences.js'
import { HilosProfileHead } from './HilosProfileHead.js'
import { HILOS_ROUTER } from '../hilosRouterToken.js'

/** The notification channels section of the profile. */
@Component({
  selector: 'hilos-profile-notifications-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosNotificationPreferences, HilosProfileHead],
  template: `<section data-id="profile-notifications-view">
    <hilos-profile-head [context]="context()" />
    <hilos-notification-preferences
      [connection]="context().connection"
      [addressSection]="addressSection()"
      [addressTo]="addressTo()"
    />
  </section>`,
})
export class HilosProfileNotificationsPage {
  readonly context = input.required<HilosProfilePageContext>()
  private readonly router = inject(HILOS_ROUTER, { optional: true })
  protected readonly addressSection = signal<HilosPageCrumb | null>(null)
  protected readonly addressTo = computed(() => {
    const section = this.addressSection()
    return section ? this.router?.resolvePath(section.page) : undefined
  })
  constructor() {
    effect((onCleanup) => {
      onCleanup(startHilosNotificationPreferences(this.context()))
    })
    effect((onCleanup) => {
      const section = hilosNotificationAddressSection(this.context().scopes)
      this.addressSection.set(section.get())
      onCleanup(
        subscribeSignal(section, (value) => this.addressSection.set(value)),
      )
    })
  }
}
