<!-- Cron rules of one Daemon node. The page scope carries the node heading and
reported-schedule verdict; a server viewport supplies the rule rows. -->
<script setup lang="ts">
import {
  createHilosDaemonCronTable,
  daemonCronEmptyState,
  daemonCronHoldsIdleNotice,
  daemonCronIdleNotice,
  formatDaemonCronExecutor,
  formatDaemonCronLastRun,
  formatDaemonCronNextRun,
  formatDaemonNodeSilentSince,
  readDaemonCronReported,
  readHilosDaemonNodeHeading,
  type HilosDaemonCronContext,
} from '@hilos/core/admin/daemon'
import { HilosPages, hilosTimeZone } from '@hilos/core/base'
import { computed, inject, onMounted, onUnmounted, ref } from 'vue'

import HilosViewportTable from '../../HilosViewportTable.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'
import HilosDaemonNodeShell from './HilosDaemonNodeShell.vue'

const props = defineProps<{
  /** The project's connection and page scope. */
  context: HilosDaemonCronContext
}>()

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error('HilosDaemonCronPage requires a provided Hilos router.')
}

const nodeId =
  (router.currentRoute.get().params.nodeId as string | undefined) ?? ''
const cron = createHilosDaemonCronTable(props.context, nodeId)
const heading = useSignal(readHilosDaemonNodeHeading(props.context.scopes))
const reported = useSignal(readDaemonCronReported(props.context.scopes))
const rows = useSignal(cron.controller.rows)
const rules = computed(() =>
  rows.value.flatMap(({ row }) => (row === null ? [] : [row])),
)
const emptyState = computed(() =>
  daemonCronEmptyState(heading.value, reported.value),
)
const notice = computed(() => daemonCronIdleNotice(rules.value, heading.value))
const holdsNotice = computed(() =>
  daemonCronHoldsIdleNotice(rules.value, heading.value),
)
const timeZone = useSignal(hilosTimeZone)
const silentSince = computed(() => {
  void timeZone.value
  return formatDaemonNodeSilentSince(heading.value, Date.now())
})
const clustered = computed(() => heading.value?.clustered === true)
const runTimes = computed(() => {
  void timeZone.value
  const now = Date.now()
  return new Map(
    rules.value.map((row) => [
      row.rowKey,
      {
        lastRun: formatDaemonCronLastRun(row, now),
        nextRun: formatDaemonCronNextRun(row, now),
      },
    ]),
  )
})
const projectTimeZone = ref(props.context.connection.projectTimeZone)
const stopHandshake = props.context.connection.on('handshake', () => {
  projectTimeZone.value = props.context.connection.projectTimeZone
})

onMounted(() => {
  projectTimeZone.value = props.context.connection.projectTimeZone
  cron.start()
})
onUnmounted(() => {
  stopHandshake()
  cron.dispose()
})
</script>

<template>
  <HilosDaemonNodeShell :context="context" :page="HilosPages.DAEMON_CRON">
    <div class="position-relative">
      <p class="small text-body-secondary invisible" aria-hidden="true">
        This node has been silent since September 30, 2026, 11:59 PM. The rules
        below are from its last report.
      </p>
      <p
        v-if="silentSince !== null && rows.length > 0"
        class="small text-body-secondary position-absolute top-0 start-0 w-100"
        data-id="hilos-daemon-cron-silent"
      >
        This node has been silent since {{ silentSince }}. The rules below are
        from its last report.
      </p>
    </div>
    <div v-if="holdsNotice" class="position-relative">
      <div
        class="alert alert-warning d-flex align-items-start gap-3 py-2 small invisible"
        aria-hidden="true"
      >
        <i class="bi bi-pause-circle fs-5" aria-hidden="true"></i>
        <div>
          Daemon rules do not run on this node: only the leader runs them. They
          will start here if leadership moves to this node.
        </div>
      </div>
      <div
        v-if="notice !== 'none'"
        class="alert alert-warning d-flex align-items-start gap-3 py-2 small position-absolute top-0 start-0 w-100"
        data-id="hilos-daemon-cron-not-leader"
        :data-notice="notice"
      >
        <i class="bi bi-pause-circle fs-5" aria-hidden="true"></i>
        <div>
          Daemon rules do not run on this node: only the leader runs
          them.<template v-if="notice === 'idle_until_leader'">
            They will start here if leadership moves to this node.</template
          >
        </div>
      </div>
    </div>
    <p
      class="small text-body-secondary mb-2"
      data-id="hilos-daemon-cron-time-zone"
    >
      <i class="bi bi-clock me-1" aria-hidden="true"></i>Expressions are
      evaluated in the project's time zone —
      <span
        class="font-monospace text-break"
        data-id="hilos-daemon-cron-project-time-zone"
        >{{ projectTimeZone ?? '—' }}</span
      >. Run times are shown in your time zone.
    </p>
    <HilosViewportTable :controller="cron.controller">
      <template #cell-name="{ row }">
        <code class="fw-semibold text-break">{{ row.name }}</code>
      </template>
      <template #cell-agentId="{ row }">
        <span
          v-if="row.agentId === null"
          class="badge bg-body-tertiary text-body border text-wrap text-break"
          :data-id="`hilos-daemon-cron-executor-${row.rowKey}`"
          ><i class="bi bi-cpu me-1" aria-hidden="true"></i
          >{{ formatDaemonCronExecutor(row, clustered) }}</span
        >
        <span
          v-else
          class="badge bg-body-tertiary text-body border font-monospace text-wrap text-break"
          :data-id="`hilos-daemon-cron-executor-${row.rowKey}`"
          >{{ formatDaemonCronExecutor(row, clustered) }}</span
        >
      </template>
      <template #cell-expression="{ row }">
        <code>{{ row.expression }}</code>
      </template>
      <template #cell-lastRunAt="{ row }">
        <span
          class="small"
          :class="{
            'text-body-secondary':
              row.idleReason !== null || row.lastRunAt === null,
          }"
          >{{ runTimes.get(row.rowKey)?.lastRun ?? '—' }}</span
        >
      </template>
      <template #cell-nextRunAt="{ row }">
        <span
          class="small"
          :class="{
            'text-body-secondary':
              row.idleReason !== null || row.nextRunAt === null,
          }"
          >{{ runTimes.get(row.rowKey)?.nextRun ?? '—' }}</span
        >
      </template>
      <template #empty>
        <div
          v-if="emptyState === 'waiting'"
          data-id="hilos-daemon-cron-empty-waiting"
        >
          <div class="fw-semibold">
            The node has not reported its schedule yet
          </div>
          <p class="mb-0">
            Nothing has arrived from this node yet — not zero rules.
          </p>
        </div>
        <div
          v-else-if="emptyState === 'silent'"
          data-id="hilos-daemon-cron-empty-silent"
        >
          <div class="fw-semibold">
            The node is silent and never reported its schedule
          </div>
          <p class="mb-0">Its rules will appear when it reports again.</p>
        </div>
        <div v-else data-id="hilos-daemon-cron-empty-none">
          <div class="fw-semibold">The node reports no cron rules</div>
        </div>
      </template>
    </HilosViewportTable>
  </HilosDaemonNodeShell>
</template>
