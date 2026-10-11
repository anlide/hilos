<!-- Agents of one Daemon node. The page scope carries the node heading and
reported-roster verdict; a server viewport supplies the agent rows. -->
<script setup lang="ts">
import {
  createHilosDaemonAgentsTable,
  daemonAgentLogPath,
  daemonProcessesEmptyState,
  formatDaemonAgentKind,
  formatDaemonAgentOwnership,
  formatDaemonAgentWorker,
  formatDaemonNodeSilentSince,
  HilosPages,
  readDaemonProcessesReported,
  readHilosDaemonNodeHeading,
  type HilosDaemonAgentsContext,
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
  context: HilosDaemonAgentsContext
}>()

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error('HilosDaemonAgentsPage requires a provided Hilos router.')
}

const nodeId =
  (router.currentRoute.get().params.nodeId as string | undefined) ?? ''
const agents = createHilosDaemonAgentsTable(props.context, nodeId)
const heading = useSignal(readHilosDaemonNodeHeading(props.context.scopes))
const reported = useSignal(readDaemonProcessesReported(props.context.scopes))
const rows = useSignal(agents.controller.rows)
const emptyState = computed(() =>
  daemonProcessesEmptyState(heading.value, reported.value),
)
const silentSince = computed(() =>
  formatDaemonNodeSilentSince(heading.value, Date.now()),
)

onMounted(() => agents.start())
onUnmounted(() => agents.dispose())
</script>

<template>
  <HilosAdminPage :page="HilosPages.DAEMON_AGENTS">
    <HilosDaemonNodeLine :node-id="nodeId" :heading="heading" />
    <div class="position-relative">
      <p class="small text-body-secondary invisible" aria-hidden="true">
        This node has been silent since September 30, 2026, 11:59 PM. The agents
        below are from its last report.
      </p>
      <p
        v-if="silentSince !== null && rows.length > 0"
        class="small text-body-secondary position-absolute top-0 start-0 w-100"
        data-id="hilos-daemon-agents-silent"
      >
        This node has been silent since {{ silentSince }}. The agents below are
        from its last report.
      </p>
    </div>
    <HilosViewportTable :controller="agents.controller">
      <template #cell-agentId="{ row }">
        <code class="fw-semibold">{{ row.agentId }}</code>
      </template>
      <template #cell-workerIndex="{ row }">
        <code>{{ formatDaemonAgentWorker(row) }}</code>
      </template>
      <template #cell-placement="{ row }">
        <span class="small">{{
          formatDaemonAgentKind(row, heading?.clustered === true)
        }}</span>
      </template>
      <template #cell-ownsRt="{ row }">
        <code
          class="small"
          :class="{ 'text-body-secondary': row.ownsRt.length === 0 }"
          >{{ formatDaemonAgentOwnership(row.ownsRt) }}</code
        >
      </template>
      <template #cell-ownsDb="{ row }">
        <code
          class="small"
          :class="{ 'text-body-secondary': row.ownsDb.length === 0 }"
          >{{ formatDaemonAgentOwnership(row.ownsDb) }}</code
        >
      </template>
      <template #cell-actions="{ row }">
        <HilosLink
          :to="daemonAgentLogPath(nodeId, row)"
          class="small"
          :data-id="`hilos-daemon-agent-log-${row.rowKey}`"
          >Log</HilosLink
        >
      </template>
      <template #empty>
        <div
          v-if="emptyState === 'waiting'"
          data-id="hilos-daemon-agents-empty-waiting"
        >
          <div class="fw-semibold">
            The node has not reported its agents yet
          </div>
          <p class="mb-0">
            Nothing has arrived from this node yet — not zero agents.
          </p>
        </div>
        <div
          v-else-if="emptyState === 'silent'"
          data-id="hilos-daemon-agents-empty-silent"
        >
          <div class="fw-semibold">
            The node is silent and never reported its agents
          </div>
          <p class="mb-0">Its agents will appear when it reports again.</p>
        </div>
        <div v-else data-id="hilos-daemon-agents-empty-none">
          <div class="fw-semibold">The node reports no agents</div>
        </div>
      </template>
    </HilosViewportTable>
  </HilosAdminPage>
</template>
