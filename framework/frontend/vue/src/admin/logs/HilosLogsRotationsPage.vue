<!-- HilosLogsRotationsPage — the framework Hilos rotation-history page
(HilosPages.LOGS_ROTATIONS): what already lies in the log archive, what it weighs,
and what the retention rule recommends carrying off before the installation runs
out of room. A row is one batch ON ONE NODE — the same rotation moment on two
machines is two directories, carried off apart — so the node column and the node
filter exist only where nodes have names. Search, the node filter and the
All / awaiting switch ride the open viewport filter map (server-side, no local
filtering); the window is re-served by the page whenever the cluster picture or
the rule moves. A recommended batch carries the first of this screen's two
commands: a modal saying where the batch lies and how to copy it off, and a
confirmation that it was (HIL-483) — the badge then repaints when the holding
node's next index arrives, not when the ack does. A taken batch carries the other
half of that (HIL-759): a trigger that takes the word back while the batch is
still on disk, behind a modal naming when its node's cleaner may first delete it.
Deleting a taken batch is HIL-382, and there is no way through to the viewer yet
because it takes no batch address (HIL-388). All table logic, the row
view-model, the empty-state discrimination and the wording are the core headless's
(hilosLogRotations); this view owns only the markup, so a project mounts it by
passing its HilosLogRotationsContext. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosLogRotationsActions,
  createHilosLogRotationsHeader,
  createHilosLogRotationsTable,
  formatRetentionRule,
  formatRotationFileCounts,
  formatRotationRule,
  formatRotationState,
  formatRotationWeight,
  hasRotationNodes,
  rotationsEmptyState,
  rotationTakeoutAddress,
  rotationTakeoutCommand,
  HILOS_PAGE_ROUTES,
  HILOS_ROTATION_STATE_CARRYING,
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

import HilosActionError from '../../HilosActionError.vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosLink from '../../HilosLink.vue'
import HilosModal from '../../HilosModal.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import LoadingButton from '../../LoadingButton.vue'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

const props = defineProps<{
  /** The project context: scope stores, the connection, and the action lifecycle. */
  context: HilosLogRotationsContext
}>()

const rotations = createHilosLogRotationsTable(props.context)
const rotationsTable = rotations.controller
const rotationsActions = createHilosLogRotationsActions(props.context)
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
  { key: 'actions', label: '', headerClass: 'text-end' },
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

