<!-- HilosTableLive — the one room above a table for everything live it has to
say: changes waiting for Apply, rows created that the window cannot show, a
source that stopped being kept up to date, and work running on the set, plus
bulk action progress and outcome report (Design D3). The room is exactly one
line tall at every table and never changes height (styling-rules.md, "The room a
live message takes"): an invisible twin of the very same row stands in the flow
at all times and holds it, and the message is laid over that twin — over its OWN
reserve, the way LoadingButton lays its spinner over its own text, and never
over a row of the table. The twin stays in the flow rather than taking turns
with the message under v-if/v-else, because the rows are not one height: some
rows have buttons, and a room swapping to a buttonless row would sit down.
When several are live, the core decides which holds the line (tableLive.ts) and
the others stand beside its text as their icons alone. Details of an untouched bulk
report open in a dialog (HilosModal) mounted outside the live strip so that
messages cycling underneath do not dismiss it.
What a screen reader hears is one hidden region that stands before there is
anything to say, never the row itself: the track of running work lives in the
row and moves every second, and a region on the row would read it out each time.
Internal to the Vue view layer on purpose: it is not exported from index.ts, for
the reason the bar and the footer are not — outside a table it means nothing. -->
<script setup lang="ts" generic="R">
import { computed, ref } from 'vue'
import {
  hilosTableStaleColumns,
  hilosTableStaleLabel,
  hilosTableStaleSources,
} from '@hilos/core'
import type {
  HilosTableBulkReport,
  HilosTableColumn,
  HilosTableLiveKind,
  // Aliased because the component drawing one of these carries the same name.
  HilosTableProgress as TableProgressBar,
  TableViewportController,
} from '@hilos/core'

import HilosModal from './HilosModal.vue'
import HilosTableProgress from './HilosTableProgress.vue'
import { useSignal } from './useSignal.js'

const props = defineProps<{
  /** The headless server-windowed controller the room reads and drives. */
  controller: TableViewportController<R>
  /**
   * The columns the table is drawn from — read to name the columns built from a
   * source that went quiet, so the room cannot name a column the table does not have.
   */
  columns: readonly HilosTableColumn[]
}>()

defineSlots<{
  /** The project's own words about the work running on the table, on the line itself. */
  'table-progress'?: (props: { progress: TableProgressBar }) => unknown
  /** The project's own control for that work, standing where a button stands. */
  'table-progress-action'?: (props: { progress: TableProgressBar }) => unknown
  /**
   * The human name of one row a run left untouched; the key is printed where the
   * page fills nothing, because the row itself has left the window by then.
   */
  'bulk-untouched'?: (props: { rowKey: string; reason: string }) => unknown
}>()

/**
 * The message row and its idle twin, to the character — only `invisible` on the
 * twin and the color and the placement on the message differ. The room equals the
 * true height of the row exactly while the markup matches. The row never wraps: the
 * text truncates and the icons and the button keep their size, so a narrow screen
 * cannot fold the button onto a second line.
 */
const ROW_CLASS =
  'alert py-2 px-3 mb-0 d-flex flex-nowrap align-items-center gap-2'

/** The color each kind of message is drawn in (report is computed separately). */
const VARIANT: Record<Exclude<HilosTableLiveKind, 'report'>, string> = {
  bulk: 'alert-secondary',
  pending: 'alert-warning',
  announce: 'alert-secondary',
  stale: 'alert-info',
  progress: 'alert-secondary',
}

/** The icon each kind is drawn with — on the line when it holds it, and beside it when not. */
const ICON: Record<HilosTableLiveKind, string> = {
  report: 'bi-clipboard-check',
  bulk: 'bi-check2-square',
  pending: 'bi-pause-circle',
  announce: 'bi-arrow-down-circle',
  stale: 'bi-snow',
  progress: 'bi-arrow-repeat',
}

/** The data-id of the row while that kind holds it — the handles e2e reads each message by. */
const ROW_ID: Record<HilosTableLiveKind, string> = {
  report: 'hilos-table-bulk-report',
  bulk: 'hilos-table-progress-bulk',
  pending: 'hilos-table-pending-row',
  announce: 'hilos-table-announce',
  stale: 'hilos-table-stale',
  progress: 'hilos-table-progress',
}

/** How each kind is named when it is listed after the one holding the line. */
const REST_WORDS: Record<HilosTableLiveKind, string> = {
  report: 'a bulk report',
  bulk: 'work on the marked rows',
  pending: 'pending changes',
  announce: 'new rows',
  stale: 'a source is behind',
  progress: 'work running',
}

const live = useSignal(props.controller.live)
const rows = useSignal(props.controller.rows)
const pendingCount = useSignal(props.controller.pendingCount)
const announced = useSignal(props.controller.announced)
const tableProgress = useSignal(props.controller.progress.table)
const bulkProgress = useSignal(props.controller.progress.bulk)
const bulkStarted = useSignal(props.controller.bulk.started)
const bulkReport = useSignal(props.controller.bulk.report)

/** The accessible name of the track, and the caption of a bar we did not start. */
const BULK_BAR_NAME = 'Working on the marked rows'

const bulkBarCaption = computed(() => {
  const progress = bulkProgress.value
  if (progress === null) {
    return ''
  }
  const ours = bulkStarted.value
  if (ours === null || ours.progressKey !== progress.progressKey) {
    return BULK_BAR_NAME
  }

  return progress.total === null
    ? ours.label
    : `${ours.label}: ${progress.current} of ${progress.total}`
})

const untouchedCount = computed(() =>
  bulkReport.value === null
    ? 0
    : bulkReport.value.untouched.length + bulkReport.value.untouchedOmitted,
)

const reportTitle = computed(() => {
  const report = bulkReport.value
  if (report === null) {
    return ''
  }
  const changed = `Changed ${report.touched} ${report.touched === 1 ? 'row' : 'rows'}`

  return untouchedCount.value === 0
    ? changed
    : `${changed}, ${untouchedCount.value} untouched`
})

function variantFor(kind: HilosTableLiveKind): string {
  if (kind === 'report') {
    return untouchedCount.value > 0 ? 'alert-warning' : 'alert-success'
  }
  return VARIANT[kind]
}

// The sentence about the columns that went quiet, read out of the very list the
// table is drawn from.
const staleLabel = computed(() => {
  const sources = hilosTableStaleSources(rows.value)

  return hilosTableStaleLabel(
    hilosTableStaleColumns(props.columns, sources),
    sources.size > 0,
  )
})

// The numeral of each message is chosen here rather than in the template: '1 rows'
// would stand in the most visible place of the screen.
const announceLabel = computed(() =>
  announced.value.total === 1
    ? '1 new row'
    : `${announced.value.total} new rows`,
)

// Only the tail of the waiting sentence is composed here, because the count itself
// stays a node of its own under `hilos-table-pending` — the handle the outside reads
// the number by.
const pendingSuffix = computed(() =>
  pendingCount.value === 1
    ? 'row will move or leave'
    : 'rows will move or leave',
)

// What the hidden region says: the sentence of the message on the line, then the
// others by name. The track of running work is not in it — it moves every second.
const announcement = computed(() => {
  const top = live.value.top
  if (top === null) {
    return ''
  }
  const sentences: Record<HilosTableLiveKind, string> = {
    report: `${reportTitle.value}.`,
    bulk: `${bulkBarCaption.value}.`,
    pending: `${pendingCount.value} ${pendingSuffix.value}.`,
    announce: `${announceLabel.value}.`,
    stale: staleLabel.value ?? '',
    progress: 'Work is running on this table.',
  }
  const rest = live.value.rest.map((kind) => REST_WORDS[kind])

  return rest.length === 0
    ? sentences[top]
    : `${sentences[top]} Also: ${rest.join(', ')}.`
})

const detailsOpen = ref(false)
const detailsReport = ref<HilosTableBulkReport | null>(null)

function titleForReport(report: HilosTableBulkReport | null): string {
  if (report === null) {
    return ''
  }
  const count = report.untouched.length + report.untouchedOmitted
  const changed = `Changed ${report.touched} ${report.touched === 1 ? 'row' : 'rows'}`
  return count === 0 ? changed : `${changed}, ${count} untouched`
}

function openDetails(): void {
  if (bulkReport.value !== null) {
    detailsReport.value = bulkReport.value
    detailsOpen.value = true
  }
}
</script>

<template>
  <div class="position-relative mb-2" data-id="hilos-table-live-slot">
    <div
      :class="[ROW_CLASS, 'alert-secondary', 'invisible']"
      aria-hidden="true"
      data-id="hilos-table-live-idle"
    >
      <i class="bi bi-info-circle flex-shrink-0"></i>
      <span class="small flex-grow-1 text-truncate">&nbsp;</span>
      <!-- A span, not a button: the twin holds room, it does not take focus. -->
      <span class="btn btn-sm btn-outline-secondary flex-shrink-0">&nbsp;</span>
    </div>

    <div
      v-if="live.top !== null"
      :key="live.top"
      :class="[
        ROW_CLASS,
        variantFor(live.top),
        'position-absolute',
        'top-0',
        'start-0',
        'w-100',
      ]"
      :data-id="ROW_ID[live.top]"
    >
      <i
        class="bi flex-shrink-0"
        :class="ICON[live.top]"
        aria-hidden="true"
      ></i>
      <span class="small flex-grow-1 text-truncate">
        <template v-if="live.top === 'report'">{{ reportTitle }}</template>
        <template v-else-if="live.top === 'bulk'">{{
          bulkBarCaption
        }}</template>
        <template v-else-if="live.top === 'pending'">
          <span data-id="hilos-table-pending">{{ pendingCount }}</span>
          {{ pendingSuffix }}
        </template>
        <template v-else-if="live.top === 'announce'">{{
          announceLabel
        }}</template>
        <template v-else-if="live.top === 'stale'">{{ staleLabel }}</template>
        <template v-else-if="tableProgress">
          <slot name="table-progress" :progress="tableProgress" />
        </template>
      </span>
      <span
        v-if="live.rest.length > 0"
        class="d-flex gap-1 flex-shrink-0"
        data-id="hilos-table-live-rest"
      >
        <i
          v-for="kind in live.rest"
          :key="kind"
          class="bi flex-shrink-0"
          :class="ICON[kind]"
          aria-hidden="true"
        ></i>
      </span>
      <template v-if="live.top === 'report' && bulkReport">
        <button
          v-if="untouchedCount > 0"
          type="button"
          class="btn btn-sm btn-outline-secondary flex-shrink-0"
          data-id="hilos-table-bulk-report-details"
          @click="openDetails()"
        >
          Details
        </button>
        <button
          type="button"
          class="btn-close flex-shrink-0"
          aria-label="Dismiss"
          data-id="hilos-table-bulk-report-close"
          @click="controller.dismissBulkReport(bulkReport.progressKey)"
        ></button>
      </template>
      <button
        v-else-if="live.top === 'pending'"
        type="button"
        class="btn btn-sm btn-warning flex-shrink-0"
        data-id="hilos-table-apply"
        @click="controller.apply()"
      >
        Apply
      </button>
      <button
        v-else-if="live.top === 'announce'"
        type="button"
        class="btn btn-sm btn-outline-secondary flex-shrink-0"
        data-id="hilos-table-announce-show"
        @click="controller.show()"
      >
        Show
      </button>
      <template v-else-if="live.top === 'progress' && tableProgress">
        <slot name="table-progress-action" :progress="tableProgress" />
        <!-- The track runs along the bottom edge of the line and adds no height;
        the line is positioned itself, so it is the box the track sits in. -->
        <HilosTableProgress
          class="position-absolute bottom-0 start-0 w-100 rounded-0"
          :progress="tableProgress"
          label="Work on this table"
        />
      </template>
      <template v-else-if="live.top === 'bulk' && bulkProgress">
        <HilosTableProgress
          class="position-absolute bottom-0 start-0 w-100 rounded-0"
          :progress="bulkProgress"
          label="Working on the marked rows"
        />
      </template>
    </div>

    <HilosModal
      v-model="detailsOpen"
      :title="titleForReport(detailsReport)"
      initial-focus="dialog"
    >
      <ul
        v-if="detailsReport"
        class="list-unstyled mb-0"
        data-id="hilos-table-bulk-report-list"
      >
        <li v-for="row in detailsReport.untouched" :key="row.rowKey">
          <strong>
            <slot
              name="bulk-untouched"
              :row-key="row.rowKey"
              :reason="row.reason"
            >
              {{ row.rowKey }}
            </slot>
          </strong>
          — {{ row.reason }}
        </li>
        <li v-if="detailsReport.untouchedOmitted > 0">
          and {{ detailsReport.untouchedOmitted }} more
        </li>
      </ul>
      <template #actions="{ requestClose }">
        <button type="button" class="btn btn-secondary" @click="requestClose()">
          Close
        </button>
      </template>
    </HilosModal>

    <span
      class="visually-hidden"
      role="status"
      data-id="hilos-table-live-status"
      >{{ announcement }}</span
    >
  </div>
</template>
