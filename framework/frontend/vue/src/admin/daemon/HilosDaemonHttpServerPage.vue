<!-- HTTP listener and counters of one Daemon node. The page scope carries the
summary; the existing server viewport supplies its route rows. -->
<script setup lang="ts">
import {
  createHilosDaemonHttpRoutesTable,
  daemonHttpEmptyState,
  DAEMON_NODE_STATE_SILENT,
  formatDaemonHttpAddress,
  formatDaemonHttpCount,
  formatDaemonHttpPeriod,
  formatDaemonHttpRoute,
  formatDaemonHttpSlowest,
  formatDaemonNodeSilentSince,
  readHilosDaemonHttpSummary,
  readHilosDaemonNodeHeading,
  type HilosDaemonHttpContext,
} from '@hilos/core/admin/daemon'
import { HilosPages, hilosTimeZone } from '@hilos/core/base'
import {
  computed,
  inject,
  onMounted,
  onUnmounted,
  shallowRef,
  watch,
} from 'vue'

import HilosViewportTable from '../../HilosViewportTable.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'
import HilosDaemonNodeShell from './HilosDaemonNodeShell.vue'

const props = defineProps<{
  /** The project's connection and page scope. */
  context: HilosDaemonHttpContext
}>()

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error('HilosDaemonHttpServerPage requires a provided Hilos router.')
}

const route = useSignal(router.currentRoute)
const nodeId = computed(
  () => (route.value.params.nodeId as string | undefined) ?? '',
)
const routes = shallowRef(
  createHilosDaemonHttpRoutesTable(props.context, nodeId.value),
)
const heading = useSignal(readHilosDaemonNodeHeading(props.context.scopes))
const summary = useSignal(readHilosDaemonHttpSummary(props.context.scopes))
const emptyState = computed(() =>
  daemonHttpEmptyState(heading.value, summary.value),
)
const silent = computed(() => heading.value?.state === DAEMON_NODE_STATE_SILENT)
const timeZone = useSignal(hilosTimeZone)
const silentSince = computed(() => {
  void timeZone.value
  return formatDaemonNodeSilentSince(heading.value, Date.now())
})
const period = computed(() =>
  summary.value === null ? '—' : formatDaemonHttpPeriod(summary.value),
)
const status = computed(() =>
  emptyState.value !== 'none'
    ? emptyState.value
    : silent.value
      ? 'last-report'
      : null,
)
const waitingMessage = 'The node has not reported HTTP metrics yet'
const silentMessage = 'The node is silent and has not reported HTTP metrics'
const lastReportReserve =
  'This node has been silent since September 30, 2026, 11:59 PM. The values below are from its last report.'
const lastReportMessage = computed(
  () =>
    `This node has been silent since ${silentSince.value ?? '—'}. The values below are from its last report.`,
)
const statusMessage = computed(() =>
  status.value === 'waiting'
    ? waitingMessage
    : status.value === 'silent'
      ? silentMessage
      : status.value === 'last-report'
        ? lastReportMessage.value
        : '',
)

let mounted = false
watch(
  nodeId,
  (nextNodeId) => {
    routes.value.dispose()
    routes.value = createHilosDaemonHttpRoutesTable(props.context, nextNodeId)
    if (mounted) {
      routes.value.start()
    }
  },
  { flush: 'sync' },
)

onMounted(() => {
  mounted = true
  routes.value.start()
})
onUnmounted(() => {
  mounted = false
  routes.value.dispose()
})
</script>

