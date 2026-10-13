<!-- Comparison cell for one node and one environment key. -->
<script setup lang="ts">
import {
  daemonEnvMismatchCellView,
  daemonEnvMismatchFromExample,
  daemonEnvMismatchStandsOut,
  type HilosDaemonEnvMismatchRow,
} from '@hilos/core'
import { computed } from 'vue'

import HilosDaemonEnvSourceBadge from './HilosDaemonEnvSourceBadge.vue'
import HilosDaemonEnvValue from './HilosDaemonEnvValue.vue'

const props = defineProps<{
  row: HilosDaemonEnvMismatchRow
  nodeId: string
  servingNodeId: string | null
  valuesMatch: boolean
}>()

const view = computed(() =>
  daemonEnvMismatchCellView(
    props.row,
    props.nodeId,
    props.servingNodeId,
    props.valuesMatch,
  ),
)
const standsOut = computed(
  () =>
    !props.valuesMatch && daemonEnvMismatchStandsOut(props.row, props.nodeId),
)
const fromExample = computed(
  () =>
    props.valuesMatch && daemonEnvMismatchFromExample(props.row, props.nodeId),
)
</script>

<template>
  <div
    class="d-flex flex-column gap-1"
    :data-id="`hilos-daemon-env-mismatch-cell-${row.rowKey}-${nodeId}`"
    :data-kind="view.kind"
  >
    <template v-if="view.kind === 'unknown'">
      <span
        class="text-body-secondary"
        title="Silent or no environment report: unknown, not a match"
      >
        ?
      </span>
      <span class="visually-hidden">
        Silent or no environment report: unknown, not a match
      </span>
    </template>
    <span v-else-if="view.kind === 'undeclared'" class="text-danger">
      Not declared
    </span>
    <template v-else-if="view.kind === 'none'">
      <span>—</span>
      <HilosDaemonEnvSourceBadge :source="view.source" />
    </template>
    <template v-else-if="view.kind === 'digest'">
      <code class="small">{{ view.text }}</code>
      <HilosDaemonEnvSourceBadge :source="view.source" />
    </template>
    <template v-else-if="view.kind === 'value'">
      <HilosDaemonEnvValue :view="view.view" />
      <HilosDaemonEnvSourceBadge :source="view.source" />
    </template>
    <span
      v-if="standsOut"
      class="small text-danger"
      :data-id="`hilos-daemon-env-mismatch-cell-${row.rowKey}-${nodeId}-stands-out`"
    >
      <i class="bi bi-exclamation-circle me-1" aria-hidden="true"></i>Differs
      from the others
    </span>
    <span
      v-if="fromExample"
      class="small text-warning-emphasis"
      :data-id="`hilos-daemon-env-mismatch-cell-${row.rowKey}-${nodeId}-from-example`"
    >
      <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>From
      .env.example
    </span>
  </div>
</template>
