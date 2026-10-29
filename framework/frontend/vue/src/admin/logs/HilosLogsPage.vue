<!-- HilosLogsPage — the framework Hilos logs page (HilosPages.LOGS): the root of the
section and its overview. It answers two questions at once, "is anything wrong with
the journals" and "where do I go from here". The overview frame carries the tiles,
forecast and takeout banner; the per-node rows use the ordinary page-scoped table
window. Both refresh by push. The screen commands nothing: the takeout banner
and the per-node badge are ordinary links into the rotations history, at the
address that opens it on the awaiting filter (HIL-903) — the batches the counter
names. Which of the two empty states it is in, and the wording of every figure,
are the core headless's (hilosLogsOverview); this view owns only the markup, so a
project mounts it by passing its HilosLogNodesContext. Bootstrap classes only
(styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosLogNodesTable,
  createHilosLogsOverview,
  formatLogsOverviewBytes,
  formatLogsOverviewCount,
  formatLogsOverviewGrowth,
  formatLogsOverviewRecentAt,
  formatLogsOverviewRotationAt,
  hasLogsOverviewNodes,
  hasLogsOverviewRecent,
  HILOS_ROTATION_STATE_DUE,
  logLevelVariant,
  logRotationsPath,
  logsOverviewBatchesNote,
  logsOverviewForecastNote,
  logsOverviewGrowthNote,
  logsOverviewNodesDue,
  logsOverviewRecent,
  logsOverviewRecentBadge,
  logsOverviewRecentEmptyLead,
  logsOverviewRecentEmptyTitle,
  logsOverviewRecentLead,
  logsOverviewRecentLevel,
  logsOverviewRecentOrigin,
  logsOverviewRecentPath,
  logsOverviewRecentTabLabel,
  logsOverviewState,
  logsOverviewTakeoutHeadline,
  logsOverviewThresholdPassed,
  NODE_ID_FIELD,
  NODE_LAST_ROTATION_AT_FIELD,
  NODE_LIVE_BYTES_FIELD,
  NODE_ARCHIVE_BYTES_FIELD,
  NODE_GROWTH_BYTES_PER_DAY_FIELD,
  NODE_BATCHES_DUE_FOR_TAKEOUT_FIELD,
  RECENT_TAB_ERRORS,
  RECENT_TAB_WARNINGS,
  type HilosLogNodesContext,
  type HilosLogsRecentTab,
} from '@hilos/core/admin/logs'
import { formatMoment, HilosPages, hilosTimeZone } from '@hilos/core/base'
import { computed, onMounted, onUnmounted, ref } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosHideable from '../../HilosHideable.vue'
import HilosLink from '../../HilosLink.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{
  /** The project context: the connection and page-scoped table stores. */
  context: HilosLogNodesContext
}>()

const handle = createHilosLogsOverview(props.context)
const nodesHandle = createHilosLogNodesTable(props.context)
const nodesTable = nodesHandle.controller
const overview = useSignal(handle.overview)

const timeZone = useSignal(hilosTimeZone)
function rotationAt(moment: string | null): string {
  void timeZone.value
  return formatLogsOverviewRotationAt(moment)
}
function recentAt(moment: string): string {
  void timeZone.value
  return formatLogsOverviewRecentAt(moment)
}
function nodeRotationAt(moment: number | null): string {
  void timeZone.value
  return moment === null
    ? '—'
    : (formatMoment(moment, { kind: 'dateTime' }) ?? '—')
}

// The subscription supplies the first overview and table window. Bind both push
// paths on mount without requesting a second window.
onMounted(() => {
  handle.start()
  nodesHandle.start()
})
onUnmounted(() => {
  nodesHandle.dispose()
  handle.dispose()
})

// The per-node table exists only where nodes have names: in a single-node
// installation the whole idea of a node is absent, and a table of one row about
// "this machine" would be furniture for a distinction that does not exist.
const clustered = computed(() => hasLogsOverviewNodes(overview.value))
const thresholdPassed = computed(() =>
  logsOverviewThresholdPassed(overview.value),
)

// Which of the two empty states the screen is in — the discrimination is the
// headless's, because it is the same question in all three view frameworks.
const state = computed(() => logsOverviewState(overview.value))