<template>
  <HilosDaemonNodeShell
    :context="context"
    :page="HilosPages.DAEMON_HTTP_SERVER"
  >
    <!-- The twins share the message's grid cell and reserve its tallest state.
    They carry no stable selector or accessible name. -->
    <div class="hilos-stack mb-3">
      <p class="small text-body-secondary mb-0 invisible" aria-hidden="true">
        {{ waitingMessage }}
      </p>
      <p class="small text-body-secondary mb-0 invisible" aria-hidden="true">
        {{ silentMessage }}
      </p>
      <p class="small text-body-secondary mb-0 invisible" aria-hidden="true">
        {{ lastReportReserve }}
      </p>
      <p
        v-if="status !== null"
        class="small text-body-secondary mb-0"
        data-id="hilos-daemon-http-status"
        :data-status="status"
      >
        {{ statusMessage }}
      </p>
    </div>
    <div class="row row-cols-2 row-cols-md-4 g-2 mb-3">
      <div class="col">
        <div class="border rounded-3 p-3 h-100">
          <div class="d-flex align-items-center gap-2 text-body-secondary mb-1">
            <i class="bi bi-globe" aria-hidden="true"></i
            ><span class="small">Address</span>
          </div>
          <div
            class="fs-6 font-monospace lh-1 mb-1 text-break"
            data-id="hilos-daemon-http-address"
          >
            {{ summary === null ? '—' : formatDaemonHttpAddress(summary) }}
          </div>
          <div class="small text-body-secondary">
            {{ summary === null ? '—' : silent ? 'Last report' : 'Listening' }}
          </div>
        </div>
      </div>
      <div class="col">
        <div class="border rounded-3 p-3 h-100">
          <div class="d-flex align-items-center gap-2 text-body-secondary mb-1">
            <i class="bi bi-arrow-down-up" aria-hidden="true"></i
            ><span class="small">Requests</span>
          </div>
          <div
            class="fs-5 lh-1 mb-1 text-break"
            data-id="hilos-daemon-http-requests"
          >
            {{
              summary === null ? '—' : formatDaemonHttpCount(summary.requests)
            }}
          </div>
          <div
            class="small text-body-secondary"
            data-id="hilos-daemon-http-period"
          >
            {{ period }}
          </div>
        </div>
      </div>
      <div class="col">
        <div class="border rounded-3 p-3 h-100">
          <div class="d-flex align-items-center gap-2 text-body-secondary mb-1">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i
            ><span class="small">Errors</span>
          </div>
          <div
            class="fs-5 lh-1 mb-1 text-break"
            data-id="hilos-daemon-http-errors"
          >
            {{
              summary === null
                ? '—'
                : formatDaemonHttpCount(summary.serverErrors)
            }}
          </div>
          <div class="small text-body-secondary">{{ '5xx · ' + period }}</div>
        </div>
      </div>
      <div class="col">
        <div class="border rounded-3 p-3 h-100">
          <div class="d-flex align-items-center gap-2 text-body-secondary mb-1">
            <i class="bi bi-hourglass-split" aria-hidden="true"></i
            ><span class="small">Slow</span>
          </div>
          <div
            class="fs-5 lh-1 mb-1 text-break"
            data-id="hilos-daemon-http-slow"
          >
            {{ summary === null ? '—' : formatDaemonHttpCount(summary.slow) }}
          </div>
          <div class="small text-body-secondary">
            {{ 'Over 1 s · ' + period }}
          </div>
        </div>
      </div>
    </div>
    <HilosViewportTable :key="nodeId" :controller="routes.controller">
      <template #cell-path="{ row }">
        <code>{{ formatDaemonHttpRoute(row) }}</code>
        <template v-if="row.agentType !== null">
          <span class="small text-body-secondary ms-1">agent</span>
          <span
            class="badge bg-body-tertiary text-body border font-monospace ms-1"
            >{{ row.agentType }}</span
          >
        </template>
      </template>
      <template #cell-requests="{ row }">
        <span :class="{ 'text-body-secondary': row.requests === 0 }">{{
          formatDaemonHttpCount(row.requests)
        }}</span>
      </template>
      <template #cell-serverErrors="{ row }">
        <span :class="{ 'text-body-secondary': row.serverErrors === 0 }">{{
          formatDaemonHttpCount(row.serverErrors)
        }}</span>
      </template>
      <template #cell-slowestMs="{ row }">
        <span
          class="small"
          :class="{ 'text-body-secondary': row.slowestMs === null }"
          >{{ formatDaemonHttpSlowest(row) }}</span
        >
      </template>
      <template #empty>
        <div v-if="emptyState === 'waiting'">{{ waitingMessage }}</div>
        <div v-else-if="emptyState === 'silent'">{{ silentMessage }}</div>
        <div v-else>Loading…</div>
      </template>
    </HilosViewportTable>
  </HilosDaemonNodeShell>
</template>
