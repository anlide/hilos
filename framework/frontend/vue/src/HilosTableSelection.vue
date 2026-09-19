<!-- HilosTableSelection — the panel that stands in place of the bar's controls
while rows are marked: how many are marked, the way to take the whole filtered
set, the operations the page declared, and the way to drop the marks. Under them
stands the bar of the run those operations start and the report it ends with —
the third place work is drawn in, and the one that belongs to the framework
entirely (table-subscription.md, "Selection and bulk actions"). It holds NO rule
about the marks: which rows they are, what the header checkbox shows and how a
deleted row leaves the selection are all the controller's, and every control here
is a call into it (multiframework-core.md). What it does hold is what only the
view can know — which operation a confirmation is about, and which run the bar
above the track is named after. Internal to the Vue view layer on purpose: it is
not exported from index.ts, for the reason the bar and the footer are not.
It is MOUNTED for the whole life of a table that declared bulk operations and only
SHOWS itself while one of the three counts holds. Its confirmation is a HilosModal,
and a dialog owns the page's scroll lock: it takes the lock when it opens, and a
closed one releases it the moment it mounts, whosever lock that was. A panel that
came and went with what the server sends — a report arriving, a window arriving with
none of the marked rows left — would pass that lock around on nobody's behalf, and
would take an open dialog off the screen with it. -->
<script setup lang="ts" generic="R">
import { computed, ref } from 'vue'
import type {
  HilosTableBulkAction,
  HilosTableBulkReport,
  TableViewportController,
} from '@hilos/core'

import HilosActionError from './HilosActionError.vue'
import HilosModal from './HilosModal.vue'
import HilosTableProgress from './HilosTableProgress.vue'
import LoadingButton from './LoadingButton.vue'
import { useSignal } from './useSignal.js'
import { useTrackedAction } from './useTrackedAction.js'

const props = defineProps<{
  /** The headless server-windowed controller the panel reads and drives. */
  controller: TableViewportController<R>
  /**
   * Whether the panel stands in place of the bar's controls. The bar works it out,
   * because the same answer decides which of the two strips it draws.
   */
  shown: boolean
  /**
   * The report to draw, or null when none stands. The bar above resolves what a
   * reader dismissed, because the same answer decides whether this panel is on
   * screen at all — one rule, read in one place.
   */
  report: HilosTableBulkReport | null
}>()

const emit = defineEmits<{
  /**
   * The reader dismissed the report of this run.
   *
   * @param progressKey Key of the run whose report was dismissed.
   */
  dismiss: [progressKey: string]
}>()

defineSlots<{
  /**
   * The human name of one row a run left untouched; the key is printed where the
   * page fills nothing, because the row itself has left the window by then.
   */
  'bulk-untouched'(props: { rowKey: string; reason: string }): unknown
}>()

// The declaration does not change over the life of a table, so its operations are
// read once rather than wrapped in a signal (tableFrame.ts, HilosTableFrameState).
const bulkActions = props.controller.frame.declaration?.bulkActions ?? []

const target = useSignal(props.controller.selection.target)
const count = useSignal(props.controller.selection.count)
const bulkProgress = useSignal(props.controller.progress.bulk)

// The choice by condition says words and not a number: the size of a large set is
// a ceiling rather than a count, so "12 480 marked" would be a lie, while what is
// on this page is the truth about what is on screen (Flow F3).
const byFilter = computed(() => target.value?.kind === 'filter')
const countLabel = computed(() =>
  byFilter.value
    ? 'All rows matching the filter'
    : `${count.value} marked on this page`,
)

// The button the mockup pins to the right, away from the two that only narrow or
// drop the choice: the destructive operation where the page declared one, and the
// first operation otherwise — the shape of the row is the same either way.
const pinnedKey = computed(
  () =>
    (bulkActions.find((action) => action.danger === true) ?? bulkActions[0])
      ?.key,
)

// The run this panel started, remembered as the pair the bar is named by. Naming
// it from the declaration is honest only while the bar is the very run we asked
// for: a bar under another key is somebody else's work, and this panel knows
// nothing about what it is (Flow F9).
const started = ref<{ progressKey: string; label: string } | null>(null)

/** The accessible name of the track, and the caption of a bar we did not start. */
const BAR_NAME = 'Working on the marked rows'

const barCaption = computed(() => {
  const progress = bulkProgress.value
  if (progress === null) {
    return ''
  }
  const ours = started.value
  if (ours === null || ours.progressKey !== progress.progressKey) {
    return BAR_NAME
  }

  return progress.total === null
    ? ours.label
    : `${ours.label}: ${progress.current} of ${progress.total}`
})

// The untouched a report speaks of: the ones it named, and the ones that did not
// fit under the server's ceiling. Both count towards the headline, because a run
// that omitted names still left those rows alone (Flow F10).
const untouchedCount = computed(() =>
  props.report === null
    ? 0
    : props.report.untouched.length + props.report.untouchedOmitted,
)

const reportTitle = computed(() => {
  if (props.report === null) {
    return ''
  }
  const changed = `Changed ${props.report.touched} rows`

  return untouchedCount.value === 0
    ? changed
    : `${changed}, ${untouchedCount.value} untouched`
})

// Which operation the open confirmation is about. The dialog draws its title and
// its confirming button from it, so the two cannot say different things.
const confirmAction = ref<HilosTableBulkAction | null>(null)
const confirmOpen = ref(false)

const bulkTracked = useTrackedAction()
const {
  loading: bulkLoading,
  busy: bulkBusy,
  run: runBulk,
  clearError: clearBulkError,
} = bulkTracked

