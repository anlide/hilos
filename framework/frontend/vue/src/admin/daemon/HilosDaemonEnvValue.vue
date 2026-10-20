<!-- Value presentation for one environment reading. -->
<script setup lang="ts">
import { type HilosDaemonEnvValueView } from '@hilos/core/admin/daemon'

import HilosHiddenMark from '../../HilosHiddenMark.vue'

defineProps<{
  view: HilosDaemonEnvValueView
}>()
</script>

<template>
  <HilosHiddenMark v-if="view.kind === 'hidden'" />
  <span
    v-else-if="view.kind === 'secret'"
    class="text-body-secondary d-inline-flex align-items-center gap-1"
  >
    <i class="bi bi-key" aria-hidden="true"></i>{{ view.text }}
  </span>
  <span v-else-if="view.kind === 'none'" class="text-danger">—</span>
  <span
    v-else-if="view.kind === 'empty'"
    class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle flex-shrink-0"
    title="An empty string is a value"
  >
    empty
  </span>
  <input
    v-else-if="view.kind === 'boolean'"
    type="checkbox"
    class="form-check-input mt-0 flex-shrink-0"
    disabled
    tabindex="-1"
    :checked="view.on"
    :aria-label="view.on ? 'On' : 'Off'"
  />
  <code
    v-else-if="view.kind === 'text'"
    :class="{ 'fst-italic': view.numeric }"
  >
    {{ view.text }}
  </code>
</template>
