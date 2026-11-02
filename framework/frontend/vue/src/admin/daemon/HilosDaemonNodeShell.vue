<!-- Shared node entity block and page-owned body. -->
<script setup lang="ts">
import {
  daemonNodeLogPath,
  formatDaemonNodeClockWarning,
  formatDaemonNodeSilentSince,
  formatDaemonNodeState,
  readHilosDaemonNodeHeading,
  readHilosDaemonNodeOverview,
  type HilosDaemonContext,
} from '@hilos/core/admin/daemon'
import {
  bindPageInstanceName,
  computedSignal,
  HilosPages,
  hilosTimeZone,
} from '@hilos/core/base'
import { computed, inject, onUnmounted, ref, watch } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosLink from '../../HilosLink.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{ context: HilosDaemonContext; page: string }>()
const router = inject(hilosRouterKey)
if (!router) {
  throw new Error('HilosDaemonNodeShell requires a provided Hilos router.')
}
const headingSource = readHilosDaemonNodeHeading(props.context.scopes)
const overviewSource = readHilosDaemonNodeOverview(props.context.scopes)
const currentSource = computedSignal(() => {
  const page = props.context.scopes.page()?.key
  const route = router.currentRoute.get()
  const heading = headingSource.get()
  if (
    page !== HilosPages.DAEMON_NODE &&
    page !== HilosPages.DAEMON_WORKERS &&
    page !== HilosPages.DAEMON_AGENTS &&
    page !== HilosPages.DAEMON_CRON &&
    page !== HilosPages.DAEMON_WEBSOCKETS &&
    page !== HilosPages.DAEMON_HTTP_SERVER &&
    page !== HilosPages.DAEMON_ENV &&
    page !== HilosPages.DAEMON_ENV_ORPHANS
  )
    return null
  const nodeId = route.params.nodeId
  if (
    route.page !== page ||
    router.pageError.get() !== null ||
    typeof nodeId !== 'string' ||
    nodeId === '' ||
    heading === null
  )
    return null
  // Overview names its own addressed node; a child uses its permitted heading
  // and the current route, including Environment's own node-agent response.
  const overview = overviewSource.get()
  if (
    page === HilosPages.DAEMON_NODE &&
    overview !== null &&
    overview.nodeId !== nodeId
  ) {
    return null
  }
  return { nodeId, heading }
})
const unbindName = bindPageInstanceName(
  props.context.scopes,
  HilosPages.DAEMON_NODE,
  computedSignal(() => {
    const current = currentSource.get()
    return current?.heading.name ?? current?.nodeId
  }),
)
onUnmounted(unbindName)
const current = useSignal(currentSource)
const heading = computed(() => current.value?.heading)
const clockWarning = computed(() =>
  formatDaemonNodeClockWarning(heading.value?.clock ?? null),
)
const clockScrollport = ref<HTMLElement | null>(null)
const roleBadge = ref<HTMLElement | null>(null)
// Move an active reader before the warning's room becomes hidden and inert.
watch(clockWarning, (warning) => {
  if (warning === null && document.activeElement === clockScrollport.value)
    roleBadge.value?.focus()
})
const diagramAction = computed(() =>
  heading.value?.clustered
    ? {
        page: HilosPages.DAEMON,
        params: {},
        label: 'Change on the diagram',
      }
    : undefined,
)
const logPath = computed(() =>
  current.value
    ? daemonNodeLogPath(router.resolvePath, current.value.nodeId)
    : undefined,
)
const timeZone = useSignal(hilosTimeZone)
const silentSince = computed(() => {
  void timeZone.value
  return formatDaemonNodeSilentSince(heading.value ?? null, Date.now())
})

/** @param state The backend's cluster verdict, independent of the declared role. */
function stateClass(state: string): string {
  switch (state) {
    case 'leader':
      return 'bg-warning-subtle text-warning-emphasis border-warning-subtle'
    case 'standby':
      return 'bg-secondary-subtle text-secondary-emphasis border-secondary-subtle'
    case 'data':
      return 'bg-info-subtle text-info-emphasis border-info-subtle'
    case 'silent':
      return 'bg-danger-subtle text-danger-emphasis border-danger-subtle'
    default:
      return 'bg-body-tertiary text-body'
  }
}
</script>

<template>
  <HilosAdminPage
    :page="page"
    head-kind="entity"
    breadcrumb-label="Breadcrumb"
    tabs-label="Node pages"
    :head-action="diagramAction"
  >
    <template v-if="current" #head-visual>
      <span
        class="bg-body-secondary rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0 p-3 fs-3 lh-1"
        aria-hidden="true"
        data-id="hilos-daemon-node-visual"
      >
        <i
          class="bi"
          :class="
            heading?.clustered && heading.state === 'silent'
              ? 'bi-plug text-danger'
              : 'bi-hdd-network'
          "
        ></i>
      </span>
    </template>
    <template v-if="heading" #head-badges>
      <span
        v-if="heading.name !== null"
        class="font-monospace text-body-secondary small text-break"
        >{{ current?.nodeId }}</span
      >
      <template v-if="heading.clustered">
        <span
          v-if="heading.state !== null"
          class="badge border"
          :class="stateClass(heading.state)"
          data-id="hilos-daemon-node-state"
          >{{ formatDaemonNodeState(heading.state) }}</span
        >
        <span
          ref="roleBadge"
          class="badge bg-body-tertiary text-body border"
          data-id="hilos-daemon-node-role"
          tabindex="-1"
          >Role: {{ heading.role ?? '—' }}</span
        >
        <!-- A full-width, single-line room keeps the head below the badges
        still when the warning arrives, clears or needs horizontal scrolling. -->
        <span
          ref="clockScrollport"
          class="d-block w-100 overflow-x-scroll text-nowrap"
          :tabindex="clockWarning !== null ? 0 : undefined"
          :role="clockWarning !== null ? 'region' : undefined"
          :aria-label="clockWarning !== null ? 'Node clock warning' : undefined"
          :aria-hidden="clockWarning === null ? 'true' : undefined"
          :inert="clockWarning === null"
        >
          <span
            v-if="clockWarning !== null"
            class="badge text-bg-warning"
            data-id="hilos-daemon-node-clock-warning"
          >
            <i
              class="bi bi-exclamation-triangle-fill me-1"
              aria-hidden="true"
            ></i
            >{{ clockWarning }}
          </span>
          <span
            v-else
            class="badge text-bg-warning invisible"
            aria-hidden="true"
            inert
          >
            <i
              class="bi bi-exclamation-triangle-fill me-1"
              aria-hidden="true"
            ></i
            >&nbsp;
          </span>
        </span>
      </template>
      <span
        v-else
        class="badge bg-body-tertiary text-body border"
        data-id="hilos-daemon-node-solo"
        >Single installation</span
      >
    </template>
    <template v-if="current" #head-description>
      <p
        class="small text-body-secondary mb-0 d-flex flex-wrap gap-3"
        data-id="hilos-daemon-node-description"
      >
        <span
          v-if="heading?.clustered && silentSince"
          data-id="hilos-daemon-node-silent"
          >Silent since {{ silentSince }}</span
        >
        <HilosLink v-if="logPath" :to="logPath" data-id="hilos-daemon-node-log"
          >Node log <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i
        ></HilosLink>
      </p>
    </template>
    <slot />
  </HilosAdminPage>
</template>
