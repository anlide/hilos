<!-- HilosPushDeviceToggle — the profile "Notifications" section's control for the
web-push channel (HIL-199). Unlike the other channel switches, which set the
per-user preference (allowed/muted, shared across the user's tabs), web push is
per-device browser state: this switch subscribes or unsubscribes THIS browser.
It renders the framework-owned push-subscription store (hilosPushSubscription),
which reflects the live PushManager rather than a server snapshot, so it reads
the device on mount. A denied or unsupported browser cannot subscribe, so the
switch is disabled with a hint rather than retrying. Enabling prompts for
permission, subscribes with the channel's VAPID public key, and registers the
subscription server-side; disabling unsubscribes and drops the row. Bootstrap
classes only, no CSS of its own. -->
<script setup lang="ts">
import {
  HILOS_NOTIFICATION_PREFERENCES_COPY as COPY,
  hilosPushSubscription,
  type HilosConnection,
  type HilosPushSubscriptionStore,
} from '@hilos/core/base'
import { computed, onMounted, useId } from 'vue'

import { useSignal } from './useSignal.js'

const props = withDefaults(
  defineProps<{
    /** The connection the subscribe/unsubscribe action is sent over. */
    connection: HilosConnection
    /** The push channel's registry name, used for stable element ids. */
    channel: string
    /** The push channel's human label. */
    label: string
    /** The channel's VAPID public key (base64url) the browser subscribes with. */
    vapidPublicKey: string
    /** The store the toggle renders; defaults to the shared framework store. */
    store?: HilosPushSubscriptionStore
    /** Render a delivery-channel row instead of the compact device switch. */
    channelRow?: boolean
    /** Decorative Bootstrap Icons token for the delivery-channel row. */
    icon?: string
  }>(),
  {
    store: () => hilosPushSubscription,
    channelRow: false,
    icon: 'bi-broadcast',
  },
)

const supported = useSignal(props.store.supported)
const permission = useSignal(props.store.permission)
const subscribed = useSignal(props.store.subscribed)
const busy = useSignal(props.store.busy)

// Read the live browser state (support, permission, subscription) on mount so the
// switch reflects this device; the store's default signals are safe until then.
onMounted(() => {
  void props.store.refresh()
})

const baseId = useId()
const rowId = `${baseId}-${props.channel}`

// `denied` is terminal — the browser will not re-prompt — and an unsupported
// browser cannot subscribe at all; both disable the switch and show a hint
// instead of retrying.
const hint = computed(() => {
  if (!supported.value) {
    return COPY.pushUnsupported
  }
  if (permission.value === 'denied') {
    return COPY.pushBlocked
  }

  return ''
})

// Per-device: subscribe or unsubscribe THIS browser rather than setting a shared
// preference. The store owns the browser dance and the server round-trip.
function toggle(event: Event): void {
  const input = event.target as HTMLInputElement
  const enabled = input.checked
  // The channel row keeps the store's position while the browser operation runs.
  if (props.channelRow) {
    input.checked = subscribed.value
  }
  if (enabled) {
    void props.store.enable(props.connection, props.vapidPublicKey)
  } else {
    void props.store.disable(props.connection)
  }
}
</script>

<template>
  <div
    :class="
      channelRow ? 'd-flex align-items-center gap-3 py-3 border-bottom' : 'mb-2'
    "
    :data-id="`hilos-notification-preference-${channel}`"
  >
    <i
      v-if="channelRow"
      class="bi fs-5 text-body-secondary flex-shrink-0"
      :class="icon"
      aria-hidden="true"
      :data-id="`hilos-notification-preference-icon-${channel}`"
    />
    <div v-if="channelRow" class="flex-grow-1 overflow-hidden">
      <label class="fw-semibold small" :for="rowId">{{ label }}</label>
      <div
        class="hilos-stack small text-body-secondary text-break"
        :data-id="`hilos-notification-preference-subtitle-${channel}`"
      >
        <span class="invisible" aria-hidden="true" inert>
          {{ COPY.pushSubscribed }}
        </span>
        <span class="invisible" aria-hidden="true" inert>
          {{ COPY.pushNotSubscribed }}
        </span>
        <span class="invisible" aria-hidden="true" inert>
          {{ COPY.pushUnsupported }}
        </span>
        <span class="invisible" aria-hidden="true" inert>
          {{ COPY.pushBlocked }}
        </span>
        <div
          v-if="hint"
          :id="`${rowId}-hint`"
          :data-id="`hilos-notification-preference-hint-${channel}`"
        >
          {{ hint }}
        </div>
        <div v-else :id="`${rowId}-subtitle`">
          {{ subscribed ? COPY.pushSubscribed : COPY.pushNotSubscribed }}
        </div>
      </div>
    </div>
    <div :class="channelRow ? 'flex-shrink-0 text-nowrap' : undefined">
      <div class="form-check form-switch mb-0">
        <input
          :id="rowId"
          class="form-check-input"
          type="checkbox"
          role="switch"
          :checked="subscribed"
          :disabled="hint !== '' || busy"
          :aria-describedby="
            hint
              ? `${rowId}-hint`
              : channelRow
                ? `${rowId}-subtitle`
                : undefined
          "
          :aria-busy="busy"
          :data-id="`hilos-notification-preference-toggle-${channel}`"
          @change="toggle"
        />
        <label v-if="!channelRow" class="form-check-label" :for="rowId">
          {{ label }}
        </label>
        <span
          :class="
            channelRow
              ? 'hilos-stack d-inline-grid ms-2 align-middle'
              : undefined
          "
        >
          <span
            v-if="channelRow"
            class="spinner-border spinner-border-sm invisible"
            aria-hidden="true"
            inert
          ></span>
          <span
            v-if="busy"
            class="spinner-border spinner-border-sm align-middle"
            :class="{ 'ms-2': !channelRow }"
            role="status"
            :data-id="`hilos-notification-preference-pending-${channel}`"
            ><span class="visually-hidden">Saving…</span></span
          >
        </span>
        <div
          v-if="!channelRow && hint"
          :id="`${rowId}-hint`"
          class="form-text mt-0"
          :data-id="`hilos-notification-preference-hint-${channel}`"
        >
          {{ hint }}
        </div>
      </div>
    </div>
  </div>
</template>
