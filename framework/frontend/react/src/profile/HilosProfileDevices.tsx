import {
  formatProfileDateTime,
  hilosProfileDeviceKind,
  hilosPushSubscription,
  type HilosConnection,
  type HilosProfileDevice,
  type HilosProfileDeviceActions,
  type HilosProfileDeviceKind,
  type HilosProfilePushChannel,
  type HilosPushSubscriptionStore,
} from '@hilos/core/base'
import { useEffect } from 'react'

import { HilosPushDeviceToggle } from '../HilosPushDeviceToggle.js'
import { LoadingButton } from '../LoadingButton.js'
import { useSignal } from '../useSignal.js'
import { useTrackedAction } from '../useTrackedAction.js'

const DEVICE_KIND_ICONS: Record<HilosProfileDeviceKind, string> = {
  phone: 'bi bi-phone',
  tablet: 'bi bi-tablet',
  laptop: 'bi bi-laptop',
  display: 'bi bi-display',
}

/** Props for {@link HilosProfileDevices}. */
export interface HilosProfileDevicesProps {
  readonly devices: readonly HilosProfileDevice[]
  readonly actions: HilosProfileDeviceActions
  readonly connection: HilosConnection
  readonly pushChannel: HilosProfilePushChannel | null
  readonly pushStore?: HilosPushSubscriptionStore
}

/** The shared push-device list and its remove/current-device controls. */
export function HilosProfileDevices({
  devices,
  actions,
  connection,
  pushChannel,
  pushStore = hilosPushSubscription,
}: HilosProfileDevicesProps) {
  const supported = useSignal(pushStore.supported)
  const permission = useSignal(pushStore.permission)
  const refreshed = useSignal(pushStore.refreshed)
  const removeAction = useTrackedAction()
  const hasCurrent = devices.some((device) => device.current)
  const showUnsubscribedCurrent =
    !hasCurrent &&
    refreshed &&
    pushChannel !== null &&
    supported &&
    permission !== 'denied'

  useEffect(() => {
    void pushStore.refresh()
  }, [pushStore])

  async function removeDevice(subscriptionId: number): Promise<void> {
    await removeAction.run(actions.removeDevice(subscriptionId))
  }

  return (
    <section aria-label="Devices" data-id="profile-devices">
      <p>
        Push subscriptions. A device stays here long after its session ended, so
        this is not the same list as sessions.
      </p>
      {devices.map((device) => (
        <article
          key={device.id}
          className="d-flex align-items-center gap-3 py-3 border-bottom"
          data-id="profile-device-row"
        >
          <i
            className={`${DEVICE_KIND_ICONS[hilosProfileDeviceKind(device.deviceName)]} fs-5 text-body-secondary flex-shrink-0`}
            aria-hidden="true"
          />
          <div className="flex-grow-1 min-w-0 text-break">
            <h3 className="fw-semibold small mb-0">
              {device.deviceName ?? 'Unknown device'}
              {device.current ? (
                <span
                  className="badge text-bg-success ms-1"
                  data-id="profile-device-this"
                >
                  this one
                </span>
              ) : null}
            </h3>
            {device.expired ? (
              <div
                className="small text-body-secondary"
                data-id="profile-device-expired"
              >
                Subscription expired
              </div>
            ) : (
              <div className="small text-body-secondary">
                Subscribed · added {formatProfileDateTime(device.createdAt)}
              </div>
            )}
          </div>
          <div className="flex-shrink-0 text-nowrap">
            {device.current && pushChannel !== null ? (
              <HilosPushDeviceToggle
                connection={connection}
                channel={pushChannel.channel}
                label={pushChannel.label}
                vapidPublicKey={pushChannel.vapidPublicKey}
                store={pushStore}
              />
            ) : (
              <LoadingButton
                className="btn-outline-secondary btn-sm"
                loading={removeAction.busy}
                disabled={!refreshed}
                data-id="profile-device-remove"
                onClick={() => void removeDevice(device.id)}
              >
                Remove
              </LoadingButton>
            )}
          </div>
        </article>
      ))}
      {showUnsubscribedCurrent && pushChannel !== null ? (
        <article className="card" data-id="profile-device-none-this">
          <div className="card-body">
            <h3 className="h6">This browser · Not subscribed</h3>
            <HilosPushDeviceToggle
              connection={connection}
              channel={pushChannel.channel}
              label={pushChannel.label}
              vapidPublicKey={pushChannel.vapidPublicKey}
              store={pushStore}
            />
          </div>
        </article>
      ) : null}
    </section>
  )
}
