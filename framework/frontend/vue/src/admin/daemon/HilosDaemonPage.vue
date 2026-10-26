<!-- Overview of the node picture and the selected entry points. -->
<script setup lang="ts">
import {
  createHilosDaemonOverview,
  daemonMonopolisticCount,
  daemonPercentage,
  daemonProgressWidth,
  daemonTotalMemory,
  daemonWorkerOccupancy,
  formatDaemonBytes,
  formatDaemonCores,
  formatDaemonDatabaseAddress,
  formatDaemonNodeState,
  formatDaemonNodeSilentSince,
  formatDaemonNumber,
  formatDaemonPercentage,
  formatDaemonWorkerName,
  type HilosDaemonContext,
  type HilosDaemonOverviewNode,
} from '@hilos/core/admin/daemon'
import { HilosPages, hilosTimeZone } from '@hilos/core/base'
import { computed, inject } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosSkeleton from '../../HilosSkeleton.vue'
import HilosLink from '../../HilosLink.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{ context: HilosDaemonContext }>()
const daemon = createHilosDaemonOverview(props.context)
const overview = useSignal(daemon.overview)
const database = useSignal(daemon.database)
const timeZone = useSignal(hilosTimeZone)
const router = inject(hilosRouterKey)
if (!router)
  throw new Error('HilosDaemonPage requires a provided Hilos router.')

/** @param node The silent node's last-known silence boundary. */
function formatSilentSince(node: HilosDaemonOverviewNode): string | null {
  void timeZone.value
  return formatDaemonNodeSilentSince({ ...node, clustered: true }, Date.now())
}

const metricsPath = computed(() => {
  const source = overview.value?.entryPoints
  return source
    ? router.resolvePath(HilosPages.DAEMON_HTTP_SERVER, {
        nodeId: source.nodeId,
      })
    : null
})
</script>

<template>
  <HilosAdminPage
    :page="HilosPages.DAEMON"
    head-kind="section"
    tabs-label="Daemon pages"
  >
    <div data-id="hilos-daemon-nodes-view">
      <div v-if="overview === null" data-id="hilos-daemon-overview-waiting">
        <p class="text-body-secondary">Waiting for the node picture…</p>
        <HilosSkeleton />
      </div>
      <template v-else>
        <div
          class="d-flex flex-wrap gap-3 small text-body-secondary mb-3"
          data-id="hilos-daemon-entry-points"
        >
          <span
            ><i class="bi bi-window me-1" aria-hidden="true"></i>Browsers</span
          >
          <span
            ><i class="bi bi-terminal me-1" aria-hidden="true"></i>CLI on
            <span
              class="font-monospace"
              data-id="hilos-daemon-entry-command-port"
              >{{
                overview.entryPoints?.commandPort == null
                  ? '—'
                  : `:${overview.entryPoints.commandPort}`
              }}</span
            ></span
          >
          <span
            ><i class="bi bi-globe me-1" aria-hidden="true"></i>HTTP
            <span
              class="font-monospace"
              data-id="hilos-daemon-entry-http-port"
              >{{
                overview.entryPoints?.httpPort == null
                  ? '—'
                  : `:${overview.entryPoints.httpPort}`
              }}</span
            >
            <HilosLink
              v-if="metricsPath"
              :to="metricsPath"
              class="ms-1"
              data-id="hilos-daemon-entry-metrics"
              >metrics</HilosLink
            >
          </span>
        </div>
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
            <HilosLink
              :to="
                router.resolvePath(HilosPages.DAEMON_NODE, {
                  nodeId: node.nodeId,
                })!
              "
              class="btn bg-body text-body border rounded shadow-sm w-100 p-3 text-start text-decoration-none"
              :class="{ 'border-danger-subtle': !node.online }"
              :aria-label="`Node ${node.nodeId}`"
              :data-id="`hilos-daemon-node-${node.nodeId}`"
              :data-state="node.state"
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
                v-if="!node.online"
                class="d-block small text-body-secondary"
                :data-id="`hilos-daemon-silent-${node.nodeId}`"
              >
                Silent since {{ formatSilentSince(node) ?? '—' }}
              </span>
              <template v-else>
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
                    {{
                      formatDaemonNumber(daemonMonopolisticCount(node))
                    }}</span
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
              </template>
            </HilosLink>
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
    </div>
  </HilosAdminPage>
</template>
