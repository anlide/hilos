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
user sees the whole channel set. Mandatory types carry no toggle — a switch that
cannot be turned off is worse than none — only the note. Sparse opt-out: no muted
row means allowed. Bootstrap classes only, no CSS of its own. -->
<script setup lang="ts">
import {
  hilosNotificationPreferences,
  NOTIFICATION_ACTION_CHANNEL_SET,
  type HilosConnection,
  type HilosNotificationChannelState,
  type HilosNotificationPreferencesStore,
} from '@hilos/core'
import { useId } from 'vue'

import { useSignal } from './useSignal.js'

const props = withDefaults(
  defineProps<{
    /** The connection the toggle action is sent over. */
    connection: HilosConnection
    /** The store the section renders; defaults to the shared framework store. */
    store?: HilosNotificationPreferencesStore
  }>(),
  { store: () => hilosNotificationPreferences },
)

const channels = useSignal(props.store.channels)
const mandatoryNote = useSignal(props.store.mandatoryNote)
const pending = useSignal(props.store.pending)

// One id base for this instance so two sections never collide on label `for`.
const baseId = useId()

function rowId(channel: string): string {
  return `${baseId}-${channel}`
}

// Toggling is tracked, not optimistic: mark the row pending, send the action,
// and let the changed signal settle it. A send that never leaves (no live
// connection) settles the loader here so the row snaps back to its last
// confirmed state instead of hanging spinning.
function toggle(row: HilosNotificationChannelState, event: Event): void {
  const enabled = (event.target as HTMLInputElement).checked
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
    <h2 :id="`${baseId}-heading`" class="h5">Notifications</h2>
    <p
      v-if="channels.length === 0"
      class="text-body-secondary mb-0"
      data-id="hilos-notification-preferences-empty"
    >
      No notification channels are available.
    </p>
    <div
      v-for="row in channels"
      :key="row.channel"
      class="form-check form-switch mb-2"
      :data-id="`hilos-notification-preference-${row.channel}`"
    >
      <input
        :id="rowId(row.channel)"
        class="form-check-input"
        type="checkbox"
        role="switch"
        :checked="row.allowed"
        :disabled="!row.hasAddress || pending.has(row.channel)"
        :aria-describedby="
          row.hasAddress ? undefined : `${rowId(row.channel)}-hint`
        "
        :aria-busy="pending.has(row.channel)"
        :data-id="`hilos-notification-preference-toggle-${row.channel}`"
        @change="toggle(row, $event)"
      />
      <label class="form-check-label" :for="rowId(row.channel)">
        {{ row.label }}
      </label>
      <span
        v-if="pending.has(row.channel)"
        class="spinner-border spinner-border-sm ms-2 align-middle"
        role="status"
        :data-id="`hilos-notification-preference-pending-${row.channel}`"
        ><span class="visually-hidden">Saving…</span></span
      >
      <div
        v-if="!row.hasAddress"
        :id="`${rowId(row.channel)}-hint`"
        class="form-text mt-0"
        :data-id="`hilos-notification-preference-hint-${row.channel}`"
      >
        Add an address in your profile to enable this channel.
      </div>
    </div>
    <p
      v-if="mandatoryNote"
      class="text-body-secondary small mb-0 mt-2"
      data-id="hilos-notification-preferences-mandatory"
    >
      Security messages are always delivered.
    </p>
  </section>
</template>
