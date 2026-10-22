<!-- Agents of one Daemon node. The page scope carries the node heading and
reported-roster verdict; a server viewport supplies the agent rows. -->
<script setup lang="ts">
import {
  createHilosDaemonAgentRestart,
  createHilosDaemonAgentsTable,
  DAEMON_AGENT_PLACEMENT_POLICY,
  daemonAgentLogPath,
  daemonProcessesEmptyState,
  formatDaemonAgentKind,
  formatDaemonAgentOwnership,
  formatDaemonAgentWorker,
  formatDaemonNodeSilentSince,
  readDaemonProcessesReported,
  readHilosDaemonNodeHeading,
  type HilosDaemonAgentRow,
  type HilosDaemonAgentsContext,
} from '@hilos/core/admin/daemon'
import { HilosPages } from '@hilos/core/base'
import { computed, inject, onMounted, onUnmounted, ref } from 'vue'

import HilosActionError from '../../HilosActionError.vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosLink from '../../HilosLink.vue'
import HilosModal from '../../HilosModal.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import LoadingButton from '../../LoadingButton.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'
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
const tableBody = useSignal(agents.controller.frame.body)
const frozenSince = useSignal(agents.controller.frozenSince)
const restart = createHilosDaemonAgentRestart(
  props.context,
  agents.controller,
  nodeId,
)
const restartOpen = useSignal(restart.opened)
const restartTarget = useSignal(restart.target)
const canRestart = useSignal(restart.canSubmit)
const restartAction = useTrackedAction()
const { busy: restartBusy } = restartAction
const restartIdentity = ref<HilosDaemonAgentRow | null>(null)
const restartUnavailable = computed(
  () =>
    restartTarget.value === undefined ||
    restartTarget.value.restarting ||
    heading.value === null ||
    heading.value.state === 'silent' ||
    tableBody.value === 'loading' ||
    tableBody.value === 'unavailable' ||
    frozenSince.value !== null,
)
const policyWarning = computed(
  () =>
    heading.value?.clustered === true &&
    restartTarget.value?.placement === DAEMON_AGENT_PLACEMENT_POLICY,
)
const restartCommand = computed(
  () =>
    `php cli.php agent:restart ${restartIdentity.value?.agentId ?? ''}` +
    (heading.value?.clustered === true ? ` --node=${nodeId}` : ''),
)
const emptyState = computed(() =>
  daemonProcessesEmptyState(heading.value, reported.value),
)
const silentSince = computed(() =>
  formatDaemonNodeSilentSince(heading.value, Date.now()),
)

onMounted(() => agents.start())
onUnmounted(() => {
  restart.close()
  agents.dispose()
})

function openRestart(row: HilosDaemonAgentRow): void {
  if (
    restartBusy.value ||
    row.restarting ||
    heading.value === null ||
    heading.value.state === 'silent' ||
    tableBody.value === 'loading' ||
    frozenSince.value !== null
  )
    return
  restart.open(row.rowKey)
  if (restart.opened.get()) {
    restartIdentity.value = restart.target.get() ?? null
    restartAction.clearError()
  }
}

function closeRestart(): void {
  if (!restartBusy.value) restart.close()
}

async function submitRestart(): Promise<void> {
  if (!canRestart.value || restartBusy.value) return
  if (await restartAction.run(restart.sendRestart())) closeRestart()
}
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
        <span
          v-if="row.restarting"
          class="badge text-bg-warning ms-2"
          :data-id="`hilos-daemon-agent-restarting-${row.rowKey}`"
          >Restarting</span
        >
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
          class="small me-2"
          :data-id="`hilos-daemon-agent-log-${row.rowKey}`"
          >Log</HilosLink
        >
        <LoadingButton
          class="btn-sm btn-outline-secondary"
          :disabled="
            restartBusy ||
            row.restarting ||
            heading === null ||
            heading.state === 'silent' ||
            tableBody === 'loading' ||
            frozenSince !== null
          "
          :data-id="`hilos-daemon-agent-restart-${row.rowKey}`"
          @click="openRestart(row)"
          >Restart</LoadingButton
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
    <HilosModal
      :model-value="restartOpen"
      title="Restart agent"
      :busy="restartBusy"
      initial-focus="dialog"
      @update:model-value="closeRestart"
    >
      <HilosActionError
        :action="restartAction"
        details-title="Couldn't restart the agent"
      />
      <p>
        Agent
        <code data-id="hilos-daemon-restart-agent">{{
          restartIdentity?.agentId
        }}</code>
        on node <code data-id="hilos-daemon-restart-node">{{ nodeId }}</code>
        will be stopped and will immediately start again under its placement
        rules.
      </p>
      <p class="text-body-secondary">
        Neighboring agents on the same worker are unaffected.
      </p>
      <p
        v-if="policyWarning"
        class="alert alert-warning"
        data-id="hilos-daemon-restart-policy"
      >
        This policy-placed agent may come back on a different node.
      </p>
      <div class="border rounded p-2 bg-body-tertiary">
        <div class="small text-body-secondary mb-1">
          The same from a terminal:
        </div>
        <code class="text-break" data-id="hilos-daemon-restart-cli">{{
          restartCommand
        }}</code>
      </div>
      <p
        class="small text-body-secondary mt-2 mb-0"
        :class="{ invisible: !restartUnavailable }"
        :aria-hidden="!restartUnavailable"
        data-id="hilos-daemon-restart-unavailable"
      >
        This agent has disappeared or is currently unavailable for restart.
      </p>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="restartBusy"
          data-id="hilos-daemon-restart-cancel"
          @click="requestClose"
        >
          Cancel
        </button>
        <LoadingButton
          class="btn-primary"
          :loading="restartBusy"
          :disabled="restartUnavailable"
          data-id="hilos-daemon-restart-confirm"
          @click="submitRestart"
          >Restart</LoadingButton
        >
      </template>
    </HilosModal>
  </HilosAdminPage>
</template>
