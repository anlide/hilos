import {
  formatProfileDateTime,
  hilosPushSubscription,
  type HilosConnection,
  type HilosProfileDevice,
  type HilosProfileDeviceActions,
  type HilosProfilePushChannel,
  type HilosPushSubscriptionStore,
} from '@hilos/core'
import { useEffect } from 'react'

import { HilosPushDeviceToggle } from '../HilosPushDeviceToggle.js'
import { LoadingButton } from '../LoadingButton.js'
import { useSignal } from '../useSignal.js'
import { useTrackedAction } from '../useTrackedAction.js'

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
      <div className="d-flex flex-column gap-3">
        {devices.map((device) => (
          <article
            key={device.id}
            className="card"
            data-id="profile-device-row"
          >
            <div className="card-body d-flex flex-wrap align-items-start justify-content-between gap-2">
              <div>
                <h3 className="h6 mb-1">
                  {device.deviceName ?? 'Unknown device'}
                </h3>
                {device.current ? (
                  <span
                    className="badge text-bg-primary me-1"
                    data-id="profile-device-this"
                  >
                    this one
                  </span>
                ) : null}
                {device.expired ? (
                  <span
                    className="badge text-bg-secondary"
                    data-id="profile-device-expired"
                  >
                    Subscription expired
                  </span>
                ) : (
                  <div className="small text-body-secondary">
                    Subscribed · added {formatProfileDateTime(device.createdAt)}
                  </div>
                )}
              </div>
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
                  className="btn-outline-danger btn-sm"
                  loading={removeAction.loading}
                  disabled={!refreshed || removeAction.busy}
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
      </div>
    </section>
  )
}
