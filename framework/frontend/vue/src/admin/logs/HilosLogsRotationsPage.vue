<!-- HilosLogsRotationsPage — the framework Hilos rotation-history page
(HilosPages.LOGS_ROTATIONS): what already lies in the log archive, what it weighs,
and what the retention rule recommends carrying off before the installation runs
out of room. A row is one batch ON ONE NODE — the same rotation moment on two
machines is two directories, carried off apart — so the node column and the node
filter exist only where nodes have names. Search, the node filter and the
All / awaiting switch ride the open viewport filter map (server-side, no local
filtering); the window is re-served by the page whenever the cluster picture or
the rule moves. The screen commands nothing: what to do with a recommended batch
is HIL-483, deleting a taken one is HIL-382, and there is no way through to the
viewer yet because it takes no batch address (HIL-388). All table logic, the row
view-model, the empty-state discrimination and the wording are the core headless's
(hilosLogRotations); this view owns only the markup, so a project mounts it by
passing its HilosLogRotationsContext. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosLogRotationsHeader,
  createHilosLogRotationsTable,
  formatRetentionRule,
  formatRotationFileCounts,
  formatRotationRule,
  formatRotationState,
  formatRotationWeight,
  hasRotationNodes,
  rotationsEmptyState,
  HILOS_PAGE_ROUTES,
  HILOS_ROTATION_STATE_DUE,
  HILOS_ROTATION_STATE_OPTIONS,
  HILOS_ROTATION_STATE_TAKEN,
  HilosPages,
  ROTATION_BATCH_AT_FIELD,
  ROTATION_BYTES_FIELD,
  ROTATION_FILTER_NODE,
  ROTATION_FILTER_STATE,
  ROTATION_NODE_FIELD,
  type HilosLogRotationRow,
  type HilosLogRotationsContext,
  type HilosTableColumn,
} from '@hilos/core'
import { computed, onMounted, onUnmounted, ref } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosLink from '../../HilosLink.vue'
import HilosModal from '../../HilosModal.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{
  /** The project context: scope stores and the connection. */
  context: HilosLogRotationsContext
}>()

const rotations = createHilosLogRotationsTable(props.context)
const rotationsTable = rotations.controller
const headerHandle = createHilosLogRotationsHeader(props.context)
const header = useSignal(headerHandle.header)

// Bind the server-windowed table and start listening for the header on mount; the
// header also arrives once as the answer to the subscription.
onMounted(() => {
  headerHandle.start()
  rotations.start()
})
onUnmounted(() => {
  rotations.dispose()
  headerHandle.dispose()
})

const rows = useSignal(rotationsTable.rows)
const search = useSignal(rotationsTable.search)

// The node column and the node filter exist only where nodes have names: in a
// single-node installation a column repeating one name and a filter offering one
// option would both be furniture for a choice that does not exist.
const clustered = computed(() => hasRotationNodes(header.value))

// Declared as the loose HilosTableColumn rather than the row-typed form: the Files
// column is three counts at once and belongs to no single field, so keying it to
// one of them would name the column after a third of what it shows. The sortable
// keys are the exported wire constants, which is where a typo would actually cost
// something — they travel to the backend as the sort field.
//
// The node and weight columns drop out of the header below `lg`, where their
// values move into the sub-line of the batch cell: a narrow screen gets a shorter
// table rather than one that scrolls sideways.
const columns = computed<HilosTableColumn[]>(() => [
  { key: ROTATION_BATCH_AT_FIELD, label: 'Batch', sortable: true },
  ...(clustered.value
    ? [
        {
          key: ROTATION_NODE_FIELD,
          label: 'Node',
          sortable: true,
          headerClass: 'd-none d-lg-table-cell',
        },
      ]
    : []),
  { key: 'files', label: 'Files' },
  {
    key: ROTATION_BYTES_FIELD,
    label: 'Weight',
    sortable: true,
    headerClass: 'text-end d-none d-lg-table-cell',
  },
  { key: 'retention', label: 'Retention' },
])

// Domain filters: the node and the state ride the open filter map so the backend
// narrows the window (no local filtering). Empty clears the filter.
const nodeFilter = ref('')
const stateFilter = ref('')

function setNode(value: string): void {
  nodeFilter.value = value
  rotationsTable.setFilter(ROTATION_FILTER_NODE, value)
}

function onNode(event: Event): void {
  setNode((event.target as HTMLSelectElement).value)
}

function setState(value: string): void {
  stateFilter.value = value
  rotationsTable.setFilter(ROTATION_FILTER_STATE, value)
}

// Which of the four empty states the screen is in — the discrimination is the
// headless's, because it is the same question in all three view frameworks.
const emptyState = computed(() =>
  rotationsEmptyState(
    header.value,
    rows.value.length,
    search.value !== '' || nodeFilter.value !== '' || stateFilter.value !== '',
  ),
)

// The batch's own name is its rotation time; the archive directory under it is
// what an operator types into scp, so both are in the cell.
function batchTime(row: HilosLogRotationRow): string {
  return new Date(row.batchAt * 1000).toLocaleString()
}

// The retention badge: a recommendation is a warning and not a fault, a taken
// batch is settled, and a kept one is the quiet default.
const RETENTION_CLASS: Record<string, string> = {
  [HILOS_ROTATION_STATE_DUE]: 'text-bg-warning',
  [HILOS_ROTATION_STATE_TAKEN]: 'text-bg-secondary',
}

function retentionClass(row: HilosLogRotationRow): string {
  return RETENTION_CLASS[row.retentionState] ?? 'text-bg-light border'
}

function clearFilters(): void {
  rotationsTable.setSearch('')
  setNode('')
  setState('')
}

