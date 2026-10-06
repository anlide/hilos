<!-- HilosLogsWorkersPage draws the by-worker stream list. The core frame owns the
shared bar, columns, cards, and footer; this page supplies cells and its four empty
states. The header names nodes reactively, and Open leads to the stream viewer. -->
<script setup lang="ts">
import {
  createHilosLogWorkersHeader,
  createHilosLogWorkersTable,
  formatLogWorkerState,
  formatLogWorkerType,
  formatLogWorkerWeight,
  hasLogWorkerNodes,
  logWorkerViewerPath,
  logWorkersEmptyState,
  HILOS_LOG_WORKER_TYPE_MONOPOLISTIC,
  HilosPages,
  type HilosLogWorkerRow,
  type HilosLogWorkersContext,
} from '@hilos/core'
import { computed, onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosLink from '../../HilosLink.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{
  /** The project context: scope stores and the connection. */
  context: HilosLogWorkersContext
}>()

const headerHandle = createHilosLogWorkersHeader(props.context)
const streams = createHilosLogWorkersTable(props.context, headerHandle.header)
const streamsTable = streams.controller
const header = useSignal(headerHandle.header)

// Bind the server-windowed table and start listening for the header on mount; the
// header also arrives once as the answer to the subscription.
onMounted(() => {
  headerHandle.start()
  streams.start()
})
onUnmounted(() => {
  streams.dispose()
  headerHandle.dispose()
})

const rows = useSignal(streamsTable.rows)
const search = useSignal(streamsTable.search)
const filter = useSignal(streamsTable.filter)

// The node column, the node filter and the footnote's wording all follow the same
// question: in a single-node installation a column repeating one name and a filter
// offering one option would both be furniture for a choice that does not exist.
const clustered = computed(() => hasLogWorkerNodes(header.value))

// Which of the four empty states the screen is in — the discrimination is the
// headless's, because it is the same question in all three view frameworks.
const emptyState = computed(() =>
  logWorkersEmptyState(
    header.value,
    rows.value.length,
    search.value !== '' || Object.keys(filter.value).length > 0,
  ),
)

// The monopolistic worker is the one this screen was opened for, so its badge is the
// one that carries color; the ordinary ones stay quiet.
function typeClass(row: HilosLogWorkerRow): string {
  return row.type === HILOS_LOG_WORKER_TYPE_MONOPOLISTIC
    ? 'text-bg-info-subtle text-info-emphasis border border-info-subtle'
    : 'text-bg-light border'
}

// A stream still being written is the live one; one left only in the archive is
// quiet, and the two are told apart by weight of color rather than by wording alone.
function stateClass(row: HilosLogWorkerRow): string {
  return row.live ? 'text-bg-success' : 'text-bg-light border'
}

function clearFilters(): void {
  streamsTable.resetFilters()
}
</script>

<template>
  <HilosAdminPage :page="HilosPages.LOGS_WORKERS">
    <p class="text-body-secondary">
      The same thing again, but only for the workers and with the distinction
      the by-key page deliberately loses: an ordinary worker or the monopolistic
      one.
    </p>

    <HilosViewportTable :controller="streamsTable">
      <template #cell-key="{ row }"
        ><code class="fw-semibold small">{{ row.key }}</code></template
      >
      <template #cell-node="{ row }">{{ row.node }}</template>
      <template #cell-type="{ row }">
        <span class="badge" :class="typeClass(row)">
          {{ formatLogWorkerType(row) }}
        </span>
      </template>
      <template #cell-live="{ row }">
        <span class="badge" :class="stateClass(row)">
          {{ formatLogWorkerState(row) }}
        </span>
      </template>
      <template #cell-batchCount="{ row }">{{ row.batchCount }}</template>
      <template #cell-bytes="{ row }">{{
        formatLogWorkerWeight(row)
      }}</template>
      <template #cell-actions="{ row }">
        <HilosLink
          v-if="logWorkerViewerPath(row) !== ''"
          :to="logWorkerViewerPath(row)"
          class="btn btn-sm btn-outline-secondary text-nowrap"
          :data-id="`hilos-log-worker-open-${row.rowKey}`"
          >Open</HilosLink
        >
      </template>

      <template #empty>
        <div
          v-if="emptyState === 'unknown'"
          data-id="hilos-log-worker-empty-unknown"
        >
          <div class="fw-semibold">The cluster picture has not arrived yet</div>
          <p class="mb-0">
            Nobody has reported yet, so there are no figures — not zero of them.
          </p>
        </div>
        <div
          v-else-if="emptyState === 'unreadable'"
          data-id="hilos-log-worker-empty-unreadable"
        >
          <div class="fw-semibold">The log directory cannot be read</div>
          <p class="mb-0">
            <template v-if="clustered">
              No node could read its log store.
            </template>
            <template v-else>The log store could not be read.</template>
            Check the log directory setting and the permissions on it.
          </p>
        </div>
        <div
          v-else-if="emptyState === 'nomatch'"
          data-id="hilos-log-worker-empty-nomatch"
        >
          <div class="fw-semibold">Nothing matches</div>
          <p class="mb-2">There are worker streams — just not these.</p>
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            data-id="hilos-log-worker-clear-filters"
            @click="clearFilters"
          >
            Clear the filters
          </button>
        </div>
        <div v-else data-id="hilos-log-worker-empty-never">
          <div class="fw-semibold">Nothing has been logged yet</div>
          <p class="mb-0">
            No worker has written into this directory — an installation that has
            only just come up looks exactly like this.
          </p>
        </div>
      </template>
    </HilosViewportTable>

    <div class="alert alert-secondary small py-3 mt-4 mb-0">
      <div class="fw-semibold mb-1">
        <i class="bi bi-lightbulb me-1" aria-hidden="true"></i>Why a page of its
        own
      </div>
      <template v-if="clustered">
        There is one monopolistic worker for the whole cluster and any number of
        ordinary ones, and they live on different machines. When the log has
        grown, the first question is whether all the workers grew or only the
        single one holding the shared work. The node column shows whose machine
        it is happening on.
      </template>
      <template v-else>
        The monopolistic worker holds work that cannot be done by two hands at
        once. When the log has grown, the first question is whether the ordinary
        workers grew or that one did — they have different causes and different
        cures.
      </template>
    </div>
  </HilosAdminPage>
</template>
