<!-- Environment mismatch between nodes of the cluster. Displays standalone notice,
waiting indicator, or comparison summary with tiles and verdict tables. -->
<script setup lang="ts">
import {
  computedSignal,
  createHilosDaemonEnvMismatchTables,
  daemonEnvMismatchEmptyState,
  daemonEnvMismatchNodeColumnKey,
  daemonEnvMismatchNodes,
  DAEMON_ENV_SENSITIVE_HINT,
  HilosPages,
  readHilosDaemonEnvMismatchSummary,
  type HilosDaemonEnvMismatchContext,
} from '@hilos/core'
import { computed, onMounted, onUnmounted, watch } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosSkeleton from '../../HilosSkeleton.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { useSignal } from '../../useSignal.js'
import HilosDaemonEnvMismatchCell from './HilosDaemonEnvMismatchCell.vue'

const props = defineProps<{
  /** Connection and page scope used by the environment mismatch tables. */
  context: HilosDaemonEnvMismatchContext
}>()

const summarySignal = readHilosDaemonEnvMismatchSummary(props.context.scopes)
const summary = useSignal(summarySignal)
const tables = createHilosDaemonEnvMismatchTables(
  props.context,
  computedSignal(() => daemonEnvMismatchNodes(summarySignal.get())),
)

const nodes = computed(() => daemonEnvMismatchNodes(summary.value))
const comparison = computed(() => summary.value?.comparison ?? null)
const emptyState = computed(() =>
  comparison.value !== null
    ? daemonEnvMismatchEmptyState(comparison.value)
    : null,
)

let started = false
function ensureStarted(): void {
  if (!started && summary.value?.clustered === true) {
    started = true
    tables.start()
  }
}

watch(
  () => summary.value?.clustered,
  (clustered) => {
    if (clustered === true) {
      ensureStarted()
    }
  },
)

onMounted(() => {
  ensureStarted()
})

onUnmounted(() => {
  tables.dispose()
})

function nodeSlot(nodeId: string): string {
  return 'cell-' + daemonEnvMismatchNodeColumnKey(nodeId)
}
</script>

