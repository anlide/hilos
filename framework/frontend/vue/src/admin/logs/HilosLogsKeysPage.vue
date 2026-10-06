<!-- HilosLogsKeysPage draws the by-key stream list. The core frame owns the shared
bar, columns, cards, and footer; this page supplies cells and its four empty states.
The header names nodes reactively, and Open leads to the stream viewer. -->
<script setup lang="ts">
import {
  createHilosLogKeysHeader,
  createHilosLogKeysTable,
  formatLogKeyClass,
  formatLogKeyGrowth,
  formatLogKeyState,
  formatLogKeyWeight,
  hasLogKeyNodes,
  logKeyViewerPath,
  logKeysEmptyState,
  HILOS_PAGE_ROUTES,
  HilosPages,
  type HilosLogKeyRow,
  type HilosLogKeysContext,
} from '@hilos/core'
import { computed, onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosLink from '../../HilosLink.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{
  /** The project context: scope stores and the connection. */
  context: HilosLogKeysContext
}>()

const headerHandle = createHilosLogKeysHeader(props.context)
const streams = createHilosLogKeysTable(props.context, headerHandle.header)
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

// The node column and the node filter exist only where nodes have names: in a
// single-node installation a column repeating one name and a filter offering one
// option would both be furniture for a choice that does not exist.
const clustered = computed(() => hasLogKeyNodes(header.value))

// Which of the four empty states the screen is in — the discrimination is the
// headless's, because it is the same question in all three view frameworks.
const emptyState = computed(() =>
  logKeysEmptyState(
    header.value,
    rows.value.length,
    search.value !== '' || Object.keys(filter.value).length > 0,
  ),
)

// A stream still being written is the live one; one left only in the archive is
// quiet, and the two are told apart by weight of color rather than by wording alone.
function stateClass(row: HilosLogKeyRow): string {
  return row.live
    ? 'text-bg-success'
    : 'bg-body-tertiary text-body-secondary border'
}

function clearFilters(): void {
  streamsTable.resetFilters()
}

// Where the split this page folds away is actually shown. HIL-385 left the phrase
// as plain text because the by-worker page was still a stub; it is a screen now.
const workersPath = HILOS_PAGE_ROUTES[HilosPages.LOGS_WORKERS]
</script>

