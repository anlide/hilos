<script setup lang="ts">
import {
  formatProfileDateTime,
  hilosPushSubscription,
  type HilosConnection,
  type HilosProfileDevice,
  type HilosProfileDeviceActions,
  type HilosProfilePushChannel,
  type HilosPushSubscriptionStore,
} from '@hilos/core'
import { computed, onMounted } from 'vue'

import HilosPushDeviceToggle from '../HilosPushDeviceToggle.vue'
import LoadingButton from '../LoadingButton.vue'
import { useSignal } from '../useSignal.js'
import { useTrackedAction } from '../useTrackedAction.js'

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

const supported = useSignal(props.pushStore.supported)
const permission = useSignal(props.pushStore.permission)
const refreshed = useSignal(props.pushStore.refreshed)
const removeAction = useTrackedAction()
const removeLoading = computed(() => removeAction.loading.value)
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

    <div class="d-flex flex-column gap-3">
      <article
        v-for="device in devices"
        :key="device.id"
        class="card"
        data-id="profile-device-row"
      >
        <div
          class="card-body d-flex flex-wrap align-items-start justify-content-between gap-2"
        >
          <div>
            <h3 class="h6 mb-1">{{ device.deviceName ?? 'Unknown device' }}</h3>
            <span
              v-if="device.current"
              class="badge text-bg-primary me-1"
              data-id="profile-device-this"
              >this one</span
            >
            <span
              v-if="device.expired"
              class="badge text-bg-secondary"
              data-id="profile-device-expired"
              >Subscription expired</span
            >
            <div v-else class="small text-body-secondary">
              Subscribed · added {{ formatProfileDateTime(device.createdAt) }}
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
            class="btn-outline-danger btn-sm"
            :loading="removeLoading"
            :disabled="!refreshed || removeBusy"
            data-id="profile-device-remove"
            @click="removeDevice(device.id)"
          >
            Remove
          </LoadingButton>
        </div>
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
    </div>
  </section>
</template>
