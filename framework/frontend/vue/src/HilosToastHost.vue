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
</script>

<template>
  <div
    class="toast-container position-fixed bottom-0 end-0 p-3"
    data-id="hilos-toasts"
  >
    <div
      v-for="toast in toasts"
      :key="toast.id"
      class="toast show align-items-center border-0 d-flex"
      :class="SURFACE[toast.severity]"
      role="alert"
      aria-live="assertive"
      aria-atomic="true"
      :data-id="`hilos-toast-${toast.severity}`"
    >
      <div class="toast-body">{{ toast.message }}</div>
      <button
        type="button"
        class="btn-close btn-close-white me-2 m-auto"
        aria-label="Close"
        data-id="hilos-toast-close"
        @click="store.dismiss(toast.id)"
      ></button>
    </div>
  </div>
</template>
