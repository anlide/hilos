import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
  type OnInit,
} from '@angular/core'
import {
  formatProfileDateTime,
  hilosProfileDeviceKind,
  hilosPushSubscription,
  subscribeSignal,
  type HilosConnection,
  type HilosProfileDevice,
  type HilosProfileDeviceActions,
  type HilosProfileDeviceKind,
  type HilosProfilePushChannel,
  type HilosPushSubscriptionStore,
} from '@hilos/core/base'

import { HilosPushDeviceToggle } from '../HilosPushDeviceToggle.js'
import { LoadingButton } from '../LoadingButton.js'
import { createHilosTrackedAction } from '../hilosTrackedAction.js'

const DEVICE_KIND_ICONS: Record<HilosProfileDeviceKind, string> = {
  phone: 'bi bi-phone',
  tablet: 'bi bi-tablet',
  laptop: 'bi bi-laptop',
  display: 'bi bi-display',
}

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
      @for (device of devices(); track device.id) {
        <article
          class="d-flex align-items-center gap-3 py-3 border-bottom"
          data-id="profile-device-row"
        >
          <i
            [class]="
              deviceIcon(device.deviceName) +
              ' fs-5 text-body-secondary flex-shrink-0'
            "
            aria-hidden="true"
          ></i>
          <div class="flex-grow-1 min-w-0 text-break">
            <h3 class="fw-semibold small mb-0">
              {{ device.deviceName ?? 'Unknown device' }}
              @if (device.current) {
                <span
                  class="badge text-bg-success ms-1"
                  data-id="profile-device-this"
                  >this one</span
                >
              }
            </h3>
            @if (device.expired) {
              <div
                class="small text-body-secondary"
                data-id="profile-device-expired"
              >
                Subscription expired
              </div>
            } @else {
              <div class="small text-body-secondary">
                Subscribed · added {{ date(device.createdAt) }}
              </div>
            }
          </div>
          <div class="flex-shrink-0 text-nowrap">
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
                class="btn-outline-secondary btn-sm"
                [loading]="removeAction.busy()"
                [disabled]="!refreshed()"
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
    </section>
  `,
})
export class HilosProfileDevices implements OnInit {
  readonly devices = input.required<readonly HilosProfileDevice[]>()
  readonly actions = input.required<HilosProfileDeviceActions>()
  readonly connection = input.required<HilosConnection>()
  readonly pushChannel = input.required<HilosProfilePushChannel | null>()
  readonly store = input<HilosPushSubscriptionStore>(hilosPushSubscription)

  protected get boundStore(): HilosPushSubscriptionStore {
    return this.store()
  }

  protected readonly supported = signal(this.boundStore.supported.get())
  protected readonly permission = signal(this.boundStore.permission.get())
  protected readonly refreshed = signal(this.boundStore.refreshed.get())
  protected readonly removeAction = createHilosTrackedAction()
  protected readonly showUnsubscribedCurrent = computed(
    () =>
      !this.devices().some((device) => device.current) &&
      this.refreshed() &&
      this.pushChannel() !== null &&
      this.supported() &&
      this.permission() !== 'denied',
  )

  constructor() {
    effect((onCleanup) => {
      const store = this.boundStore
      this.supported.set(store.supported.get())
      this.permission.set(store.permission.get())
      this.refreshed.set(store.refreshed.get())
      const unsubSupported = subscribeSignal(store.supported, (value) =>
        this.supported.set(value),
      )
      const unsubPermission = subscribeSignal(store.permission, (value) =>
        this.permission.set(value),
      )
      const unsubRefreshed = subscribeSignal(store.refreshed, (value) =>
        this.refreshed.set(value),
      )
      onCleanup(() => {
        unsubSupported()
        unsubPermission()
        unsubRefreshed()
      })
    })
  }

  ngOnInit(): void {
    void this.boundStore.refresh()
  }

  protected deviceIcon(deviceName: string | null): string {
    return DEVICE_KIND_ICONS[hilosProfileDeviceKind(deviceName)]
  }

  protected date(value: number): string {
    return formatProfileDateTime(value)
  }

  protected async removeDevice(subscriptionId: number): Promise<void> {
    await this.removeAction.run(this.actions().removeDevice(subscriptionId))
  }
}
