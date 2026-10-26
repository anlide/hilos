<script setup lang="ts">
import {
  formatProfileDateTime,
  hilosProfileDeviceKind,
  hilosPushSubscription,
  hilosTimeZone,
  type HilosConnection,
  type HilosProfileDevice,
  type HilosProfileDeviceActions,
  type HilosProfileDeviceKind,
  type HilosProfilePushChannel,
  type HilosPushSubscriptionStore,
} from '@hilos/core/base'
import { computed, onMounted } from 'vue'

import HilosPushDeviceToggle from '../HilosPushDeviceToggle.vue'
import LoadingButton from '../LoadingButton.vue'
import { useSignal } from '../useSignal.js'
import { useTrackedAction } from '../useTrackedAction.js'

const DEVICE_KIND_ICONS: Record<HilosProfileDeviceKind, string> = {
  phone: 'bi bi-phone',
  tablet: 'bi bi-tablet',
  laptop: 'bi bi-laptop',
  display: 'bi bi-display',
}

const props = withDefaults(
  defineProps<{
    /** Durable push destinations, including expired rows. */
    devices: readonly HilosProfileDevice[]
    /** Tracked command that removes one destination. */
    actions: HilosProfileDeviceActions
    /** Connection used by the local push toggle. */
    connection: HilosConnection
    /** Enabled push channel and public VAPID key, or null when unavailable. */
    pushChannel: HilosProfilePushChannel | null
    /** Local browser push state. */
    pushStore?: HilosPushSubscriptionStore
  }>(),
  { pushStore: () => hilosPushSubscription },
)

const timeZone = useSignal(hilosTimeZone)
const supported = useSignal(props.pushStore.supported)
const permission = useSignal(props.pushStore.permission)
const refreshed = useSignal(props.pushStore.refreshed)
const removeAction = useTrackedAction()
const removeBusy = computed(() => removeAction.busy.value)
const hasCurrent = computed(() =>
  props.devices.some((device) => device.current),
)
const showUnsubscribedCurrent = computed(
  () =>
    !hasCurrent.value &&
    refreshed.value &&
    props.pushChannel !== null &&
    supported.value &&
    permission.value !== 'denied',
)

onMounted(() => {
  void props.pushStore.refresh()
})

/** Format a device moment again when the reader's zone changes. */
function dateTime(moment: number): string {
  void timeZone.value
  return formatProfileDateTime(moment)
}

async function removeDevice(subscriptionId: number): Promise<void> {
  await removeAction.run(props.actions.removeDevice(subscriptionId))
}
</script>

<template>
  <section aria-label="Devices" data-id="profile-devices">
    <p>
      Push subscriptions. A device stays here long after its session ended, so
      this is not the same list as sessions.
    </p>

    <article
      v-for="device in devices"
      :key="device.id"
      class="d-flex align-items-center gap-3 py-3 border-bottom"
      data-id="profile-device-row"
    >
      <i
        :class="[
          DEVICE_KIND_ICONS[hilosProfileDeviceKind(device.deviceName)],
          'fs-5 text-body-secondary flex-shrink-0',
        ]"
        aria-hidden="true"
      />
      <div class="flex-grow-1 min-w-0 text-break">
        <h3 class="fw-semibold small mb-0">
          {{ device.deviceName ?? 'Unknown device'
          }}<span
            v-if="device.current"
            class="badge text-bg-success ms-1"
            data-id="profile-device-this"
            >this one</span
          >
        </h3>
        <div
          v-if="device.expired"
          class="small text-body-secondary"
          data-id="profile-device-expired"
        >
          Subscription expired
        </div>
        <div v-else class="small text-body-secondary">
          Subscribed · added {{ dateTime(device.createdAt) }}
        </div>
      </div>
      <HilosPushDeviceToggle
        v-if="device.current && pushChannel !== null"
        :connection="connection"
        :channel="pushChannel.channel"
        :label="pushChannel.label"
        :vapid-public-key="pushChannel.vapidPublicKey"
        :store="pushStore"
      />
      <LoadingButton
        v-else
        class="btn-outline-secondary btn-sm"
        :loading="removeBusy"
        :disabled="!refreshed"
        data-id="profile-device-remove"
        @click="removeDevice(device.id)"
      >
        Remove
      </LoadingButton>
    </article>

    <article
      v-if="showUnsubscribedCurrent"
      class="card"
      data-id="profile-device-none-this"
    >
      <div class="card-body">
        <h3 class="h6">This browser · Not subscribed</h3>
        <HilosPushDeviceToggle
          v-if="pushChannel !== null"
          :connection="connection"
          :channel="pushChannel.channel"
          :label="pushChannel.label"
          :vapid-public-key="pushChannel.vapidPublicKey"
          :store="pushStore"
        />
      </div>
    </article>
  </section>
</template>