// The retention badge: a batch on its way is in motion and not in trouble, a
// recommendation is a warning and not a fault, a taken batch is settled, and a kept
// one is the quiet default. Neither row action reads this map — both compare with
// the state they act on, so a carrying row offers neither by construction.
const RETENTION_CLASS: Record<string, string> = {
  [HILOS_ROTATION_STATE_CARRYING]: 'text-bg-info',
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

// The takeout dialog: how to carry one batch off, and the button that records
// that it was. Only a recommended batch offers it — a kept one is not being asked
// for, and a taken one has already been answered.
const takeoutOpen = ref(false)
const takeoutRow = ref<HilosLogRotationRow | null>(null)
const takeoutAction = useTrackedAction()
const {
  loading: takeoutLoading,
  busy: takeoutBusy,
  run: runTakeout,
  clearError: clearTakeoutError,
} = takeoutAction

function offersTakeout(row: HilosLogRotationRow): boolean {
  return row.retentionState === HILOS_ROTATION_STATE_DUE
}

function openTakeout(row: HilosLogRotationRow): void {
  clearTakeoutError()
  takeoutRow.value = row
  takeoutOpen.value = true
}

// A snapshot of the row the dialog opened on, so a window re-served underneath it
// (the page re-sends one whenever the picture moves) does not swap the batch the
// operator is reading the address of.
const takeoutAddress = computed(() =>
  takeoutRow.value === null ? null : rotationTakeoutAddress(takeoutRow.value),
)
const takeoutCommand = computed(() =>
  takeoutRow.value === null ? null : rotationTakeoutCommand(takeoutRow.value),
)

async function submitTakeout(): Promise<void> {
  const row = takeoutRow.value
  if (row === null || takeoutBusy.value) {
    return
  }
  // The dialog closes on the server's word and not on the click: the refusals
  // this can meet — the batch is gone, it is protected again — are the whole
  // reason the confirmation travels to the node that holds the directory.
  if (await runTakeout(rotationsActions.sendTakeoutConfirm(row))) {
    takeoutOpen.value = false
  }
}

// Taking the word back (HIL-759). Offered on a taken batch and on no other, as a
// link rather than a button: it is the correction of a click and not the action
// the row is there for. The judge is the physical batch and never a timer in this
// tab — the node refuses only when the directory is gone.
const undoOpen = ref(false)
const undoRow = ref<HilosLogRotationRow | null>(null)
const undoAction = useTrackedAction()
const {
  loading: undoLoading,
  busy: undoBusy,
  run: runUndo,
  clearError: clearUndoError,
} = undoAction

function offersUndo(row: HilosLogRotationRow): boolean {
  return row.retentionState === HILOS_ROTATION_STATE_TAKEN
}

function openUndo(row: HilosLogRotationRow): void {
  clearUndoError()
  undoRow.value = row
  undoOpen.value = true
}

// What the batch's own node promises: the instant its cleaner may first take it.
// One word for one actor on this screen — the class behind it is a pruner, but
// the operator has been reading "cleaner" since the takeout modal. Null is the
// installation that told it not to wait, and that is said in words too: a blank
// would read as "we do not know" rather than "at any moment".
const undoDeadline = computed(() =>
  undoRow.value?.pruneNotBefore == null
    ? 'The cleaner may delete this batch as soon as it next runs.'
    : `The cleaner may delete this batch after ${new Date(undoRow.value.pruneNotBefore * 1000).toLocaleString()}.`,
)

async function submitUndo(): Promise<void> {
  const row = undoRow.value
  if (row === null || undoBusy.value) {
    return
  }
  // Closes on the server's word, like the confirmation: the one refusal this can
  // meet — the batch is no longer on the node — is exactly what the operator has
  // to see instead of a modal that closed as though it had worked.
  if (await runUndo(rotationsActions.sendTakeoutUndo(row))) {
    undoOpen.value = false
  }
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
        <td class="text-end text-nowrap">
          <button
            v-if="offersTakeout(row)"
            type="button"
            class="btn btn-sm btn-warning"
            data-id="hilos-rotation-takeout"
            @click="openTakeout(row)"
          >
            How to carry it off
          </button>
          <!-- A link by sight and a button by nature, the way the legend trigger
          below is: the design asks for a link because withdrawing is not the
          action the row is there for, but this one opens a dialog and navigates
          nowhere, so an <a href="#"> would answer a ctrl-click with a pointless
          new tab and announce itself to a screen reader as a link. -->
          <button
            v-if="offersUndo(row)"
            type="button"
            class="btn btn-link btn-sm p-0 align-baseline"
            data-id="hilos-rotation-undo"
            @click="openUndo(row)"
          >
            I did not carry this one off
          </button>
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

    <HilosModal
      v-model="takeoutOpen"
      :title="
        takeoutRow
          ? `Carrying off the batch of ${batchTime(takeoutRow)}${takeoutRow.node ? ` · ${takeoutRow.node}` : ''}`
          : 'Carrying off a batch'
      "
      :close-on-backdrop="!takeoutBusy"
      :close-on-esc="!takeoutBusy"
    >
      <HilosActionError :action="takeoutAction" />
      <p>
        This batch is recommended for carrying off: it is older than the
        retention rule keeps. The system does
        <strong>not delete it</strong> — you copy it where you keep cold logs,
        and then confirm that you have.
      </p>
      <template v-if="takeoutAddress && takeoutCommand">
        <div class="fw-semibold mb-1">Where it lies</div>
        <pre
          class="border rounded-2 p-2 bg-body-tertiary mb-3"
          data-id="hilos-rotation-takeout-path"
        ><code>{{ takeoutAddress }}</code></pre>
        <div class="fw-semibold mb-1">How to take it</div>
        <pre
          class="border rounded-2 p-2 bg-body-tertiary mb-3"
          data-id="hilos-rotation-takeout-command"
        ><code>{{ takeoutCommand }}</code></pre>
      </template>
      <!-- A node that reported no log root has no address to give, and this
      screen must not offer its own: the page worker knows where ITS logs live,
      and that directory is on the wrong machine. Confirming is still possible —
      the operator may know the path from the node itself. -->
      <div v-else class="alert alert-secondary small py-2">
        This node did not report where its logs live, so there is no address to
        copy from here. Look it up on the node itself.
      </div>
      <div
        v-if="clustered && takeoutRow?.node"
        class="alert alert-warning small py-2 mb-0"
      >
        The batch lies on node
        <span class="font-monospace">{{ takeoutRow.node }}</span> and only
        there: logs do not converge anywhere. Take it from that node, and the
        confirmation covers this batch on this node.
      </div>
      <div v-else class="alert alert-secondary small py-2 mb-0">
        Once confirmed, the batch becomes available to the cleaner — but not
        straight away: this node keeps a confirmed batch for a while, and you
        can take the confirmation back for as long as the batch is there.
      </div>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="takeoutBusy"
          @click="requestClose"
        >
          Close
        </button>
        <LoadingButton
          class="btn-primary"
          :loading="takeoutLoading"
          data-id="hilos-rotation-takeout-confirm"
          @click="submitTakeout"
        >
          I have taken this batch
        </LoadingButton>
      </template>
    </HilosModal>

    <HilosModal
      v-model="undoOpen"
      title="Has the batch not been carried off?"
      :close-on-backdrop="!undoBusy"
      :close-on-esc="!undoBusy"
    >
      <HilosActionError :action="undoAction" />
      <p>
        Your word that you have taken it is the only thing that lets the cleaner
        delete this batch. Take that word back and the batch returns to the list
        of the ones recommended for carrying off.
      </p>
      <p
        class="small text-body-secondary"
        data-id="hilos-rotation-undo-deadline"
      >
        {{ undoDeadline }}
      </p>
      <div class="alert alert-secondary small py-2 mb-0">
        It can only be taken back while the batch is still there. Once the
        cleaner has passed there is nothing to bring back — which is exactly why
        deleting waits for your word.
      </div>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="undoBusy"
          @click="requestClose"
        >
          Leave it as it is
        </button>
        <LoadingButton
          class="btn-primary"
          :loading="undoLoading"
          data-id="hilos-rotation-undo-confirm"
          @click="submitUndo"
        >
          Withdraw the acknowledgement
        </LoadingButton>
      </template>
    </HilosModal>

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