// The rule line leads to the general settings screen: the log settings page does
// not exist in the registry yet (HIL-391 adds it and re-points this link).
const settingsHref = HILOS_PAGE_ROUTES[HilosPages.SETTINGS] ?? '/'

const legendOpen = ref(false)
</script>

<template>
  <HilosAdminPage :page="HilosPages.LOGS_ROTATIONS">
    <div
      class="d-flex flex-wrap align-items-center gap-3 border rounded-3 p-3 mb-4"
    >
      <i class="bi bi-sliders text-body-secondary" aria-hidden="true"></i>
      <div class="flex-grow-1">
        <div
          v-if="header"
          class="fw-semibold small"
          data-id="hilos-rotation-rule"
        >
          {{ formatRotationRule(header) }}
        </div>
        <div v-if="header" class="small text-body-secondary">
          {{ formatRetentionRule(header) }}
        </div>
        <div v-else class="small text-body-secondary">
          The rule in force is not known yet.
        </div>
        <div class="small text-body-secondary">
          {{
            clustered
              ? 'One rule for the whole cluster'
              : 'One rule for the installation'
          }}
        </div>
      </div>
      <HilosLink
        :to="settingsHref"
        class="btn btn-sm btn-outline-secondary text-nowrap"
        data-id="hilos-rotation-settings"
      >
        Log settings
      </HilosLink>
    </div>

    <div class="d-flex flex-wrap align-items-end gap-2 mb-3">
      <div v-if="clustered">
        <label class="form-label" for="hilos-rotation-node">Node</label>
        <select
          id="hilos-rotation-node"
          class="form-select"
          :value="nodeFilter"
          data-id="hilos-rotation-node"
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
        aria-label="Retention state"
      >
        <button
          v-for="option in HILOS_ROTATION_STATE_OPTIONS"
          :key="option.value"
          type="button"
          class="btn btn-outline-secondary"
          :class="{ active: stateFilter === option.value }"
          :aria-pressed="stateFilter === option.value"
          :data-id="`hilos-rotation-state-${option.value || 'all'}`"
          @click="setState(option.value)"
        >
          {{ option.label }}
        </button>
      </div>
    </div>

    <HilosViewportTable
      label="Rotation batches"
      :controller="rotationsTable"
      :columns="columns"
      searchable
      search-placeholder="Search by batch date or node…"
    >
      <template #row="{ row }">
        <td>
          <div class="fw-semibold small">{{ batchTime(row) }}</div>
          <code class="small text-body-secondary">{{ row.path }}</code>
          <!-- The sub-line carries whatever the hidden columns were carrying, so a
          narrow screen loses the layout and not the figures. It is there in a
          single-node installation too, where only the weight was hidden. -->
          <div class="small text-body-secondary d-lg-none">
            <template v-if="clustered">{{ row.node }} · </template
            >{{ formatRotationWeight(row) }}
          </div>
        </td>
        <td v-if="clustered" class="d-none d-lg-table-cell">{{ row.node }}</td>
        <td class="small">{{ formatRotationFileCounts(row) }}</td>
        <td class="text-end d-none d-lg-table-cell">
          {{ formatRotationWeight(row) }}
        </td>
        <td>
          <span class="badge" :class="retentionClass(row)">
            {{ formatRotationState(row) }}
          </span>
        </td>
      </template>

      <template #empty>
        <div
          v-if="emptyState === 'unknown'"
          data-id="hilos-rotation-empty-unknown"
        >
          <div class="fw-semibold">The cluster picture has not arrived yet</div>
          <p class="mb-0">
            Nobody has reported yet, so there are no figures — not zero of them.
          </p>
        </div>
        <div
          v-else-if="emptyState === 'unreadable'"
          data-id="hilos-rotation-empty-unreadable"
        >
          <div class="fw-semibold">The log directory cannot be read</div>
          <p class="mb-0">
            No node could read its log store. Check the log directory setting
            and the permissions on it.
          </p>
        </div>
        <div
          v-else-if="emptyState === 'nomatch'"
          data-id="hilos-rotation-empty-nomatch"
        >
          <div class="fw-semibold">Nothing matches</div>
          <p class="mb-2">There are batches — just not these.</p>
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            data-id="hilos-rotation-clear-filters"
            @click="clearFilters"
          >
            Clear the filters
          </button>
        </div>
        <div v-else data-id="hilos-rotation-empty-never">
          <div class="fw-semibold">Nothing has rotated yet</div>
          <p class="mb-0">
            The archive fills at the first rotation; until then there is nothing
            to carry off.
          </p>
        </div>
      </template>
    </HilosViewportTable>

    <p class="small text-body-secondary mt-2 mb-0">
      <button
        type="button"
        class="btn btn-link btn-sm p-0 align-baseline"
        data-id="hilos-rotation-legend"
        @click="legendOpen = true"
      >
        Files
      </button>
      — three numbers in a row: agent / worker / monopolistic worker.
    </p>

    <HilosModal v-model="legendOpen" title="What is in a batch">
      <p>
        A batch is one archive directory, written by one rotation on one node.
        The three numbers count the files in it by the stream that wrote them:
      </p>
      <ul class="mb-3">
        <li><strong>agent</strong> — one file per agent that logged.</li>
        <li>
          <strong>worker</strong> — one per worker process, the monopolistic
          ones apart.
        </li>
        <li>
          <strong>monopolistic worker</strong> — the workers that hold work
          which cannot be done in two hands.
        </li>
      </ul>
      <p class="mb-0">
        The daemon's own two files are a fourth class and are not counted here —
        they belong to the node rather than to anything the installation runs.
        The weight column still includes them: that is what the directory costs.
      </p>
    </HilosModal>
  </HilosAdminPage>
</template>
