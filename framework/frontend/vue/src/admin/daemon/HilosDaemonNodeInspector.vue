<!-- The selected node's last report. Rendering only: no second controller,
subscription, timer or action. -->
<script setup lang="ts">
import {
  daemonCoresBoundHint,
  daemonEnvironmentAttention,
  daemonEnvironmentDifferences,
  daemonMemoryBoundHint,
  daemonMonopolisticCount,
  daemonNodeChildParams,
  daemonNodeLogPath,
  daemonPercentage,
  daemonProgressWidth,
  daemonTotalMemory,
  formatDaemonBytes,
  formatDaemonCores,
  formatDaemonNodeSilentSince,
  formatDaemonNodeState,
  formatDaemonNumber,
  formatDaemonPercentage,
  formatDaemonUptime,
  type HilosDaemonOverviewData,
  type HilosDaemonOverviewNode,
} from '@hilos/core/admin/daemon'
import { HilosPages } from '@hilos/core/base'
import { computed, inject, ref } from 'vue'

import HilosLink from '../../HilosLink.vue'
import HilosLongText from '../../HilosLongText.vue'
import HilosModal from '../../HilosModal.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

const props = defineProps<{
  overview: HilosDaemonOverviewData
  node: HilosDaemonOverviewNode
}>()
const router = inject(hilosRouterKey)
if (!router)
  throw new Error('HilosDaemonNodeInspector requires a provided Hilos router.')

const measurements = computed(() => props.node.measurements)
const cpuPercent = computed(() =>
  daemonPercentage(measurements.value?.daemonCores, measurements.value?.cores),
)
const memory = computed(() => daemonTotalMemory(props.node))
const memoryPercent = computed(() =>
  daemonPercentage(memory.value, measurements.value?.memoryCeiling),
)
const silentSince = computed(() =>
  formatDaemonNodeSilentSince(
    {
      clustered: props.overview.clustered,
      state: props.node.state,
      silentSince: props.node.silentSince,
    },
    Date.now(),
  ),
)
const envPath = computed(() =>
  router.resolvePath(
    HilosPages.DAEMON_ENV,
    daemonNodeChildParams(props.node.nodeId),
  ),
)
const mismatchPath = computed(() =>
  props.overview.clustered
    ? router.resolvePath(HilosPages.DAEMON_ENV_MISMATCH, {})
    : undefined,
)
const logPath = computed(() =>
  daemonNodeLogPath(router.resolvePath, props.node.nodeId),
)
const noticeOpen = ref(false)
const reportNotice = computed(() => {
  if (props.node.state === 'silent') {
    return props.node.role === null
      ? 'Silent · this node has not reported yet.'
      : `Silent · values from the last report. Last arrival: ${silentSince.value ?? '—'}.`
  }
  return props.node.role === null ? 'This node has not reported yet.' : null
})
</script>