const confirmBody = computed(() =>
  byFilter.value
    ? 'Every row matching the current filter will be affected, including rows that are not on this page'
    : `${count.value} ${count.value === 1 ? 'row' : 'rows'} on this page will be affected`,
)

function openConfirm(action: HilosTableBulkAction): void {
  clearBulkError()
  confirmAction.value = action
  confirmOpen.value = true
}

function closeConfirm(): void {
  confirmOpen.value = false
}

// Authoritative-backend: the reply answers ACCEPTANCE and nothing more — the work
// itself is watched through the bar and ends in the report — so a success closes
// the dialog, while a refusal keeps it open with the sentence the page sent back.
// The target is read HERE rather than when the button was pressed: between the two
// the reader may have cleared a checkbox (Flow F7).
async function submitBulk(): Promise<void> {
  const action = confirmAction.value
  const runTarget = target.value
  if (action === null || runTarget === null || bulkBusy.value) {
    return
  }
  const handle = action.run(runTarget)
  if (!(await runBulk(handle))) {
    return
  }
  const accepted = (await handle.done).reply
  if (accepted !== undefined) {
    started.value = {
      progressKey: accepted.progressKey,
      label: action.label,
    }
  }
  closeConfirm()
}
</script>

<template>
  <div
    v-if="shown"
    class="d-flex flex-column gap-2 mb-2 p-2 rounded border bg-body-tertiary"
    role="group"
    aria-label="Selection"
    data-id="hilos-table-selection"
  >
    <div class="d-flex align-items-center flex-wrap gap-2">
      <span class="small fw-medium" data-id="hilos-table-selection-count">
        {{ countLabel }}
      </span>

      <!-- Gone in the choice by condition: there it is already pressed, and a
      second press would change nothing (Flow F3). -->
      <button
        v-if="!byFilter"
        type="button"
        class="btn btn-sm btn-outline-secondary"
        data-id="hilos-table-select-all-filtered"
        @click="controller.selectAllByFilter()"
      >
        Select all matching the filter
      </button>

      <!-- While a run is going the server refuses a second one on this table, and
      a button whose only outcome is a refusal is not a button. It is a courtesy
      and not a guarantee: a refusal that arrives anyway is still shown (Flow
      F5). -->
      <button
        v-for="action in bulkActions"
        :key="action.key"
        type="button"
        class="btn btn-sm"
        :class="[
          action.danger === true
            ? 'btn-outline-danger'
            : 'btn-outline-secondary',
          { 'ms-auto': action.key === pinnedKey },
        ]"
        :disabled="bulkProgress !== null"
        :data-id="`hilos-table-bulk-${action.key}`"
        @click="openConfirm(action)"
      >
        {{ action.label }}
      </button>

      <button
        type="button"
        class="btn btn-sm btn-link text-decoration-none"
        data-id="hilos-table-selection-clear"
        @click="controller.clearSelection()"
      >
        Clear
      </button>
    </div>

    <!-- The bar of the run: the framework gives the room, the track comes ready
    from the one component both other bars are drawn with, and the line above it
    names the operation while that is honest (Flow F9). -->
    <div v-if="bulkProgress" data-id="hilos-table-progress-bulk">
      <div class="small mb-1">{{ barCaption }}</div>
      <HilosTableProgress :progress="bulkProgress" :label="BAR_NAME" />
    </div>

    <!-- The outcome, announced as it appears: it comes from the server rather
    than from a press, so waiting for the reader to find it with their eyes would
    be waiting for nothing (Flow F13). -->
    <div
      v-if="report"
      class="alert py-2 px-3 small mb-0"
      :class="untouchedCount > 0 ? 'alert-warning' : 'alert-success'"
      role="status"
      data-id="hilos-table-bulk-report"
    >
      <div class="d-flex align-items-start gap-2">
        <span class="fw-semibold flex-grow-1">{{ reportTitle }}</span>
        <button
          type="button"
          class="btn-close"
          aria-label="Dismiss"
          data-id="hilos-table-bulk-report-close"
          @click="emit('dismiss', report.progressKey)"
        ></button>
      </div>
      <ul v-if="untouchedCount > 0" class="list-unstyled mb-0 mt-1">
        <li v-for="row in report.untouched" :key="row.rowKey">
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
        <!-- The names that did not fit under the server's ceiling, as a number:
        a run that omitted names says so rather than quietly showing a shorter
        list (Flow F11). -->
        <li v-if="report.untouchedOmitted > 0">
          and {{ report.untouchedOmitted }} more
        </li>
      </ul>
    </div>
  </div>

  <!-- Outside the div above on purpose: the dialog outlives the strip, so that a
  panel going away under the reader cannot take an open dialog — and the page's
  scroll lock — with it. -->
  <HilosModal
    v-model="confirmOpen"
    :title="confirmAction?.label"
    initial-focus="dialog"
    @cancel="closeConfirm"
  >
    <HilosActionError :action="bulkTracked" />
    <p class="mb-0">{{ confirmBody }}</p>
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-secondary"
        :disabled="bulkBusy"
        @click="requestClose"
      >
        Cancel
      </button>
      <LoadingButton
        :class="confirmAction?.danger === true ? 'btn-danger' : 'btn-primary'"
        :loading="bulkLoading"
        :disabled="bulkBusy"
        data-id="hilos-table-bulk-confirm"
        @click="submitBulk"
      >
        {{ confirmAction?.label }}
      </LoadingButton>
    </template>
  </HilosModal>
</template>
