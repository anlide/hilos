<!-- Workers of one Daemon node. The page scope carries the node heading and
reported-roster verdict; a server viewport supplies the worker rows. -->
<script setup lang="ts">
import {
  createHilosDaemonWorkersTable,
  daemonWorkersEmptyState,
  daemonWorkerLogPath,
  formatDaemonNodeSilentSince,
  formatDaemonWorkerAgentCount,
  formatDaemonWorkerKind,
  formatDaemonWorkerMemory,
  formatDaemonWorkerName,
  formatDaemonWorkerPid,
  HilosPages,
  readDaemonWorkersProcessesReported,
  readHilosDaemonNodeHeading,
  type HilosDaemonWorkersContext,
} from '@hilos/core'
import { computed, inject, onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosLink from '../../HilosLink.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'
import HilosDaemonNodeLine from './HilosDaemonNodeLine.vue'

const props = defineProps<{
  /** The project's connection and page scope. */
  context: HilosDaemonWorkersContext
}>()

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error('HilosDaemonWorkersPage requires a provided Hilos router.')
}

const nodeId =
  (router.currentRoute.get().params.nodeId as string | undefined) ?? ''
const workers = createHilosDaemonWorkersTable(props.context, nodeId)
const heading = useSignal(readHilosDaemonNodeHeading(props.context.scopes))
const reported = useSignal(
  readDaemonWorkersProcessesReported(props.context.scopes),
)
const rows = useSignal(workers.controller.rows)
const emptyState = computed(() =>
  daemonWorkersEmptyState(heading.value, reported.value),
)
const silentSince = computed(() =>
  formatDaemonNodeSilentSince(heading.value, Date.now()),
)

onMounted(() => workers.start())
onUnmounted(() => workers.dispose())
</script>

<template>
  <HilosAdminPage :page="HilosPages.DAEMON_WORKERS">
    <HilosDaemonNodeLine :node-id="nodeId" :heading="heading" />
    <div class="position-relative">
      <p class="small text-body-secondary invisible" aria-hidden="true">
        This node has been silent since September 30, 2026, 11:59 PM. The
        workers below are from its last report.
      </p>
      <p
        v-if="silentSince !== null && rows.length > 0"
        class="small text-body-secondary position-absolute top-0 start-0 w-100"
        data-id="hilos-daemon-workers-silent"
      >
        This node has been silent since {{ silentSince }}. The workers below are
        from its last report.
      </p>
    </div>
    <HilosViewportTable :controller="workers.controller">
      <template #cell-index="{ row }">
        <code class="fw-semibold">{{
          formatDaemonWorkerName(row.index, row.kind)
        }}</code>
      </template>
      <template #cell-kind="{ row }">
        <span class="small">{{ formatDaemonWorkerKind(row) }}</span>
      </template>
      <template #cell-pid="{ row }"
        ><code>{{ formatDaemonWorkerPid(row) }}</code></template
      >
      <template #cell-agentCount="{ row }">
        <span
          class="d-block text-center"
          :class="{ 'text-body-secondary': row.agentCount === 0 }"
        >
          {{ formatDaemonWorkerAgentCount(row) }}
        </span>
      </template>
      <template #cell-memoryBytes="{ row }">{{
        formatDaemonWorkerMemory(row)
      }}</template>
      <template #cell-actions="{ row }">
        <HilosLink
          :to="daemonWorkerLogPath(nodeId, row)"
          class="btn btn-sm btn-outline-secondary text-nowrap"
          :data-id="`hilos-daemon-worker-log-${row.rowKey}`"
          >Log</HilosLink
        >
      </template>
      <template #detail-agentIds="{ row }">
        <div
          class="d-flex flex-wrap gap-1"
          :data-id="`hilos-daemon-worker-agents-${row.rowKey}`"
        >
          <span
            v-for="id in row.agentIds"
            :key="id"
            class="badge bg-body-tertiary text-body border font-monospace"
            >{{ id }}</span
          >
          <span v-if="row.agentIds.length === 0" class="text-body-secondary"
            >—</span
          >
        </div>
      </template>
      <template #empty>
        <div
          v-if="emptyState === 'waiting'"
          data-id="hilos-daemon-workers-empty-waiting"
        >
          <div class="fw-semibold">
            The node has not reported its workers yet
          </div>
          <p class="mb-0">
            Nothing has arrived from this node yet — not zero workers.
          </p>
        </div>
        <div
          v-else-if="emptyState === 'silent'"
          data-id="hilos-daemon-workers-empty-silent"
        >
          <div class="fw-semibold">
            The node is silent and never reported its workers
          </div>
          <p class="mb-0">Its workers will appear when it reports again.</p>
        </div>
        <div v-else data-id="hilos-daemon-workers-empty-none">
          <div class="fw-semibold">The node reports no workers</div>
        </div>
      </template>
    </HilosViewportTable>
  </HilosAdminPage>
</template>
