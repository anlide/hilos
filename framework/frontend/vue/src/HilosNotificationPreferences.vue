<!-- HilosNotificationPreferences — the profile "Notifications" section (HIL-485):
one switch per globally-enabled channel letting the signed-in user opt in or out,
plus an always-on note when the project declares any mandatory notification type.
It renders the framework-owned preference store (hilosNotificationPreferences),
which the mounting profile surface feeds from the profile page data (the section
snapshot) and the `notification_preferences_changed` signal (live multi-device
sync) — see notificationPreferences.ts. A toggle is tracked, never optimistic: it
marks the row pending (a per-row loader on the switch) and fires the
`profile_notification_channel_set` action; the switch turns only when the server
fans the changed signal back to every one of the user's tabs, and a send that
never leaves simply settles the loader and snaps the row back. A channel with no
address for it is shown disabled with a hint to add one rather than hidden, so the
user sees the whole channel set; where the mounting page names the profile
section that adds an address (HIL-1166), the hint links there. Mandatory types carry no toggle — a switch that
cannot be turned off is worse than none — only the note. Sparse opt-out: no muted
row means allowed. Bootstrap classes only, no CSS of its own. -->
<script setup lang="ts">
import {
  HILOS_NOTIFICATION_PREFERENCES_COPY as COPY,
  hilosNotificationPreferences,
  hilosPushSubscription,
  NOTIFICATION_ACTION_CHANNEL_SET,
  type HilosConnection,
  type HilosNotificationChannelState,
  type HilosNotificationPreferencesStore,
  type HilosPageCrumb,
  type HilosPushSubscriptionStore,
} from '@hilos/core/base'
import { useId } from 'vue'

import HilosLink from './HilosLink.vue'
import HilosPushDeviceToggle from './HilosPushDeviceToggle.vue'
import HilosSwitch from './HilosSwitch.vue'
import { useSignal } from './useSignal.js'

const props = withDefaults(
  defineProps<{
    /** The connection the toggle action is sent over. */
    connection: HilosConnection
    /** The store the section renders; defaults to the shared framework store. */
    store?: HilosNotificationPreferencesStore
    /** The per-device push store the push channel's toggle renders. */
    pushStore?: HilosPushSubscriptionStore
    /** The profile section where an address is added, or null when the project has none. */
    addressSection?: HilosPageCrumb | null
    /** The path of that section; without it the hint stays plain text. */
    addressTo?: string
  }>(),
  {
    store: () => hilosNotificationPreferences,
    pushStore: () => hilosPushSubscription,
    addressSection: null,
    addressTo: undefined,
  },
)

const channels = useSignal(props.store.channels)
const mandatoryNote = useSignal(props.store.mandatoryNote)
const pending = useSignal(props.store.pending)

// One id base for this instance so two sections never collide on label `for`.
const baseId = useId()

function rowId(channel: string): string {
  return `${baseId}-${channel}`
}

// A web-push channel ships its VAPID public key as the row's `config`; that key
// both marks the row as the per-device push toggle and is what the browser
// subscribes with. A channel without it renders the ordinary preference switch.
function pushKey(row: HilosNotificationChannelState): string | undefined {
  return row.config?.vapid_public
}

// Toggling is tracked, not optimistic: mark the row pending, send the action,
// and let the changed signal settle it. A send that never leaves (no live
// connection) settles the loader here so the row snaps back to its last
// confirmed state instead of hanging spinning.
function toggle(row: HilosNotificationChannelState, enabled: boolean): void {
  props.store.markPending(row.channel)
  const sent = props.connection.sendAction(NOTIFICATION_ACTION_CHANNEL_SET, {
    channel: row.channel,
    enabled,
  })
  if (!sent) {
    props.store.clearPending(row.channel)
  }
}
</script>

<template>
  <section
    :aria-labelledby="`${baseId}-heading`"
    data-id="hilos-notification-preferences"
  >
    <h2
      :id="`${baseId}-heading`"
      class="h6 text-uppercase text-body-secondary mb-2"
    >
      Delivery channels
    </h2>
    <p
      v-if="channels.length === 0"
      class="text-body-secondary mb-0"
      data-id="hilos-notification-preferences-empty"
    >
      No notification channels are available.
    </p>
    <template v-for="row in channels" :key="row.channel">
      <HilosPushDeviceToggle
        v-if="pushKey(row) !== undefined"
        :connection="connection"
        :channel="row.channel"
        :label="row.label"
        :vapid-public-key="pushKey(row)!"
        :store="pushStore"
        :channel-row="true"
        :icon="row.icon"
      />
      <div
        v-else
        class="d-flex align-items-center gap-3 py-3 border-bottom"
        :data-id="`hilos-notification-preference-${row.channel}`"
      >
        <i
          class="bi fs-5 text-body-secondary flex-shrink-0"
          :class="row.icon ?? 'bi-bell'"
          aria-hidden="true"
          :data-id="`hilos-notification-preference-icon-${row.channel}`"
        />
        <div class="flex-grow-1 overflow-hidden">
          <div class="fw-semibold small">{{ row.label }}</div>
          <div
            class="hilos-stack small text-body-secondary text-break"
            :data-id="`hilos-notification-preference-subtitle-${row.channel}`"
          >
            <span class="invisible" aria-hidden="true" inert>
              <template v-if="addressSection && addressTo !== undefined">
                {{ COPY.noAddressBefore }} {{ addressSection.label }}
                {{ COPY.noAddressAfter }}
              </template>
              <template v-else>{{ COPY.noAddress }}</template>
            </span>
            <div
              v-if="!row.hasAddress"
              :id="`${rowId(row.channel)}-hint`"
              :data-id="`hilos-notification-preference-hint-${row.channel}`"
            >
              <template v-if="addressSection && addressTo !== undefined"
                >{{ COPY.noAddressBefore }}
                <HilosLink
                  :to="addressTo"
                  :data-id="`hilos-notification-preference-address-${row.channel}`"
                  >{{ addressSection.label }}</HilosLink
                >
                {{ COPY.noAddressAfter }}</template
              >
              <template v-else>{{ COPY.noAddress }}</template>
            </div>
            <div v-else :id="`${rowId(row.channel)}-subtitle`">
              {{ row.address ?? '\u00a0' }}
            </div>
          </div>
        </div>
        <div class="hilos-stack flex-shrink-0 text-nowrap">
          <div
            class="form-check form-switch mb-0 invisible"
            aria-hidden="true"
            inert
          >
            <span class="form-check-input"></span>
            <span
              class="spinner-border spinner-border-sm ms-2 align-middle"
            ></span>
          </div>
          <HilosSwitch
            class="mb-0"
            :checked="row.allowed"
            :busy="pending.has(row.channel)"
            :disabled="!row.hasAddress"
            :aria-label="row.label"
            :aria-describedby="`${rowId(row.channel)}-${row.hasAddress ? 'subtitle' : 'hint'}`"
            :data-id="`hilos-notification-preference-toggle-${row.channel}`"
            @toggle="toggle(row, $event)"
          />
        </div>
      </div>
    </template>
    <p
      v-if="mandatoryNote"
      class="text-body-secondary small mb-0 mt-2"
      data-id="hilos-notification-preferences-mandatory"
    >
      Security messages are always delivered.
    </p>
  </section>
</template>