<template>
  <section
    class="border border-primary-subtle rounded p-3"
    data-id="hilos-daemon-inspector"
    :data-node-id="node.nodeId"
  >
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
      <i class="bi bi-hdd-network" aria-hidden="true"></i>
      <h2 class="h6 font-monospace text-break mb-0">{{ node.nodeId }}</h2>
      <template v-if="overview.clustered">
        <span
          class="badge border"
          :class="
            node.state === 'silent'
              ? 'bg-danger-subtle text-danger-emphasis border-danger-subtle'
              : 'bg-body-tertiary text-body'
          "
          data-id="hilos-daemon-inspector-state"
          >{{
            node.state === null ? '—' : formatDaemonNodeState(node.state)
          }}</span
        >
        <span class="badge bg-body-tertiary text-body border"
          >Role: {{ node.role ?? '—' }}</span
        >
      </template>
      <span v-else class="badge bg-body-tertiary text-body border"
        >Single installation</span
      >
      <HilosLink
        v-if="logPath"
        :to="logPath"
        class="small ms-auto"
        data-id="hilos-daemon-node-log"
        >Node log <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i
      ></HilosLink>
    </div>

    <div class="hilos-stack mb-3 small text-body-secondary">
      <div
        class="invisible d-flex align-items-center gap-2"
        aria-hidden="true"
        inert
      >
        <span class="flex-grow-1 text-truncate">&nbsp;</span>
        <span class="btn btn-link btn-sm p-0 flex-shrink-0"
          ><i class="bi bi-info-circle" aria-hidden="true"></i
        ></span>
      </div>
      <div
        class="d-flex align-items-center gap-2"
        :class="{ invisible: reportNotice === null }"
        :aria-hidden="reportNotice === null ? true : undefined"
        :inert="reportNotice === null"
        data-id="hilos-daemon-report-notice"
      >
        <span class="flex-grow-1 text-truncate">{{
          reportNotice ?? '\u00a0'
        }}</span>
        <button
          type="button"
          class="btn btn-link btn-sm p-0 flex-shrink-0"
          aria-label="Show report details"
          data-id="hilos-daemon-report-details"
          @click="noticeOpen = true"
        >
          <i class="bi bi-info-circle" aria-hidden="true"></i>
        </button>
      </div>
    </div>

    <h3 class="h6">Processor</h3>
    <div class="row row-cols-2 row-cols-md-4 g-2 mb-3">
      <div class="col">
        <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
          <dt class="small text-body-secondary fw-normal">Node cores</dt>
          <dd class="fs-5 mb-1" data-id="hilos-daemon-cores">
            {{ formatDaemonCores(measurements?.cores) }}
          </dd>
          <dd
            class="small mb-0"
            :title="daemonCoresBoundHint(measurements?.coresBound)"
          >
            {{ daemonCoresBoundHint(measurements?.coresBound) }}
          </dd>
        </dl>
      </div>
      <div class="col">
        <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
          <dt class="small text-body-secondary fw-normal">Daemon CPU</dt>
          <dd class="fs-5 mb-1" data-id="hilos-daemon-daemon-cores">
            {{ formatDaemonCores(measurements?.daemonCores) }}
            <span class="small">cores</span>
          </dd>
          <dd class="small mb-0" data-id="hilos-daemon-cpu-percent">
            {{ formatDaemonPercentage(cpuPercent) }} of available CPU
          </dd>
          <dd class="mb-0">
            <div class="progress hilos-progress-track mt-2" aria-hidden="true">
              <div
                class="progress-bar hilos-progress"
                :style="{ '--hilos-progress': daemonProgressWidth(cpuPercent) }"
              ></div>
            </div>
          </dd>
        </dl>
      </div>
      <div class="col">
        <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
          <dt class="small text-body-secondary fw-normal">Master CPU</dt>
          <dd class="fs-5 mb-1">
            {{ formatDaemonCores(measurements?.masterCores) }}
            <span class="small">cores</span>
          </dd>
          <dd class="small mb-0">Daemon process without workers</dd>
        </dl>
      </div>
      <div class="col">
        <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
          <dt class="small text-body-secondary fw-normal">Machine CPU</dt>
          <dd class="fs-5 mb-1">
            {{ formatDaemonPercentage(measurements?.machineLoad) }}
          </dd>
          <dd class="small mb-0">Whole machine, including other processes</dd>
        </dl>
      </div>
    </div>

    <h3 class="h6">Memory</h3>
    <div class="row row-cols-2 row-cols-md-4 g-2 mb-3">
      <div class="col">
        <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
          <dt class="small text-body-secondary fw-normal">Master</dt>
          <dd class="fs-5 mb-1">
            {{ formatDaemonBytes(measurements?.masterMemory) }}
          </dd>
          <dd class="small mb-0">Daemon process</dd>
        </dl>
      </div>
      <div class="col">
        <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
          <dt class="small text-body-secondary fw-normal">Workers</dt>
          <dd class="fs-5 mb-1">
            {{ formatDaemonBytes(measurements?.workersMemory) }}
          </dd>
          <dd class="small mb-0" data-id="hilos-daemon-workers-measured">
            {{ formatDaemonNumber(measurements?.workersMeasured) }} of
            {{ formatDaemonNumber(node.workers?.length) }} worker processes
            measured
          </dd>
        </dl>
      </div>
      <div class="col">
        <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
          <dt class="small text-body-secondary fw-normal">Measured total</dt>
          <dd class="fs-5 mb-1" data-id="hilos-daemon-total-memory">
            {{ formatDaemonBytes(memory) }}
          </dd>
          <dd class="small mb-0" data-id="hilos-daemon-memory-percent">
            {{ formatDaemonPercentage(memoryPercent) }} of ceiling
          </dd>
          <dd class="mb-0">
            <div class="progress hilos-progress-track mt-2" aria-hidden="true">
              <div
                class="progress-bar hilos-progress"
                :style="{
                  '--hilos-progress': daemonProgressWidth(memoryPercent),
                }"
              ></div>
            </div>
          </dd>
        </dl>
      </div>
      <div class="col">
        <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
          <dt class="small text-body-secondary fw-normal">Ceiling</dt>
          <dd class="fs-5 mb-1">
            {{ formatDaemonBytes(measurements?.memoryCeiling) }}
          </dd>
          <dd
            class="small mb-0"
            :title="daemonMemoryBoundHint(measurements?.memoryCeilingBound)"
          >
            {{ daemonMemoryBoundHint(measurements?.memoryCeilingBound) }}
          </dd>
        </dl>
      </div>
    </div>
    <p class="small text-body-secondary">
      PHP memory limit per process:
      {{ formatDaemonBytes(measurements?.phpMemoryLimit) }}
    </p>

    <h3 class="h6">Node</h3>
    <div class="row row-cols-2 row-cols-md-4 g-2 mb-3">
      <div class="col">
        <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
          <dt class="small text-body-secondary fw-normal">Uptime</dt>
          <dd class="fs-5 mb-1" data-id="hilos-daemon-uptime">
            {{ formatDaemonUptime(node.uptimeMinutes) }}
          </dd>
          <dd class="small mb-0">At the last report</dd>
        </dl>
      </div>
      <div class="col">
        <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
          <dt class="small text-body-secondary fw-normal">Monopolistic</dt>
          <dd class="fs-5 mb-1" data-id="hilos-daemon-monopolistic-count">
            {{ formatDaemonNumber(daemonMonopolisticCount(node)) }}
          </dd>
          <dd class="small mb-0">Pool grows on demand</dd>
        </dl>
      </div>
      <div class="col">
        <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
          <dt class="small text-body-secondary fw-normal">
            Worker replacements / 24 h
          </dt>
          <dd class="fs-5 mb-1">
            {{ formatDaemonNumber(node.workerRestarts24h) }}
          </dd>
          <dd class="small mb-0">After unexpected worker loss</dd>
        </dl>
      </div>
      <div class="col">
        <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
          <dt class="small text-body-secondary fw-normal">Sessions on node</dt>
          <dd class="fs-5 mb-1">
            {{
              node.role === 'slave'
                ? '—'
                : formatDaemonNumber(node.standing?.sessions)
            }}
          </dd>
          <dd class="small mb-0" data-id="hilos-daemon-inspector-connections">
            {{
              node.role === 'slave'
                ? 'Does not accept browsers'
                : `${formatDaemonNumber(node.standing?.connections)} connections`
            }}
          </dd>
        </dl>
      </div>
    </div>

    <h3 class="h6">Environment</h3>
    <div class="row row-cols-2 row-cols-md-4 g-2 mb-3">
      <div class="col">
        <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
          <dt class="small text-body-secondary fw-normal">APP_ENV</dt>
          <dd class="small mb-0 mt-2">
            <HilosLink
              v-if="envPath"
              :to="envPath"
              data-id="hilos-daemon-environment-link"
              >On the environment page</HilosLink
            >
            <span v-else data-id="hilos-daemon-environment-unbuilt"
              >Environment page is not available yet</span
            >
          </dd>
          <dd class="small text-body-secondary mb-0 mt-1">
            Application environment
          </dd>
        </dl>
      </div>
      <div class="col">
        <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
          <dt class="small text-body-secondary fw-normal">Node ID</dt>
          <dd class="small font-monospace text-break mb-0 mt-2">
            {{ node.nodeId }}
          </dd>
        </dl>
      </div>
      <div class="col">
        <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
          <dt class="small text-body-secondary fw-normal">
            Requires attention
          </dt>
          <dd class="fs-5 mb-1" data-id="hilos-daemon-environment-attention">
            {{ formatDaemonNumber(daemonEnvironmentAttention(node)) }}
          </dd>
          <dd class="small mb-0">
            Missing, example, disk drift and orphan flags
          </dd>
        </dl>
      </div>
      <div class="col">
        <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
          <dt class="small text-body-secondary fw-normal">
            Differs from neighbors
          </dt>
          <dd class="fs-5 mb-1" data-id="hilos-daemon-environment-differences">
            {{
              formatDaemonNumber(daemonEnvironmentDifferences(overview, node))
            }}
          </dd>
          <dd class="small mb-0">
            {{
              overview.clustered
                ? 'Diverged values and differing sources'
                : 'No other nodes to compare'
            }}
          </dd>
        </dl>
      </div>
    </div>
    <p v-if="mismatchPath" class="small">
      <HilosLink :to="mismatchPath" data-id="hilos-daemon-mismatch-link"
        >Environment differences</HilosLink
      >
    </p>

    <h3 class="h6">Node maintenance</h3>
    <p
      id="hilos-daemon-maintenance-unavailable"
      class="small text-body-secondary"
    >
      Node maintenance is not available yet.
    </p>
    <div
      class="d-flex flex-column flex-md-row flex-wrap gap-2"
      data-id="hilos-daemon-maintenance"
    >
      <button
        type="button"
        class="btn btn-sm btn-outline-secondary"
        disabled
        aria-describedby="hilos-daemon-maintenance-unavailable"
      >
        Drain node
      </button>
      <button
        type="button"
        class="btn btn-sm btn-outline-secondary"
        disabled
        aria-describedby="hilos-daemon-maintenance-unavailable"
      >
        Reload configuration
      </button>
      <button
        type="button"
        class="btn btn-sm btn-outline-danger"
        disabled
        aria-describedby="hilos-daemon-maintenance-unavailable"
      >
        Force disconnect node
      </button>
    </div>
  </section>
  <HilosModal v-model="noticeOpen" title="Node report" initial-focus="dialog">
    <HilosLongText
      kind="prose"
      :text="reportNotice ?? ''"
      data-id="hilos-daemon-report-full"
    />
    <template #actions="{ requestClose }">
      <button type="button" class="btn btn-secondary" @click="requestClose">
        Close
      </button>
    </template>
  </HilosModal>
</template>