<template>
  <HilosAdminPage :page="HilosPages.DAEMON_ENV_MISMATCH">
    <div
      v-if="summary !== null && !summary.clustered"
      class="alert alert-secondary d-flex align-items-start gap-3 py-3"
      data-id="hilos-daemon-env-mismatch-standalone"
    >
      <i class="bi bi-slash-circle fs-4" aria-hidden="true"></i>
      <div>
        <div class="fw-semibold mb-1">
          This page does not exist on a single-node installation
        </div>
        <p class="small mb-0">
          There is one node,
          <code data-id="hilos-daemon-env-mismatch-standalone-node">{{
            summary.servingNodeId
          }}</code
          >, and nothing to compare it with.
        </p>
      </div>
    </div>

    <div
      v-else-if="summary === null || comparison === null"
      data-id="hilos-daemon-env-mismatch-waiting"
    >
      <p class="text-body-secondary">Waiting for the cluster picture…</p>
      <HilosSkeleton />
    </div>

    <template v-else>
      <div class="row row-cols-2 row-cols-md-4 g-2 mb-3">
        <div class="col">
          <div
            class="border rounded-3 p-3 h-100"
            :class="[
              comparison.answering.length < nodes.length
                ? 'border-warning'
                : '',
            ]"
            data-id="hilos-daemon-env-mismatch-tile-answering"
            :data-tone="
              comparison.answering.length < nodes.length ? 'warning' : ''
            "
          >
            <div
              class="d-flex align-items-center gap-2 text-body-secondary mb-1"
            >
              <i class="bi bi-hdd-network" aria-hidden="true"></i
              ><span class="small">Nodes answering</span>
            </div>
            <div
              class="fs-5 lh-1 mb-1"
              :class="[
                comparison.answering.length < nodes.length
                  ? 'text-warning'
                  : '',
              ]"
            >
              {{ comparison.answering.length }} of {{ nodes.length }}
            </div>
            <div class="small text-body-secondary">Silent nodes show as ?</div>
          </div>
        </div>

        <div class="col">
          <div
            class="border rounded-3 p-3 h-100"
            :class="[
              comparison.diverged > 0 ? 'border-danger' : 'border-success',
            ]"
            data-id="hilos-daemon-env-mismatch-tile-diverged"
            :data-tone="comparison.diverged > 0 ? 'danger' : 'success'"
          >
            <div
              class="d-flex align-items-center gap-2 text-body-secondary mb-1"
            >
              <i class="bi bi-exclamation-octagon" aria-hidden="true"></i
              ><span class="small">Diverged</span>
            </div>
            <div
              class="fs-5 lh-1 mb-1"
              :class="[
                comparison.diverged > 0 ? 'text-danger' : 'text-success',
              ]"
            >
              {{ comparison.diverged }}
            </div>
            <div class="small text-body-secondary">
              Nodes report different values
            </div>
          </div>
        </div>

        <div class="col">
          <div
            class="border rounded-3 p-3 h-100"
            :class="[
              comparison.sourcesDiffer > 0
                ? 'border-warning'
                : 'border-success',
            ]"
            data-id="hilos-daemon-env-mismatch-tile-sources"
            :data-tone="comparison.sourcesDiffer > 0 ? 'warning' : 'success'"
          >
            <div
              class="d-flex align-items-center gap-2 text-body-secondary mb-1"
            >
              <i class="bi bi-node-plus" aria-hidden="true"></i
              ><span class="small">Different sources</span>
            </div>
            <div
              class="fs-5 lh-1 mb-1"
              :class="[
                comparison.sourcesDiffer > 0 ? 'text-warning' : 'text-success',
              ]"
            >
              {{ comparison.sourcesDiffer }}
            </div>
            <div class="small text-body-secondary">
              Same value from different sources
            </div>
          </div>
        </div>

        <div class="col">
          <div
            class="border rounded-3 p-3 h-100"
            data-id="hilos-daemon-env-mismatch-tile-per-node"
            data-tone=""
          >
            <div
              class="d-flex align-items-center gap-2 text-body-secondary mb-1"
            >
              <i class="bi bi-check-circle" aria-hidden="true"></i
              ><span class="small">Differ by design</span>
            </div>
            <div class="fs-5 lh-1 mb-1">
              {{ comparison.perNode.length }}
            </div>
            <div class="small text-body-secondary">
              Each node has its own; that is normal
            </div>
          </div>
        </div>
      </div>

      <HilosViewportTable :controller="tables.diverged">
        <template #cell-key="{ row }">
          <code class="fw-semibold">{{ row.key }}</code>
          <template v-if="row.sensitive">
            <i
              class="bi bi-shield-lock text-body-secondary ms-1"
              :title="DAEMON_ENV_SENSITIVE_HINT"
              aria-hidden="true"
            ></i>
            <span class="visually-hidden">{{ DAEMON_ENV_SENSITIVE_HINT }}</span>
          </template>
        </template>
        <template
          v-for="node in nodes"
          :key="node.nodeId"
          #[nodeSlot(node.nodeId)]="{ row }"
        >
          <HilosDaemonEnvMismatchCell
            :row="row"
            :node-id="node.nodeId"
            :serving-node-id="summary.servingNodeId"
            :values-match="false"
          />
        </template>
        <template #empty>
          <div
            v-if="emptyState === 'alone'"
            data-id="hilos-daemon-env-mismatch-empty-alone"
          >
            <div class="fw-semibold">
              Fewer than two nodes answer, so there is nothing to compare yet.
            </div>
          </div>
          <div
            v-else-if="emptyState === 'agree'"
            data-id="hilos-daemon-env-mismatch-empty-agree"
          >
            <div class="fw-semibold">
              The answering nodes agree on every key they share.
            </div>
          </div>
        </template>
      </HilosViewportTable>

      <template v-if="comparison.sourcesDiffer > 0">
        <div class="d-flex flex-wrap align-items-baseline gap-2 mb-2 mt-4">
          <h2 class="h6 text-uppercase text-body-secondary mb-0">
            Values match, sources differ
          </h2>
        </div>
        <HilosViewportTable :controller="tables.sourcesDiffer">
          <template #cell-key="{ row }">
            <code class="fw-semibold">{{ row.key }}</code>
            <template v-if="row.sensitive">
              <i
                class="bi bi-shield-lock text-body-secondary ms-1"
                :title="DAEMON_ENV_SENSITIVE_HINT"
                aria-hidden="true"
              ></i>
              <span class="visually-hidden">{{
                DAEMON_ENV_SENSITIVE_HINT
              }}</span>
            </template>
          </template>
          <template
            v-for="node in nodes"
            :key="node.nodeId"
            #[nodeSlot(node.nodeId)]="{ row }"
          >
            <HilosDaemonEnvMismatchCell
              :row="row"
              :node-id="node.nodeId"
              :serving-node-id="summary.servingNodeId"
              :values-match="true"
            />
          </template>
        </HilosViewportTable>
      </template>

      <template v-if="comparison.perNode.length > 0">
        <div class="d-flex flex-wrap align-items-baseline gap-2 mb-2 mt-4">
          <h2 class="h6 text-uppercase text-body-secondary mb-0">
            Differ by design
          </h2>
        </div>
        <div
          class="d-flex flex-wrap gap-1 mb-3"
          data-id="hilos-daemon-env-mismatch-per-node"
        >
          <span
            v-for="key in comparison.perNode"
            :key="key"
            class="badge bg-body-tertiary text-body border font-monospace"
            :data-id="`hilos-daemon-env-mismatch-per-node-${key}`"
          >
            {{ key }}
          </span>
        </div>
      </template>
    </template>
  </HilosAdminPage>
</template>
