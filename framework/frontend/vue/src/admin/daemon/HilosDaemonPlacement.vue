<!-- The root owns the matrix subscription; this view only draws its current
window, complete placement totals and the locally chosen cell. -->
<script setup lang="ts">
import {
  DAEMON_PLACEMENT_TYPE_FIELD,
  DAEMON_PLACEMENT_PLACEMENT_FIELD,
  DAEMON_PLACEMENT_NODES_FIELD,
  DAEMON_PLACEMENT_UNPLACED_FIELD,
  daemonPlacementCellIds,
  daemonPlacementNodeColumnKey,
  formatDaemonNumber,
  formatDaemonPlacementKind,
  formatDaemonPlacementState,
  type HilosDaemonOverviewData,
  type HilosDaemonPlacementRow,
  type HilosDaemonPlacementView,
} from '@hilos/core'
import { computed } from 'vue'

import HilosSkeleton from '../../HilosSkeleton.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{
  view: HilosDaemonPlacementView
  overview: HilosDaemonOverviewData | null
}>()
const nodes = useSignal(props.view.nodes)
const selectedCell = useSignal(props.view.selectedCell)
const placement = computed(() => props.overview?.placement)
const unplacedTypes = computed(() => placement.value?.unplacedTypes ?? null)
const knownNodes = computed(
  () =>
    new Set(
      (props.overview?.nodes ?? [])
        .filter((node) => node.online && node.workers !== null)
        .map((node) => node.nodeId),
    ),
)

/**
 * A silent node cannot disclose ids retained in an older window.
 * @param row The current resolved matrix row.
 * @param nodeId The column's actual node id.
 */
function cellIds(
  row: HilosDaemonPlacementRow,
  nodeId: string,
): readonly string[] | null {
  return knownNodes.value.has(nodeId)
    ? daemonPlacementCellIds(row, nodeId)
    : null
}
</script>

<template>
  <div data-id="hilos-daemon-placement-view">
    <template v-if="overview === null">
      <p class="text-body-secondary">Waiting for the node picture…</p>
      <HilosSkeleton />
    </template>
    <template v-else>
      <div class="row row-cols-2 row-cols-md-4 g-2 mb-3">
        <div class="col">
          <section class="border rounded p-3 h-100">
            <h2 class="h6">Running instances</h2>
            <div class="fs-3" data-id="hilos-daemon-placement-instances">
              {{ formatDaemonNumber(placement?.instances) }}
            </div>
            <p class="small text-body-secondary mb-0">Instances, not types</p>
          </section>
        </div>
        <div class="col">
          <section class="border rounded p-3 h-100">
            <h2 class="h6">Agent types</h2>
            <div class="fs-3" data-id="hilos-daemon-placement-types">
              {{ formatDaemonNumber(placement?.types) }}
            </div>
            <p class="small text-body-secondary mb-0">
              Declared in the project
            </p>
          </section>
        </div>
        <div class="col">
          <section class="border rounded p-3 h-100">
            <h2 class="h6">One per cluster</h2>
            <div class="fs-3" data-id="hilos-daemon-placement-cluster-types">
              {{ formatDaemonNumber(placement?.clusterTypes) }}
            </div>
            <p class="small text-body-secondary mb-0">
              Declared in the project
            </p>
          </section>
        </div>
        <div class="col">
          <section class="border rounded p-3 h-100">
            <h2 class="h6">Unplaced</h2>
            <div
              class="fs-3"
              :class="{
                'text-danger-emphasis': (unplacedTypes?.length ?? 0) > 0,
              }"
              data-id="hilos-daemon-placement-unplaced"
            >
              {{ formatDaemonNumber(unplacedTypes?.length) }}
            </div>
            <p class="small text-body-secondary mb-0">
              {{
                unplacedTypes === null
                  ? 'Placement status unknown'
                  : unplacedTypes.length === 0
                    ? 'All placed'
                    : overview.clustered
                      ? 'Leader could not place these types'
                      : 'Could not place these types'
              }}
            </p>
          </section>
        </div>
      </div>

      <HilosViewportTable :controller="view.controller">
        <template #[`cell-${DAEMON_PLACEMENT_TYPE_FIELD}`]="{ row }">
          <code>{{ row.agentType }}</code>
        </template>
        <template #[`cell-${DAEMON_PLACEMENT_PLACEMENT_FIELD}`]="{ row }">
          {{ formatDaemonPlacementKind(row, overview.clustered) }}
        </template>
        <template #[`cell-${DAEMON_PLACEMENT_UNPLACED_FIELD}`]="{ row }">
          <span
            v-if="formatDaemonPlacementState(row)"
            class="badge text-bg-danger"
            >{{ formatDaemonPlacementState(row) }}</span
          >
        </template>
        <template
          v-for="node in nodes"
          :key="node.nodeId"
          #[`cell-${daemonPlacementNodeColumnKey(node.nodeId)}`]="{ row }"
        >
          <button
            v-if="(cellIds(row, node.nodeId)?.length ?? 0) > 0"
            type="button"
            class="btn btn-sm btn-outline-secondary"
            :aria-label="`${row.agentType} on ${node.nodeId}: ${cellIds(row, node.nodeId)?.length} instances`"
            :aria-expanded="
              selectedCell?.rowKey === row.rowKey &&
              selectedCell?.nodeId === node.nodeId
            "
            :data-id="`hilos-daemon-placement-cell-${row.rowKey}-${node.nodeId}`"
            @click="view.openCell(row.rowKey, node.nodeId)"
            @keydown.enter.prevent="view.openCell(row.rowKey, node.nodeId)"
            @keydown.space.prevent="view.openCell(row.rowKey, node.nodeId)"
          >
            {{ cellIds(row, node.nodeId)?.length }}
          </button>
          <span
            v-else
            class="text-body-secondary"
            :title="
              cellIds(row, node.nodeId) === null
                ? 'Node is silent or has not reported its agents'
                : undefined
            "
            >{{ cellIds(row, node.nodeId) === null ? '?' : '·' }}</span
          >
        </template>
        <template #[`detail-${DAEMON_PLACEMENT_NODES_FIELD}`]="{ row }">
          <section
            v-if="selectedCell?.rowKey === row.rowKey"
            class="rounded bg-body-tertiary p-2"
            data-id="hilos-daemon-placement-selection"
            aria-label="Selected agent instances"
          >
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
              <h2 class="h6 mb-0 text-break">
                <code>{{ selectedCell.agentType }}</code>
              </h2>
              <span
                >on
                <code class="text-break">{{ selectedCell.nodeId }}</code></span
              >
              <span class="badge bg-body text-body border">
                {{ selectedCell.agentIds.length }} instances
              </span>
              <button
                type="button"
                class="btn btn-sm btn-outline-secondary ms-auto"
                data-id="hilos-daemon-placement-collapse"
                @click="view.closeCell()"
              >
                Collapse
              </button>
            </div>
            <div class="d-flex flex-wrap gap-2">
              <code
                v-for="id in selectedCell.agentIds"
                :key="id"
                class="text-break"
                >{{ id }}</code
              >
            </div>
          </section>
        </template>
      </HilosViewportTable>

      <div data-id="hilos-daemon-placement-alerts" class="mt-3">
        <p
          v-for="agentType in unplacedTypes ?? []"
          :key="agentType"
          class="alert alert-danger py-2 small text-break"
        >
          <code>{{ agentType }}</code> is unplaced
        </p>
      </div>
    </template>
  </div>
</template>
