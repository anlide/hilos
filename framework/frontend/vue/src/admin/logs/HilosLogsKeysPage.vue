<!-- HilosLogsKeysPage — the framework Hilos by-key page (HilosPages.LOGS_KEYS):
which log streams the installation has, what each of them weighs, and how fast it
grows. A key is a file name that survives rotation, and a row is one key ON ONE
NODE — the same worker-0.log on two machines is two files, carried off apart — so
the node column and the node filter exist only where nodes have names. Search, the
node filter and the All / Agents / Workers switch ride the open viewport filter map
(server-side, no local filtering); the window is re-served by the page whenever the
cluster picture moves. The screen commands nothing: the only way out of it is the
Open button into the viewer (HIL-388). The monopolistic workers are folded in with
the ordinary ones here, and the daemon's own streams are not in the list at all.
All table logic, the row view-model, the empty-state discrimination and the wording
are the core headless's (hilosLogKeys); this view owns only the markup, so a project
mounts it by passing its HilosLogKeysContext. Bootstrap classes only
(styling-rules.md). -->
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
  HILOS_LOG_CLASS_OPTIONS,
  HILOS_PAGE_ROUTES,
  HilosPages,
  KEY_BATCH_COUNT_FIELD,
  KEY_BYTES_FIELD,
  KEY_FILTER_CLASS,
  KEY_FILTER_NODE,
  KEY_GROWTH_PER_DAY_FIELD,
  KEY_NAME_FIELD,
  KEY_NODE_FIELD,
  type HilosLogKeyRow,
  type HilosLogKeysContext,
  type HilosTableColumn,
} from '@hilos/core'
import { computed, onMounted, onUnmounted, ref } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosLink from '../../HilosLink.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{
  /** The project context: scope stores and the connection. */
  context: HilosLogKeysContext
}>()

const streams = createHilosLogKeysTable(props.context)
const streamsTable = streams.controller
const headerHandle = createHilosLogKeysHeader(props.context)
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

// The node column and the node filter exist only where nodes have names: in a
// single-node installation a column repeating one name and a filter offering one
// option would both be furniture for a choice that does not exist.
const clustered = computed(() => hasLogKeyNodes(header.value))

// The sortable keys are the exported wire constants, which is where a typo would
// actually cost something — they travel to the backend as the sort field. The
// growth sorts under its own displayed name: the backend maps that name onto the
// integer it orders by, so a stream nothing is known about sinks to the bottom of a
// descending sort rather than opening it.
//
// The node, weight and growth columns drop out of the header below `lg`, where their
// values move into the sub-line of the key cell: a narrow screen gets a shorter
// table rather than one that scrolls sideways.
const columns = computed<HilosTableColumn[]>(() => [
  { key: KEY_NAME_FIELD, label: 'Key', sortable: true },
  ...(clustered.value
    ? [
        {
          key: KEY_NODE_FIELD,
          label: 'Node',
          sortable: true,
          headerClass: 'd-none d-lg-table-cell',
        },
      ]
    : []),
  { key: 'class', label: 'Class' },
  { key: 'state', label: 'State' },
  {
    key: KEY_BATCH_COUNT_FIELD,
    label: 'Batches',
    sortable: true,
    headerClass: 'text-end',
  },
  {
    key: KEY_BYTES_FIELD,
    label: 'Weight',
    sortable: true,
    headerClass: 'text-end d-none d-lg-table-cell',
  },
  {
    key: KEY_GROWTH_PER_DAY_FIELD,
    label: 'Per day',
    sortable: true,
    headerClass: 'text-end d-none d-lg-table-cell',
  },
  { key: 'open', label: '' },
])

// Domain filters: the node and the class ride the open filter map so the backend
// narrows the window (no local filtering). Empty clears the filter.
const nodeFilter = ref('')
const classFilter = ref('')

function setNode(value: string): void {
  nodeFilter.value = value
  streamsTable.setFilter(KEY_FILTER_NODE, value)
}

