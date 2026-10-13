<!-- Source indicator badge for one environment key reading. -->
<script setup lang="ts">
import { daemonEnvSourceBadge, type HilosDaemonEnvSource } from '@hilos/core'
import { computed } from 'vue'

const props = defineProps<{
  source: HilosDaemonEnvSource
}>()

const badge = computed(() => daemonEnvSourceBadge(props.source))
</script>

<template>
  <span
    class="badge rounded-pill flex-shrink-0"
    :class="[
      'bg-' + badge.tone + '-subtle',
      'text-' + badge.tone + '-emphasis',
      'border',
      'border-' + badge.tone + '-subtle',
    ]"
    :title="badge.hint"
  >
    {{ badge.label }}
  </span>
</template>