<template>
  <HilosAdminPage :page="HilosPages.LOGS_KEYS">
    <p class="text-body-secondary">
      A key is the file name that survives rotation: the same stream goes on
      being written under that name into the next batch.
      <template v-if="clustered">
        A row here is a key <em>on a node</em>: the same
        <code>worker-0.log</code> on two nodes is two files, carried off apart.
      </template>
    </p>

    <HilosViewportTable :controller="streamsTable">
      <template #cell-key="{ row }"
        ><code class="fw-semibold small">{{ row.key }}</code></template
      >
      <template #cell-node="{ row }">{{ row.node }}</template>
      <template #cell-class="{ row }">
        <span
          class="badge text-bg-secondary-subtle text-secondary-emphasis border"
          >{{ formatLogKeyClass(row) }}</span
        >
      </template>
      <template #cell-live="{ row }">
        <span class="badge" :class="stateClass(row)">{{
          formatLogKeyState(row)
        }}</span>
      </template>
      <template #cell-batchCount="{ row }">{{ row.batchCount }}</template>
      <template #cell-bytes="{ row }">{{ formatLogKeyWeight(row) }}</template>
      <template #cell-growthPerDay="{ row }">{{
        formatLogKeyGrowth(row)
      }}</template>
      <template #cell-actions="{ row }">
        <HilosLink
          v-if="logKeyViewerPath(row) !== ''"
          :to="logKeyViewerPath(row)"
          class="btn btn-sm btn-outline-secondary text-nowrap"
          :data-id="`hilos-log-key-open-${row.rowKey}`"
          >Open</HilosLink
        >
      </template>

      <template #empty>
        <div
          v-if="emptyState === 'unknown'"
          data-id="hilos-log-key-empty-unknown"
        >
          <div class="fw-semibold">The cluster picture has not arrived yet</div>
          <p class="mb-0">
            Nobody has reported yet, so there are no figures — not zero of them.
          </p>
        </div>
        <div
          v-else-if="emptyState === 'unreadable'"
          data-id="hilos-log-key-empty-unreadable"
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
          data-id="hilos-log-key-empty-nomatch"
        >
          <div class="fw-semibold">Nothing matches</div>
          <p class="mb-2">There are streams — just not these.</p>
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            data-id="hilos-log-key-clear-filters"
            @click="clearFilters"
          >
            Clear the filters
          </button>
        </div>
        <div v-else data-id="hilos-log-key-empty-never">
          <div class="fw-semibold">Nothing has been logged yet</div>
          <p class="mb-0">
            The daemon has not written into this directory — an installation
            that has only just come up looks exactly like this.
          </p>
        </div>
      </template>
    </HilosViewportTable>

    <!-- The legend of the three classes reads no data and has no state, so it stands
    even under an empty table: it says what the files behind the class switch are. -->
    <section data-id="hilos-log-key-classes">
      <h2 class="h6 text-uppercase text-body-secondary mb-2 mt-4">
        Three stream classes, and there will be no fourth
      </h2>
      <p class="small text-body-secondary">
        The class is a property of which process writes the file, not of how the
        screen shows it. There are exactly three, and the All button is the sum
        of the other three.
      </p>
      <div class="row row-cols-1 row-cols-lg-3 g-3">
        <div class="col">
          <div class="border rounded-3 p-3 h-100">
            <div class="d-flex align-items-center gap-2 mb-1">
              <i
                class="bi bi-hdd-stack text-body-secondary"
                aria-hidden="true"
              ></i>
              <span class="badge text-bg-light border">Daemon</span>
            </div>
            <div class="small mb-2">
              <code>daemon.log</code>, <code>daemon-error.log</code> and the raw
              stream beside each
            </div>
            <p class="small text-body-secondary mb-0">
              Matched by the exact file name rather than by a prefix: the
              daemon's own streams carry no prefix at all. There are four files,
              not two — beside the one the logger writes lies a raw stream
              taking everything PHP prints past it: a fatal error, a warning, a
              trace. Rotation does not touch the raw pair; only a daemon restart
              replaces it.
            </p>
          </div>
        </div>
        <div class="col">
          <div class="border rounded-3 p-3 h-100">
            <div class="d-flex align-items-center gap-2 mb-1">
              <i class="bi bi-cpu text-body-secondary" aria-hidden="true"></i>
              <span class="badge text-bg-light border">Agents</span>
            </div>
            <div class="small mb-2"><code>agent-*.log</code></div>
            <p class="small text-body-secondary mb-0">
              One file per agent, the stream named after the agent type.
            </p>
          </div>
        </div>
        <div class="col">
          <div class="border rounded-3 p-3 h-100">
            <div class="d-flex align-items-center gap-2 mb-1">
              <i
                class="bi bi-diagram-3 text-body-secondary"
                aria-hidden="true"
              ></i>
              <span class="badge text-bg-light border">Workers</span>
            </div>
            <div class="small mb-2">
              <code>worker-*.log</code> and
              <code>worker-monopolistic-*.log</code>
            </div>
            <p class="small text-body-secondary mb-0">
              Both prefixes are folded into one class: telling ordinary workers
              from monopolistic ones is the neighbouring page's question, and
              here it would only split the list.
            </p>
          </div>
        </div>
      </div>
      <p class="small text-body-secondary mt-3 mb-0">
        The daemon's streams are where the errors that bring anyone to this
        section land. Hiding them here would keep a whole class of logs from the
        operator.
        <template v-if="clustered">
          Each node has its own four, and they are carried off apart.
        </template>
      </p>
    </section>

    <p class="small text-body-secondary mt-3 mb-0">
      The weight answers "how much is taken", the growth answers "when the room
      runs out"; a stream that is no longer written has no growth. Monopolistic
      workers are folded in with the ordinary ones here — the split is shown by
      <HilosLink :to="workersPath" data-id="hilos-log-key-workers-link">
        the workers page </HilosLink
      >. Search and sorting go to the server: while it counts, the table is busy
      rather than showing the old order as the new one.
    </p>
  </HilosAdminPage>
</template>