// The banner is about batches that already exist; at zero there is nothing to say,
// and a banner saying so would be a warning about nothing.
const batchesDue = computed(() => overview.value?.batchesDueForTakeout ?? 0)
const nodesDue = computed(() => logsOverviewNodesDue(overview.value))
// The panel of last failures. It is drawn only where there ARE figures: saying
// "nothing has gone wrong" about a picture that has not arrived would be good news
// made up, and that is the one thing an empty state here must never look like.
// The open tab is the screen's own state: both lists ride every frame, so a click on
// a tab asks the server for nothing.
const RECENT_TABS: readonly HilosLogsRecentTab[] = [
  RECENT_TAB_ERRORS,
  RECENT_TAB_WARNINGS,
]
const recentTab = ref<HilosLogsRecentTab>(RECENT_TAB_ERRORS)
const recentEntries = computed(() =>
  logsOverviewRecent(overview.value, recentTab.value),
)
const hasRecent = computed(() =>
  hasLogsOverviewRecent(overview.value, recentTab.value),
)
const recentLevel = computed(() => logsOverviewRecentLevel(recentTab.value))
const rotationsPath = logRotationsPath(HILOS_ROTATION_STATE_DUE)
</script>

<template>
  <HilosAdminPage
    :page="HilosPages.LOGS"
    head-kind="section"
    tabs-label="Logs pages"
  >
    <div
      class="row row-cols-1 row-cols-sm-2 g-3 mb-3"
      data-id="hilos-logs-tiles"
    >
      <div class="col">
        <div class="border rounded-3 p-3 h-100">
          <div class="d-flex align-items-center gap-2 text-body-secondary mb-1">
            <i class="bi bi-clock-history" aria-hidden="true"></i>
            <span class="small">Last rotation</span>
          </div>
          <!-- The tile names when it last happened and how many batches there
          are. It does NOT say "on schedule": that would be a verdict on the
          rotation setting, which is not in this frame, and reassuring an admin
          falsely here costs more than saying less. -->
          <div class="fs-4 lh-1 mb-1" data-id="hilos-logs-tile-rotation">
            {{ rotationAt(overview?.lastRotationAt ?? null) }}
          </div>
          <div class="small text-body-secondary">
            {{ logsOverviewBatchesNote(overview) }}
          </div>
        </div>
      </div>

      <div class="col">
        <div
          class="border rounded-3 p-3 h-100"
          :class="{ 'border-danger': thresholdPassed }"
          data-id="hilos-logs-growth-tile"
        >
          <div class="d-flex align-items-center gap-2 mb-1">
            <i
              class="bi bi-graph-up-arrow"
              :class="thresholdPassed ? 'text-danger' : 'text-body-secondary'"
              aria-hidden="true"
            ></i>
            <span class="small text-body-secondary">Written per day</span>
          </div>
          <div class="fs-4 lh-1 mb-1" data-id="hilos-logs-tile-growth">
            {{ formatLogsOverviewGrowth(overview) }}
          </div>
          <div
            v-if="logsOverviewGrowthNote(overview)"
            class="small text-body-secondary"
            data-id="hilos-logs-growth-note"
          >
            {{ logsOverviewGrowthNote(overview) }}
          </div>
          <!-- The disk consequence follows the caveat about the measured rate.
          A reached threshold remains a warning even before a rate is known. -->
          <div
            v-if="logsOverviewForecastNote(overview)"
            class="small"
            :class="
              thresholdPassed ? 'text-danger-emphasis' : 'text-body-secondary'
            "
            data-id="hilos-logs-growth-forecast"
          >
            <i
              v-if="thresholdPassed"
              class="bi bi-exclamation-triangle me-1"
              aria-hidden="true"
            ></i>
            <HilosHideable :value="logsOverviewForecastNote(overview)" />
          </div>
        </div>
      </div>
    </div>

    <!-- One set of the three stream classes, in the order of the filters on the
    streams screen: daemon, agents, workers. A row of its own rather than a fifth
    tile in the row above: a class missing from a set of three is seen at once,
    and at the tail of a list of five it is not - which is how the daemon streams
    stayed off this screen. -->
    <div
      class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-3 mb-4"
      data-id="hilos-logs-class-tiles"
    >
      <div class="col">
        <div class="border rounded-3 p-3 h-100">
          <div class="d-flex align-items-center gap-2 text-body-secondary mb-1">
            <i class="bi bi-hdd-stack" aria-hidden="true"></i>
            <span class="small">Daemon streams</span>
          </div>
          <div class="fs-4 lh-1 mb-1" data-id="hilos-logs-tile-daemon">
            {{ formatLogsOverviewCount(overview?.logKeysPerDaemon ?? null) }}
            <span class="fs-6 text-body-secondary">
              ·
              {{
                formatLogsOverviewBytes(
                  overview?.totalWeightDaemonKeysBytes ?? null,
                )
              }}
            </span>
          </div>
          <div class="small text-body-secondary">The raw pair counted in</div>
        </div>
      </div>

      <div class="col">
        <div class="border rounded-3 p-3 h-100">
          <div class="d-flex align-items-center gap-2 text-body-secondary mb-1">
            <i class="bi bi-cpu" aria-hidden="true"></i>
            <span class="small">Agent streams</span>
          </div>
          <div class="fs-4 lh-1 mb-1" data-id="hilos-logs-tile-agents">
            {{ formatLogsOverviewCount(overview?.logKeysPerAgent ?? null) }}
            <span class="fs-6 text-body-secondary">
              ·
              {{
                formatLogsOverviewBytes(
                  overview?.totalWeightAgentKeysBytes ?? null,
                )
              }}
            </span>
          </div>
          <div class="small text-body-secondary">
            Live and archived together
          </div>
        </div>
      </div>

      <div class="col">
        <div class="border rounded-3 p-3 h-100">
          <div class="d-flex align-items-center gap-2 text-body-secondary mb-1">
            <i class="bi bi-diagram-3" aria-hidden="true"></i>
            <span class="small">Worker streams</span>
          </div>
          <div class="fs-4 lh-1 mb-1" data-id="hilos-logs-tile-workers">
            {{ formatLogsOverviewCount(overview?.logKeysPerWorker ?? null) }}
            <span class="fs-6 text-body-secondary">
              ·
              {{
                formatLogsOverviewBytes(
                  overview?.totalWeightWorkerKeysBytes ?? null,
                )
              }}
            </span>
          </div>
          <div class="small text-body-secondary">
            Monopolistic ones included
          </div>
        </div>
      </div>
    </div>

    <div
      v-if="batchesDue > 0"
      class="alert alert-warning d-flex align-items-start gap-3 py-3"
      data-id="hilos-logs-takeout"
    >
      <i class="bi bi-box-arrow-up fs-5" aria-hidden="true"></i>
      <div class="flex-grow-1">
        <div class="fw-semibold small mb-1">
          {{ logsOverviewTakeoutHeadline(batchesDue)
          }}<template v-if="nodesDue.length">
            — on {{ nodesDue.join(', ') }}</template
          >
        </div>
        <div class="small">
          They are past the retention you set. Until you take them and confirm
          it, they free no space — nothing is deleted here on its own.
        </div>
      </div>
      <HilosLink
        :to="rotationsPath"
        class="btn btn-sm btn-warning text-nowrap"
        data-id="hilos-logs-takeout-open"
      >
        Show them
      </HilosLink>
    </div>

    <template v-if="state === 'figures'">
      <div class="d-flex flex-wrap align-items-baseline gap-2 mb-2 mt-4">
        <h2 class="h6 text-uppercase text-body-secondary mb-0">
          Recent failures
        </h2>
      </div>
      <p class="small text-body-secondary">
        {{ logsOverviewRecentLead(recentTab) }}
      </p>
      <!-- Two tabs and never one feed: warnings always outnumber errors and would
      bury them. -->
      <div
        class="border rounded-3 overflow-hidden mb-2"
        data-id="hilos-logs-recent"
      >
        <ul
          class="nav nav-tabs px-2 pt-2 bg-body-tertiary"
          role="tablist"
          data-id="hilos-logs-recent-tabs"
        >
          <li
            v-for="tab in RECENT_TABS"
            :key="tab"
            class="nav-item"
            role="presentation"
          >
            <button
              type="button"
              role="tab"
              class="nav-link py-1 px-3 small"
              :class="{ active: recentTab === tab }"
              :aria-selected="recentTab === tab"
              aria-controls="hilos-logs-recent-panel"
              :data-id="`hilos-logs-recent-tab-${tab}`"
              @click="recentTab = tab"
            >
              {{ logsOverviewRecentTabLabel(tab) }}
              <span
                class="badge ms-1"
                :class="`text-bg-${logLevelVariant(logsOverviewRecentLevel(tab))}`"
                :data-id="`hilos-logs-recent-count-${tab}`"
              >
                {{ logsOverviewRecentBadge(overview, tab) }}
              </span>
            </button>
          </li>
          <li
            class="ms-auto small text-body-secondary py-1"
            role="presentation"
          >
            Over the last hour
          </li>
        </ul>
        <div id="hilos-logs-recent-panel" role="tabpanel">
          <template v-if="hasRecent">
            <HilosLink
              v-for="(entry, index) in recentEntries"
              :key="index"
              :to="logsOverviewRecentPath(entry)"
              class="d-flex align-items-start gap-2 py-2 px-2 border-bottom text-decoration-none link-body-emphasis"
              data-id="hilos-logs-recent-row"
            >
              <span
                class="small fw-semibold flex-shrink-0"
                :class="`text-${logLevelVariant(recentLevel)}`"
                >{{ recentLevel }}</span
              >
              <span class="small text-body-secondary flex-shrink-0">
                {{ recentAt(entry.at) }}
              </span>
              <span class="flex-grow-1 small">
                <HilosHideable :value="entry.message" />
                <span class="d-block text-body-secondary">
                  {{ logsOverviewRecentOrigin(overview, entry) }}
                </span>
              </span>
              <span
                v-if="entry.traceFrames !== null"
                class="badge bg-body-tertiary text-body-emphasis border flex-shrink-0"
                title="This entry carries a stack trace"
                data-id="hilos-logs-recent-row-trace"
              >
                <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                {{ entry.traceFrames }}
              </span>
              <i
                class="bi bi-chevron-right text-body-secondary flex-shrink-0"
                aria-hidden="true"
              ></i>
            </HilosLink>
          </template>
          <!-- Good news, and it must not read as "no data": the picture IS here, and
          it says nothing of this kind happened in the window. -->
          <div
            v-else
            class="p-3 d-flex align-items-center gap-3 bg-body-tertiary"
            data-id="hilos-logs-recent-empty"
          >
            <i
              class="bi bi-check2-circle fs-4 text-success"
              aria-hidden="true"
            ></i>
            <div>
              <div class="fw-semibold small">
                {{ logsOverviewRecentEmptyTitle(recentTab) }}
              </div>
              <div class="small text-body-secondary">
                {{ logsOverviewRecentEmptyLead(recentTab) }}
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>

    <HilosViewportTable v-if="clustered" :controller="nodesTable" class="mt-4">
      <template #[`cell-${NODE_ID_FIELD}`]="{ row }">
        <span
          class="badge bg-body-tertiary text-body-emphasis border"
          :data-id="`hilos-logs-node-${row.nodeId}`"
        >
          {{ row.nodeId }}
        </span>
      </template>
      <template #[`cell-${NODE_LAST_ROTATION_AT_FIELD}`]="{ row }">
        <span :data-id="`hilos-logs-node-rotation-${row.nodeId}`">
          {{ nodeRotationAt(row.lastRotationAt) }}
        </span>
      </template>
      <template #[`cell-${NODE_LIVE_BYTES_FIELD}`]="{ row }">
        <span :data-id="`hilos-logs-node-live-${row.nodeId}`">
          {{ formatLogsOverviewBytes(row.liveBytes) }}
        </span>
      </template>
      <template #[`cell-${NODE_ARCHIVE_BYTES_FIELD}`]="{ row }">
        {{ formatLogsOverviewBytes(row.archiveBytes) }}
      </template>
      <template #[`cell-${NODE_GROWTH_BYTES_PER_DAY_FIELD}`]="{ row }">
        {{ formatLogsOverviewBytes(row.growthBytesPerDay) }}
      </template>
      <template #[`cell-${NODE_BATCHES_DUE_FOR_TAKEOUT_FIELD}`]="{ row }">
        <HilosLink
          v-if="(row.batchesDueForTakeout ?? 0) > 0"
          :to="rotationsPath"
          class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle text-decoration-none"
          :data-id="`hilos-logs-node-due-${row.nodeId}`"
        >
          {{ row.batchesDueForTakeout }}
        </HilosLink>
        <span v-else class="text-body-secondary small">—</span>
      </template>
    </HilosViewportTable>

    <div
      v-if="state === 'unknown'"
      class="alert alert-secondary small py-3 mt-4"
      data-id="hilos-logs-empty-unknown"
    >
      <div class="fw-semibold mb-1">
        <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>
        The cluster picture has not arrived yet
      </div>
      Nobody has reported yet, so the tiles are empty rather than zero: a zero
      would say nothing has ever rotated, and here we simply do not know.
    </div>
    <div
      v-else-if="state === 'unreadable'"
      class="alert alert-secondary small py-3 mt-4"
      data-id="hilos-logs-empty-unreadable"
    >
      <div class="fw-semibold mb-1">
        <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
        The log directory cannot be read
      </div>
      <template v-if="clustered"> No node could read its log store. </template>
      <template v-else>The log store could not be read.</template>
      Check the log directory setting and the permissions on it. The tiles stay
      empty rather than zero — a zero would be a measurement nobody took.
    </div>
  </HilosAdminPage>
</template>
