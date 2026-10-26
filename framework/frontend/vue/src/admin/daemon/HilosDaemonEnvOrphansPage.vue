<!-- Uncataloged .env lines of one Daemon node. The page scope carries the node
heading and silence; the server viewport supplies the orphan rows. The table
has no title of its own: its accessible name is the page heading. -->
<script setup lang="ts">
import {
  createHilosDaemonEnvOrphansTable,
  formatDaemonEnvSimilarTo,
  formatDaemonEnvValue,
  readHilosDaemonEnvOrphansPage,
  type HilosDaemonEnvContext,
} from '@hilos/core/admin/daemon'
import { HilosPages } from '@hilos/core/base'
import {
  computed,
  inject,
  onMounted,
  onUnmounted,
  shallowRef,
  watch,
} from 'vue'

import HilosHiddenMark from '../../HilosHiddenMark.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'
import HilosDaemonNodeShell from './HilosDaemonNodeShell.vue'

const props = defineProps<{
  /** The project's connection and page scope. */
  context: HilosDaemonEnvContext
}>()

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error('HilosDaemonEnvOrphansPage requires a provided Hilos router.')
}

const route = useSignal(router.currentRoute)
const nodeId = computed(
  () => (route.value.params.nodeId as string | undefined) ?? '',
)

const orphans = shallowRef(
  createHilosDaemonEnvOrphansTable(props.context, nodeId.value),
)

const page = useSignal(readHilosDaemonEnvOrphansPage(props.context.scopes))
const nodeSilent = computed(() => page.value.nodeSilent)

let mounted = false
watch(
  nodeId,
  (nextNodeId) => {
    orphans.value.dispose()
    orphans.value = createHilosDaemonEnvOrphansTable(props.context, nextNodeId)
    if (mounted) {
      orphans.value.start()
    }
  },
  { flush: 'sync' },
)

onMounted(() => {
  mounted = true
  orphans.value.start()
})

onUnmounted(() => {
  mounted = false
  orphans.value.dispose()
})
</script>

<template>
  <HilosDaemonNodeShell
    :context="context"
    :page="HilosPages.DAEMON_ENV_ORPHANS"
  >
    <HilosViewportTable :key="nodeId" :controller="orphans.controller">
      <template #cell-key="{ row }">
        <code class="fw-semibold">{{ row.key }}</code>
      </template>

      <template #cell-value="{ row }">
        <span class="d-inline-flex align-items-center gap-2 flex-wrap">
          <template
            v-for="(val, index) in [
              formatDaemonEnvValue(row.value, 'string', true),
            ]"
            :key="index"
          >
            <HilosHiddenMark v-if="val.kind === 'hidden'" />
            <span v-else-if="val.kind === 'secret'" class="text-body-secondary">
              <i class="bi bi-key me-1" aria-hidden="true"></i>{{ val.text }}
            </span>
            <span v-else-if="val.kind === 'none'" class="text-body-secondary">
              —
            </span>
            <span
              v-else-if="val.kind === 'empty'"
              class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"
              title="An empty string is a value"
            >
              empty
            </span>
            <input
              v-else-if="val.kind === 'boolean'"
              type="checkbox"
              class="form-check-input mt-0"
              disabled
              tabindex="-1"
              :checked="val.on"
              :aria-label="val.on ? 'On' : 'Off'"
            />
            <code
              v-else-if="val.kind === 'text'"
              :class="{ 'fst-italic': val.numeric }"
            >
              {{ val.text }}
            </code>
          </template>
          <span
            class="badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle"
          >
            not in catalog
          </span>
        </span>
      </template>

      <template #cell-similarTo="{ row }">
        <span class="small text-body-secondary">{{
          formatDaemonEnvSimilarTo(row)
        }}</span>
      </template>

      <template #empty>
        <span v-if="nodeSilent" data-id="hilos-daemon-env-orphans-empty-silent">
          Nothing is read while the node is silent.
        </span>
        <span v-else data-id="hilos-daemon-env-orphans-empty">
          Every line in .env is declared in the catalog.
        </span>
      </template>
    </HilosViewportTable>
  </HilosDaemonNodeShell>
</template>