function onNode(event: Event): void {
  setNode((event.target as HTMLSelectElement).value)
}

function setClass(value: string): void {
  classFilter.value = value
  streamsTable.setFilter(KEY_FILTER_CLASS, value)
}

// Which of the four empty states the screen is in — the discrimination is the
// headless's, because it is the same question in all three view frameworks.
const emptyState = computed(() =>
  logKeysEmptyState(
    header.value,
    rows.value.length,
    search.value !== '' || nodeFilter.value !== '' || classFilter.value !== '',
  ),
)

// A stream still being written is the live one; one left only in the archive is
// quiet, and the two are told apart by weight of color rather than by wording alone.
function stateClass(row: HilosLogKeyRow): string {
  return row.live ? 'text-bg-success' : 'text-bg-light border'
}

function clearFilters(): void {
  streamsTable.setSearch('')
  setNode('')
  setClass('')
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

    <div class="d-flex flex-wrap align-items-end gap-2 mb-3">
      <div v-if="clustered">
        <label class="form-label" for="hilos-log-key-node">Node</label>
        <select
          id="hilos-log-key-node"
          class="form-select"
          :value="nodeFilter"
          data-id="hilos-log-key-node"
          @change="onNode"
        >
          <option value="">All nodes</option>
          <option v-for="node in header?.nodes ?? []" :key="node" :value="node">
            {{ node }}
          </option>
        </select>
      </div>
      <div
        class="btn-group btn-group-sm"
        role="group"
        aria-label="Stream class"
      >
        <button
          v-for="option in HILOS_LOG_CLASS_OPTIONS"
          :key="option.value"
          type="button"
          class="btn btn-outline-secondary"
          :class="{ active: classFilter === option.value }"
          :aria-pressed="classFilter === option.value"
          :data-id="`hilos-log-key-class-${option.value || 'all'}`"
          @click="setClass(option.value)"
        >
          {{ option.label }}
        </button>
      </div>
    </div>

    <HilosViewportTable
      label="Log streams"
      :controller="streamsTable"
      :columns="columns"
      searchable
      search-placeholder="Search by key or node…"
    >
      <template #row="{ row }">
        <td>
          <code class="fw-semibold small">{{ row.key }}</code>
          <!-- The sub-line carries whatever the hidden columns were carrying, so a
          narrow screen loses the layout and not the figures. It is there in a
          single-node installation too, where only the weight and the growth were
          hidden. -->
          <div class="small text-body-secondary d-lg-none">
            <template v-if="clustered">{{ row.node }} · </template
            >{{ formatLogKeyWeight(row) }} · {{ formatLogKeyGrowth(row) }}
          </div>
        </td>
        <td v-if="clustered" class="d-none d-lg-table-cell">{{ row.node }}</td>
        <td>
          <span class="badge text-bg-light border">
            {{ formatLogKeyClass(row) }}
          </span>
        </td>
        <td>
          <span class="badge" :class="stateClass(row)">
            {{ formatLogKeyState(row) }}
          </span>
        </td>
        <td class="text-end">{{ row.batchCount }}</td>
        <td class="text-end d-none d-lg-table-cell">
          {{ formatLogKeyWeight(row) }}
        </td>
        <td class="text-end d-none d-lg-table-cell">
          {{ formatLogKeyGrowth(row) }}
        </td>
        <td class="text-end">
          <!-- A stream that is neither live nor archived has no file to open, and
          the headless answers with an empty address rather than a broken one. -->
          <HilosLink
            v-if="logKeyViewerPath(row) !== ''"
            :to="logKeyViewerPath(row)"
            class="btn btn-sm btn-outline-secondary text-nowrap"
            :data-id="`hilos-log-key-open-${row.rowKey}`"
          >
            Open
          </HilosLink>
        </td>
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
            No node could read its log store. Check the log directory setting
            and the permissions on it.
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
