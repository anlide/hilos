<!-- Nodes and processes from the root's one whole page answer. Local selection
supplies the node address for child cards in the shared admin shell. -->
<script setup lang="ts">
import {
  createHilosDaemonOverview,
  createHilosDaemonPlacementView,
  daemonMonopolisticCount,
  daemonNodeChildParams,
  daemonPercentage,
  daemonProgressWidth,
  daemonTotalMemory,
  daemonWorkerOccupancy,
  formatDaemonBytes,
  formatDaemonCores,
  formatDaemonDatabaseAddress,
  formatDaemonNodeState,
  formatDaemonNumber,
  formatDaemonPercentage,
  formatDaemonWorkerName,
  type HilosDaemonContext,
} from '@hilos/core/admin/daemon'
import { HilosPages } from '@hilos/core/base'
import { computed, onMounted, onUnmounted, ref } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosSkeleton from '../../HilosSkeleton.vue'
import { useSignal } from '../../useSignal.js'
import HilosDaemonNodeInspector from './HilosDaemonNodeInspector.vue'
import HilosDaemonPlacement from './HilosDaemonPlacement.vue'

const props = defineProps<{ context: HilosDaemonContext }>()
const daemon = createHilosDaemonOverview(props.context)
const placement = createHilosDaemonPlacementView(props.context, daemon.overview)
const placementActive = ref(false)
const placementOpened = ref(false)
const overview = useSignal(daemon.overview)
const database = useSignal(daemon.database)
const selectedNodeId = useSignal(daemon.selectedNodeId)
const selectedNode = useSignal(daemon.selectedNode)
const childParams = computed(() => daemonNodeChildParams(selectedNodeId.value))

/** Mount the matrix once and keep its local state between tab switches. */
function openPlacement(): void {
  placementOpened.value = true
  placementActive.value = true
}

onMounted(() => {
  daemon.start()
  placement.start()
})
onUnmounted(() => {
  placement.dispose()
  daemon.dispose()
})
</script>

