import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  formatProfileDateTime,
  hilosProfileDeviceKind,
  hilosPushSubscription,
  hilosTimeZone,
  subscribeSignal,
  type HilosConnection,
  type HilosProfileDevice,
  type HilosProfileDeviceActions,
  type HilosProfileDeviceKind,
  type HilosProfilePushChannel,
  type HilosPushSubscriptionStore,
} from '@hilos/core/base'

import { hilosSignal } from '../hilosSignal.js'
import { HilosFormError } from '../HilosFormError.js'
import { HilosModal } from '../HilosModal.js'
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
  imports: [HilosPushDeviceToggle, LoadingButton, HilosFormError, HilosModal],
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
          <div class="flex-grow-1 text-break">
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
                Registered · added {{ date(device.createdAt) }}
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
                [disabled]="!refreshed()"
                [opensWindow]="true"
                data-id="profile-device-remove"
                (click)="openRemove(device)"
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

      <hilos-modal
        [open]="target() !== null"
        (openChange)="$event ? null : target.set(null)"
        [title]="removeTitle()"
        initialFocus="dialog"
        [busy]="removeAction.busy()"
      >
        <p>
          This device will stop receiving notifications. To receive them again,
          subscribe from that device.
        </p>
        <hilos-form-error
          [message]="removeError()"
          dataId="profile-device-remove-error"
          [announce]="true"
        />
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="removeAction.busy()"
            (click)="requestClose()"
          >
            Cancel
          </button>
          <button
            hilosLoadingButton
            class="btn-danger"
            [loading]="removeAction.busy()"
            [disabled]="!refreshed() || !targetInDevices()"
            data-id="profile-device-remove-confirm"
            (click)="confirmRemove()"
          >
            Remove
          </button>
        </ng-template>
      </hilos-modal>
    </section>
  `,
})
export class HilosProfileDevices {
  readonly devices = input.required<readonly HilosProfileDevice[]>()
  readonly actions = input.required<HilosProfileDeviceActions>()
  readonly connection = input.required<HilosConnection>()
  readonly pushChannel = input.required<HilosProfilePushChannel | null>()
  readonly store = input<HilosPushSubscriptionStore>(hilosPushSubscription)

  protected get boundStore(): HilosPushSubscriptionStore {
    return this.store()
  }

  protected readonly supported = signal(this.boundStore.supported.get())
  protected readonly refreshed = signal(this.boundStore.refreshed.get())
  protected readonly removeAction = createHilosTrackedAction()
  protected readonly target = signal<{
    id: number
    deviceName: string | null
  } | null>(null)
  protected readonly targetInDevices = computed(() => {
    const t = this.target()
    return t !== null && this.devices().some((device) => device.id === t.id)
  })
  protected readonly removeError = computed(() =>
    this.targetInDevices()
      ? this.removeAction.error()
      : 'This device has already been removed.',
  )
  protected readonly removeTitle = computed(
    () => `Remove ${this.target()?.deviceName ?? 'Unknown device'}?`,
  )
  protected readonly showUnsubscribedCurrent = computed(
    () =>
      !this.devices().some((device) => device.current) &&
      this.refreshed() &&
      this.pushChannel() !== null &&
      this.supported(),
  )
  private readonly timeZone = hilosSignal(hilosTimeZone)

  constructor() {
    effect((onCleanup) => {
      const store = this.boundStore
      this.supported.set(store.supported.get())
      this.refreshed.set(store.refreshed.get())
      const unsubSupported = subscribeSignal(store.supported, (value) =>
        this.supported.set(value),
      )
      const unsubRefreshed = subscribeSignal(store.refreshed, (value) =>
        this.refreshed.set(value),
      )
      const releasePermission = store.observePermission?.()
      if (releasePermission === undefined) {
        void store.refresh()
      }
      onCleanup(() => {
        releasePermission?.()
        unsubSupported()
        unsubRefreshed()
      })
    })
  }

  protected deviceIcon(deviceName: string | null): string {
    return DEVICE_KIND_ICONS[hilosProfileDeviceKind(deviceName)]
  }

  protected date(value: number): string {
    this.timeZone()
    return formatProfileDateTime(value)
  }

  protected openRemove(device: HilosProfileDevice): void {
    if (!this.refreshed() || this.removeAction.busy()) {
      return
    }
    this.removeAction.clearError()
    this.target.set({ id: device.id, deviceName: device.deviceName })
  }

  protected async confirmRemove(): Promise<void> {
    const t = this.target()
    if (
      this.removeAction.busy() ||
      !this.refreshed() ||
      !this.targetInDevices() ||
      t === null
    ) {
      return
    }
    if (await this.removeAction.run(this.actions().removeDevice(t.id))) {
      this.target.set(null)
    }
  }
}
