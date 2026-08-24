<!-- The OAuth waiting modal (HIL-633): what a person sees in the page they stayed
on while a provider window is open in front of it. The shell mounts it once, beside
the toast host, so no project wires it and no page has to know a trip may be running
over it.

It shows for a LINK and not for a sign-in: a link is started from a live page that
must be held still while the trip runs, whereas a sign-in is already parked on the
auth surface's waiting screen, and a modal over that would be the same wait said
twice. Cancel is offered only while the person is at the provider — once the code is
being exchanged the window has closed itself and there is nothing left to take back.
Bootstrap classes only, no CSS of its own (styling-rules.md). -->
<script setup lang="ts">
import {
  cancelOAuthTrip,
  oauthTrip,
  oauthTripMessage,
  oauthTripTitle,
} from '@hilos/core'
import { computed } from 'vue'

import HilosModal from '../HilosModal.vue'
import { useSignal } from '../useSignal.js'

defineOptions({ name: 'HilosOAuthWaitModal' })

const trip = useSignal(oauthTrip)

const open = computed(() => trip.value?.intent === 'link')

// Only the provider leg can be taken back; the exchange leg is our own round trip
// and closing over it would leave the account half-linked in the person's head.
const cancelable = computed(() => trip.value?.phase === 'authorizing')

/**
 * Answer the dialog's own close attempts (Escape, backdrop, the header's ×). The
 * trip owns whether this is open, so refusing to cancel simply leaves it open.
 */
function onCloseRequest(): void {
  if (cancelable.value) {
    cancelOAuthTrip()
  }
}
</script>

<template>
  <HilosModal
    :model-value="open"
    :title="trip ? oauthTripTitle(trip) : ''"
    :close-on-esc="cancelable"
    :close-on-backdrop="cancelable"
    @update:model-value="onCloseRequest"
  >
    <div class="text-center py-2" data-id="auth-oauth-wait">
      <div class="spinner-border text-primary mb-3" role="status">
        <span class="visually-hidden">Waiting</span>
      </div>
      <p class="mb-0 small text-body-secondary">
        {{ trip ? oauthTripMessage(trip) : '' }}
      </p>
    </div>

    <template #actions>
      <button
        v-if="cancelable"
        type="button"
        class="btn btn-outline-secondary"
        data-id="auth-oauth-wait-cancel"
        @click="cancelOAuthTrip()"
      >
        Cancel
      </button>
    </template>
  </HilosModal>
</template>
