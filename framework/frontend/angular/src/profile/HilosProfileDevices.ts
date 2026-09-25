import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input,
  type OnInit,
} from '@angular/core'
import {
  formatProfileDateTime,
  hilosPushSubscription,
  type HilosConnection,
  type HilosProfileDevice,
  type HilosProfileDeviceActions,
  type HilosProfilePushChannel,
  type HilosPushSubscriptionStore,
} from '@hilos/core'

import { HilosPushDeviceToggle } from '../HilosPushDeviceToggle.js'
import { LoadingButton } from '../LoadingButton.js'
import { hilosSignal } from '../hilosSignal.js'
import { createHilosTrackedAction } from '../hilosTrackedAction.js'

/** The shared push-device list and its remove/current-device controls. */
@Component({
  selector: 'hilos-profile-devices',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosPushDeviceToggle, LoadingButton],
  template: `
    <section aria-label="Devices" data-id="profile-devices">
      <p>
        Push subscriptions. A device stays here long after its session ended, so
        this is not the same list as sessions.
      </p>
      <div class="d-flex flex-column gap-3">
        @for (device of devices(); track device.id) {
          <article class="card" data-id="profile-device-row">
            <div
              class="card-body d-flex flex-wrap align-items-start justify-content-between gap-2"
            >
              <div>
                <h3 class="h6 mb-1">
                  {{ device.deviceName ?? 'Unknown device' }}
                </h3>
                @if (device.current) {
                  <span
                    class="badge text-bg-primary me-1"
                    data-id="profile-device-this"
                    >this one</span
                  >
                }
                @if (device.expired) {
                  <span
                    class="badge text-bg-secondary"
                    data-id="profile-device-expired"
                    >Subscription expired</span
                  >
                } @else {
                  <div class="small text-body-secondary">
                    Subscribed · added {{ date(device.createdAt) }}
                  </div>
                }
              </div>
              @if (device.current && pushChannel(); as channel) {
                <hilos-push-device-toggle
                  [connection]="connection()"
                  [channel]="channel.channel"
                  [label]="channel.label"
                  [vapidPublicKey]="channel.vapidPublicKey"
                  [store]="store()"
                />
              } @else {
                <button
                  hilosLoadingButton
                  class="btn-outline-danger btn-sm"
                  [loading]="removeAction.loading()"
                  [disabled]="!refreshed() || removeAction.busy()"
                  data-id="profile-device-remove"
                  (click)="removeDevice(device.id)"
                >
                  Remove
                </button>
              }
            </div>
          </article>
        }
        @if (showUnsubscribedCurrent()) {
          <article class="card" data-id="profile-device-none-this">
            <div class="card-body">
              <h3 class="h6">This browser · Not subscribed</h3>
              @if (pushChannel(); as channel) {
                <hilos-push-device-toggle
                  [connection]="connection()"
                  [channel]="channel.channel"
                  [label]="channel.label"
                  [vapidPublicKey]="channel.vapidPublicKey"
                  [store]="store()"
                />
              }
            </div>
          </article>
        }
      </div>
    </section>
  `,
})
export class HilosProfileDevices implements OnInit {
  readonly devices = input.required<readonly HilosProfileDevice[]>()
  readonly actions = input.required<HilosProfileDeviceActions>()
  readonly connection = input.required<HilosConnection>()
  readonly pushChannel = input.required<HilosProfilePushChannel | null>()
  readonly store = input<HilosPushSubscriptionStore>(hilosPushSubscription)

  private readonly boundStore = this.store()
  protected readonly supported = hilosSignal(this.boundStore.supported)
  protected readonly permission = hilosSignal(this.boundStore.permission)
  protected readonly refreshed = hilosSignal(this.boundStore.refreshed)
  protected readonly removeAction = createHilosTrackedAction()
  protected readonly showUnsubscribedCurrent = computed(
    () =>
      !this.devices().some((device) => device.current) &&
      this.refreshed() &&
      this.pushChannel() !== null &&
      this.supported() &&
      this.permission() !== 'denied',
  )

  ngOnInit(): void {
    void this.boundStore.refresh()
  }

  protected date(value: string): string {
    return formatProfileDateTime(value)
  }

  protected async removeDevice(subscriptionId: number): Promise<void> {
    await this.removeAction.run(this.actions().removeDevice(subscriptionId))
  }
}
