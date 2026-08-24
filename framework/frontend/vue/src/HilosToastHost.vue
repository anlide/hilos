<!-- HilosToastHost — the framework toast stack: transient notices in a fixed
bottom-end corner, newest at the bottom, each self-expiring on the store's timer.
It renders the shared core store (hilosToasts), so anything in the SDK or in a
project can report an outcome without threading a store through props — the shell
mounts this once (HilosLayout) and every page is covered.

Markup is the Bootstrap toast component driven declaratively — `.toast.show`
rendered by the store rather than Bootstrap's JS Toast (the SDK ships Bootstrap's
CSS, not its JS; HilosModal does the same). Colored variants use the documented
header-less form: a body plus a close button on a `text-bg-*` surface. Bootstrap
classes only, no CSS of its own (styling-rules.md). -->
<script setup lang="ts">
import type { HilosToastSeverity, HilosToastStore } from '@hilos/core'
import { hilosToasts } from '@hilos/core'

import { useSignal } from './useSignal.js'

const props = defineProps<{
  /** The stack to render; defaults to the application-wide store. */
  store?: HilosToastStore
}>()

const store = props.store ?? hilosToasts
const toasts = useSignal(store.toasts)

// Each severity maps to a Bootstrap color surface; the close button flips to its
// light variant on the dark surfaces so it stays visible.
const SURFACE: Record<HilosToastSeverity, string> = {
  error: 'text-bg-danger',
  success: 'text-bg-success',
  info: 'text-bg-secondary',
}

// Only a failure earns an interrupt; a success or a plain notice waits its turn
// rather than cutting into what the screen-reader user is listening to.
const LIVE_REGION: Record<
  HilosToastSeverity,
  { role: string; ariaLive: 'assertive' | 'polite' }
> = {
  error: { role: 'alert', ariaLive: 'assertive' },
  success: { role: 'status', ariaLive: 'polite' },
  info: { role: 'status', ariaLive: 'polite' },
}

// The cursor and keyboard focus are two independent holds on the countdown: the
// host only reports them, the store counts them. A focus move inside the stack —
// tabbing from one close button to the next — is neither an arrival nor a leave,
// and relatedTarget is what tells those apart; without the check the holds would
// never balance out.
function movesWithin(event: FocusEvent): boolean {
  const container = event.currentTarget as HTMLElement

  return container.contains(event.relatedTarget as Node | null)
}

// The host owns at most one hold of each kind and knows whether it is holding it,
// because a toast that closes under the pointer takes the release event with it:
// Chrome and WebKit fire no mouseleave and no focusout for an element that leaves
// the DOM, and they never make it up afterwards. So both holds are given back by
// hand in dismiss(), and the cursor's one is re-taken from mouseover rather than
// mouseenter — mouseover also fires for the toast that slides under a cursor
// standing still, which is exactly what happens to the notice below the one just
// closed.
let cursorHeld = false
let focusHeld = false

function holdOnCursor(): void {
  if (!cursorHeld) {
    cursorHeld = true
    store.pause()
  }
}

function releaseCursorHold(): void {
  if (cursorHeld) {
    cursorHeld = false
    store.resume()
  }
}

function releaseFocusHold(): void {
  if (focusHeld) {
    focusHeld = false
    store.resume()
  }
}

function holdOnFocus(event: FocusEvent): void {
  if (!focusHeld && !movesWithin(event)) {
    focusHeld = true
    store.pause()
  }
}

function releaseOnBlur(event: FocusEvent): void {
  if (!movesWithin(event)) {
    releaseFocusHold()
  }
}

function dismiss(id: number): void {
  releaseCursorHold()
  releaseFocusHold()
  store.dismiss(id)
}
</script>

<template>
  <div
    class="toast-container position-fixed bottom-0 end-0 p-3"
    data-id="hilos-toasts"
    @mouseover="holdOnCursor"
    @mouseleave="releaseCursorHold"
    @focusin="holdOnFocus"
    @focusout="releaseOnBlur"
  >
    <div
      v-for="toast in toasts"
      :key="toast.id"
      class="toast show align-items-center border-0 d-flex"
      :class="SURFACE[toast.severity]"
      :role="LIVE_REGION[toast.severity].role"
      :aria-live="LIVE_REGION[toast.severity].ariaLive"
      aria-atomic="true"
      :data-id="`hilos-toast-${toast.severity}`"
    >
      <div class="toast-body">{{ toast.message }}</div>
      <button
        type="button"
        class="btn-close btn-close-white me-2 m-auto"
        aria-label="Close"
        data-id="hilos-toast-close"
        @click="dismiss(toast.id)"
      ></button>
    </div>
  </div>
</template>
