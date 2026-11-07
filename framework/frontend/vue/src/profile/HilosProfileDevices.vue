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
import {
  computed,
  effectScope,
  onBeforeUnmount,
  onMounted,
  onScopeDispose,
  ref,
  shallowRef,
  watch,
} from 'vue'

import HilosFormError from '../HilosFormError.vue'
import HilosModal from '../HilosModal.vue'
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
function mirrors() {
  const store = props.pushStore
  return {
    supported: useSignal(store.supported),
    refreshed: useSignal(store.refreshed),
  }
}
let mirrorScope = effectScope()
const current = shallowRef(mirrorScope.run(mirrors)!)
watch(
  () => props.pushStore,
  () => {
    mirrorScope.stop()
    mirrorScope = effectScope()
    current.value = mirrorScope.run(mirrors)!
  },
  { flush: 'sync' },
)
onScopeDispose(() => mirrorScope.stop())
const supported = computed(() => current.value.supported.value)
const refreshed = computed(() => current.value.refreshed.value)
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
    supported.value,
)

interface RemoveTarget {
  readonly id: number
  readonly deviceName: string | null
}

const target = ref<RemoveTarget | null>(null)
const targetInDevices = computed(
  () =>
    target.value !== null &&
    props.devices.some((device) => device.id === target.value?.id),
)
const modalTitle = computed(
  () => `Remove ${target.value?.deviceName ?? 'Unknown device'}?`,
)
const removeError = computed(() => {
  if (target.value !== null && !targetInDevices.value) {
    return 'This device has already been removed.'
  }
  return removeAction.error.value
})

let stopPermission: (() => void) | undefined
onMounted(() => {
  stopPermission = watch(
    () => props.pushStore,
    (store, _previous, onCleanup) => {
      if (store.observePermission) {
        onCleanup(store.observePermission())
      } else {
        void store.refresh()
      }
    },
    { immediate: true },
  )
})
onBeforeUnmount(() => stopPermission?.())

/** Format a device moment again when the reader's zone changes. */
function dateTime(moment: number): string {
  void timeZone.value
  return formatProfileDateTime(moment)
}

function openRemove(device: HilosProfileDevice): void {
  if (!refreshed.value || removeBusy.value) {
    return
  }
  removeAction.clearError()
  target.value = {
    id: device.id,
    deviceName: device.deviceName,
  }
}

async function confirmRemove(): Promise<void> {
  const currentTarget = target.value
  if (
    currentTarget === null ||
    removeBusy.value ||
    !refreshed.value ||
    !targetInDevices.value
  ) {
    return
  }
  if (await removeAction.run(props.actions.removeDevice(currentTarget.id))) {
    target.value = null
  }
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
          Registered · added {{ dateTime(device.createdAt) }}
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
        :disabled="!refreshed"
        :opens-window="true"
        data-id="profile-device-remove"
        @click="openRemove(device)"
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

    <HilosModal
      :model-value="target !== null"
      :title="modalTitle"
      :busy="removeBusy"
      initial-focus="dialog"
      @update:model-value="target = $event ? target : null"
    >
      <p>
        This device will stop receiving notifications. To receive them again,
        subscribe from that device.
      </p>
      <HilosFormError
        :message="removeError"
        data-id="profile-device-remove-error"
        announce
      />
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="removeBusy"
          @click="requestClose"
        >
          Cancel
        </button>
        <LoadingButton
          class="btn-danger"
          :loading="removeBusy"
          :disabled="!refreshed || !targetInDevices"
          data-id="profile-device-remove-confirm"
          @click="confirmRemove"
        >
          Remove
        </LoadingButton>
      </template>
    </HilosModal>
  </section>
</template>
