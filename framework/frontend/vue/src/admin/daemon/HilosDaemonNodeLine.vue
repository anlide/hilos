<!-- Shared node line for the Daemon child pages. The diagram link appears when
the application's router can resolve the section root. -->
<script setup lang="ts">
import {
  daemonNodeDiagramPath,
  formatDaemonNodeState,
  type HilosDaemonNodeHeading,
} from '@hilos/core'
import { computed, inject } from 'vue'

import HilosLink from '../../HilosLink.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

const props = defineProps<{
  nodeId: string
  heading: HilosDaemonNodeHeading | null
}>()

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error('HilosDaemonNodeLine requires a provided Hilos router.')
}

const diagramPath = computed(() =>
  props.heading?.clustered
    ? daemonNodeDiagramPath(router.resolvePath)
    : undefined,
)

function stateClass(state: string): string {
  switch (state) {
    case 'leader':
      return 'text-bg-warning-subtle text-warning-emphasis border border-warning-subtle'
    case 'standby':
      return 'text-bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle'
    case 'data':
      return 'text-bg-info-subtle text-info-emphasis border border-info-subtle'
    case 'silent':
      return 'text-bg-danger'
    default:
      return 'bg-body-tertiary text-body border'
  }
}

function stateIcon(state: string): string | null {
  switch (state) {
    case 'leader':
      return 'bi-star-fill'
    case 'standby':
      return 'bi-pause-circle'
    case 'data':
      return 'bi-hdd'
    case 'silent':
      return 'bi-plug'
    default:
      return null
  }
}
</script>

<template>
  <div
    class="d-flex flex-wrap align-items-center gap-2 mb-3 small"
    data-id="hilos-daemon-node-line"
  >
    <span class="text-body-secondary">Node</span>
    <span
      class="badge bg-body-tertiary text-body border font-monospace"
      data-id="hilos-daemon-node-id"
      >{{ nodeId }}</span
    >
    <template v-if="heading?.clustered">
      <span
        v-if="heading.state !== null"
        class="badge"
        :class="stateClass(heading.state)"
        :data-state="heading.state"
        data-id="hilos-daemon-node-state"
      >
        <i
          v-if="stateIcon(heading.state)"
          class="bi me-1"
          :class="stateIcon(heading.state)"
          aria-hidden="true"
        ></i>
        {{ formatDaemonNodeState(heading.state) }}
      </span>
      <HilosLink
        v-if="diagramPath"
        :to="diagramPath"
        class="ms-2"
        data-id="hilos-daemon-node-diagram"
        >Change on the diagram</HilosLink
      >
    </template>
    <span
      v-else-if="heading"
      class="badge bg-body-tertiary text-body border"
      data-id="hilos-daemon-node-solo"
    >
      <i class="bi bi-hdd me-1" aria-hidden="true"></i>Single installation
    </span>
  </div>
</template>