<template>
  <HilosAdminPage :page="HilosPages.DAEMON" :child-params="childParams">
    <div class="nav nav-tabs mb-3" aria-label="Daemon diagrams">
      <button
        type="button"
        class="nav-link"
        :class="{ active: !placementActive }"
        :aria-pressed="!placementActive"
        data-id="hilos-daemon-nodes-tab"
        @click="placementActive = false"
      >
        Nodes and processes
      </button>
      <button
        type="button"
        class="nav-link"
        :class="{ active: placementActive }"
        :aria-pressed="placementActive"
        data-id="hilos-daemon-placement-tab"
        @click="openPlacement"
      >
        Agent placement
      </button>
    </div>

    <HilosDaemonPlacement
      v-if="placementOpened"
      v-show="placementActive"
      :view="placement"
      :overview="overview"
    />
    <div v-show="!placementActive" data-id="hilos-daemon-nodes-view">
      <div v-if="overview === null" data-id="hilos-daemon-overview-waiting">
        <p class="text-body-secondary">Waiting for the node picture…</p>
        <HilosSkeleton />
      </div>
      <template v-else>
        <div
          v-if="overview.clustered"
          class="d-flex flex-wrap align-items-center gap-2 mb-3 small"
          data-id="hilos-daemon-consensus"
        >
          <span
            class="badge border"
            :class="
              overview.consensus === null
                ? 'bg-body-tertiary text-body'
                : overview.consensus.onlineMasters >=
                    overview.consensus.quorumSize
                  ? 'bg-success-subtle text-success-emphasis border-success-subtle'
                  : 'bg-danger-subtle text-danger-emphasis border-danger-subtle'
            "
            data-id="hilos-daemon-quorum"
          >
            Quorum:
            {{
              overview.consensus === null
                ? '—'
                : `${overview.consensus.onlineMasters} of ${overview.consensus.masters}`
            }}
            <template v-if="overview.consensus">
              ·
              {{
                overview.consensus.onlineMasters >=
                overview.consensus.quorumSize
                  ? 'Available'
                  : 'Unavailable'
              }}</template
            >
          </span>
          <span
            class="badge bg-body-tertiary text-body border"
            data-id="hilos-daemon-term"
            >Term: {{ formatDaemonNumber(overview.consensus?.term) }}</span
          >
          <span v-if="overview.consensus" class="text-body-secondary text-break"
            >Report: {{ overview.consensus.nodeId }} · Majority:
            {{ overview.consensus.quorumSize }}</span
          >
        </div>

        <p
          v-if="overview.nodes.length === 0"
          class="border rounded p-4 text-center text-body-secondary"
          data-id="hilos-daemon-overview-empty"
        >
          No nodes in the picture
        </p>
        <div v-else class="row g-3 mb-3" data-id="hilos-daemon-nodes">
          <div
            v-for="(node, nodeIndex) in overview.nodes"
            :key="node.nodeId"
            class="col-12 col-md-6 col-xl-4 d-flex flex-column flex-md-row gap-2"
          >
            <div
              v-if="overview.clustered && nodeIndex > 0"
              class="d-flex flex-column align-items-center justify-content-center small text-body-secondary"
              :data-id="`hilos-daemon-link-${node.nodeId}`"
              :data-silent="
                !node.online || !overview.nodes[nodeIndex - 1]?.online
              "
            >
              <i
                class="bi"
                :class="
                  !node.online || !overview.nodes[nodeIndex - 1]?.online
                    ? 'bi-link-45deg text-danger-emphasis'
                    : 'bi-arrow-left-right'
                "
                aria-hidden="true"
              ></i>
              <span class="visually-hidden"
                >{{ overview.nodes[nodeIndex - 1]?.nodeId }} ↔
                {{ node.nodeId }} ·
                {{
                  !node.online || !overview.nodes[nodeIndex - 1]?.online
                    ? 'Silent member'
                    : 'Membership link'
                }}</span
              >
            </div>
            <button
              type="button"
              class="btn bg-body text-body border rounded shadow-sm w-100 p-3 text-start"
              :class="{
                'border-primary border-2': selectedNodeId === node.nodeId,
                'border-danger-subtle':
                  node.state === 'silent' && selectedNodeId !== node.nodeId,
              }"
              :aria-pressed="selectedNodeId === node.nodeId"
              :aria-label="`Select node ${node.nodeId}`"
              :data-id="`hilos-daemon-node-${node.nodeId}`"
              :data-state="node.state"
              @click="daemon.selectNode(node.nodeId)"
              @keydown.enter.prevent="daemon.selectNode(node.nodeId)"
              @keydown.space.prevent="daemon.selectNode(node.nodeId)"
            >
              <span class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <i class="bi bi-hdd-network" aria-hidden="true"></i>
                <span class="fw-semibold font-monospace text-break">{{
                  node.nodeId
                }}</span>
                <span
                  v-if="overview.clustered"
                  class="badge border"
                  :class="
                    node.state === 'silent'
                      ? 'bg-danger-subtle text-danger-emphasis border-danger-subtle'
                      : 'bg-body-tertiary text-body'
                  "
                  >{{
                    node.state === null
                      ? '—'
                      : formatDaemonNodeState(node.state)
                  }}</span
                >
                <span v-else class="badge bg-body-tertiary text-body border"
                  >Single installation</span
                >
              </span>
              <span
                class="d-flex flex-wrap gap-1 mb-3"
                :data-id="`hilos-daemon-workers-${node.nodeId}`"
              >
                <span
                  v-if="node.workers === null"
                  class="small text-body-secondary"
                  >Workers: —</span
                >
                <span
                  v-else-if="node.workers.length === 0"
                  class="small text-body-secondary"
                  >No workers</span
                >
                <span
                  v-for="worker in node.workers ?? []"
                  :key="`${worker.kind}:${worker.index}`"
                  class="badge border font-monospace"
                  :class="
                    daemonWorkerOccupancy(worker) === 'free'
                      ? 'bg-body-tertiary text-body border-secondary-subtle'
                      : 'bg-primary-subtle text-primary-emphasis border-primary-subtle'
                  "
                  :data-id="`hilos-daemon-worker-${node.nodeId}-${worker.kind}-${worker.index}`"
                  :data-occupancy="daemonWorkerOccupancy(worker)"
                  :title="`${worker.kind === 'monopolistic' ? 'Monopolistic' : 'Ordinary'} worker · ${daemonWorkerOccupancy(worker)} · ${worker.agentCount} agents`"
                >
                  {{ formatDaemonWorkerName(worker.index, worker.kind) }}
                  <span class="visually-hidden">
                    · {{ daemonWorkerOccupancy(worker) }} ·
                    {{ worker.agentCount }} agents</span
                  >
                </span>
              </span>
              <span class="d-block small">
                <span class="d-flex justify-content-between gap-2 mb-1"
                  ><span>Daemon CPU</span
                  ><span
                    >{{ formatDaemonCores(node.measurements?.daemonCores) }} /
                    {{ formatDaemonCores(node.measurements?.cores) }}
                    cores</span
                  ></span
                >
                <span
                  class="d-block progress hilos-progress-track"
                  aria-hidden="true"
                  ><span
                    class="progress-bar hilos-progress"
                    :style="{
                      '--hilos-progress': daemonProgressWidth(
                        daemonPercentage(
                          node.measurements?.daemonCores,
                          node.measurements?.cores,
                        ),
                      ),
                    }"
                  ></span
                ></span>
                <span class="d-block text-body-secondary mb-2"
                  >{{
                    formatDaemonPercentage(
                      daemonPercentage(
                        node.measurements?.daemonCores,
                        node.measurements?.cores,
                      ),
                    )
                  }}
                  of available CPU</span
                >
                <span class="d-flex justify-content-between gap-2 mb-1"
                  ><span>Measured memory</span
                  ><span
                    >{{ formatDaemonBytes(daemonTotalMemory(node)) }} /
                    {{
                      formatDaemonBytes(node.measurements?.memoryCeiling)
                    }}</span
                  ></span
                >
                <span
                  class="d-block progress hilos-progress-track"
                  aria-hidden="true"
                  ><span
                    class="progress-bar hilos-progress"
                    :style="{
                      '--hilos-progress': daemonProgressWidth(
                        daemonPercentage(
                          daemonTotalMemory(node),
                          node.measurements?.memoryCeiling,
                        ),
                      ),
                    }"
                  ></span
                ></span>
                <span class="d-block text-body-secondary mb-2"
                  >{{
                    formatDaemonNumber(node.measurements?.workersMeasured)
                  }}
                  worker processes measured</span
                >
                <span class="d-block"
                  >Monopolistic:
                  {{ formatDaemonNumber(daemonMonopolisticCount(node)) }}</span
                >
                <span class="d-block text-body-secondary mb-2"
                  >Pool grows on demand</span
                >
                <span
                  class="d-block"
                  :data-id="`hilos-daemon-browsers-${node.nodeId}`"
                  >{{
                    node.role === 'slave'
                      ? 'Does not accept browsers'
                      : `Browser connections: ${formatDaemonNumber(node.standing?.connections)}`
                  }}</span
                >
              </span>
            </button>
          </div>
        </div>
      </template>

      <section
        class="border rounded bg-body-tertiary p-3 mb-3"
        data-id="hilos-daemon-database"
      >
        <div class="d-flex flex-wrap align-items-center gap-2">
          <i class="bi bi-database" aria-hidden="true"></i>
          <h2 class="h6 mb-0">Project database</h2>
          <span
            class="small font-monospace text-break"
            data-id="hilos-daemon-database-address"
            >{{ formatDaemonDatabaseAddress(database) }}</span
          >
          <span
            class="badge bg-body text-body border"
            data-id="hilos-daemon-database-migration"
            >Migration: {{ formatDaemonNumber(database?.migrationIndex) }}</span
          >
        </div>
        <p
          v-if="database?.socket !== null && database?.socket !== undefined"
          class="small text-break mb-0 mt-2"
          data-id="hilos-daemon-database-socket"
        >
          Unix socket: <code>{{ database.socket }}</code>
        </p>
        <p class="small text-body-secondary mb-0 mt-2">
          Shared primary connection
        </p>
      </section>

      <HilosDaemonNodeInspector
        v-if="overview && selectedNode"
        :overview="overview"
        :node="selectedNode"
      />
    </div>
  </HilosAdminPage>
</template>
