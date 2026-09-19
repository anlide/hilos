<!-- HilosLogsPage — the framework Hilos logs page (HilosPages.LOGS): the root of the
section and its overview. It answers two questions at once, "is anything wrong with
the journals" and "where do I go from here", so it keeps the shell's cards to its
child pages and puts its own figures underneath them, in the shell's named body slot.
Everything on it arrives in ONE frame of the page's own signal and refreshes itself
by push; there is no table viewport, because the per-node rows are one per node that
reported and fit in that frame whole. The screen commands nothing: the takeout banner
and the per-node badge are ordinary links into the rotations history, at the
address that opens it on the awaiting filter (HIL-903) — the batches the counter
names. Which of the two empty states it is in, and the wording of every figure,
are the core headless's (hilosLogsOverview); this view owns only the markup, so a
project mounts it by passing its HilosLogsOverviewContext. Bootstrap classes only
(styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosLogsOverview,
  formatLogsOverviewBytes,
  formatLogsOverviewCount,
  formatLogsOverviewGrowth,
  formatLogsOverviewRecentAt,
  formatLogsOverviewRotationAt,
  hasLogsOverviewNodes,
  hasLogsOverviewRecent,
  HILOS_ROTATION_STATE_DUE,
  HilosPages,
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
  RECENT_TAB_ERRORS,
  RECENT_TAB_WARNINGS,
  type HilosLogsOverviewContext,
  type HilosLogsRecentTab,
} from '@hilos/core'
import { computed, onMounted, onUnmounted, ref } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosLink from '../../HilosLink.vue'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{
  /** The project context: the connection the screen's frames arrive on. */
  context: HilosLogsOverviewContext
}>()

const handle = createHilosLogsOverview(props.context)
const overview = useSignal(handle.overview)

// The frame arrives once as the answer to the subscription and again on every tick
// where the cluster picture moved; nothing is ever re-requested.
onMounted(() => handle.start())
onUnmounted(() => handle.dispose())

// The per-node table exists only where nodes have names: in a single-node
// installation the whole idea of a node is absent, and a table of one row about
// "this machine" would be furniture for a distinction that does not exist.
const clustered = computed(() => hasLogsOverviewNodes(overview.value))
const nodes = computed(() => overview.value?.nodes ?? [])

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
  <HilosAdminPage :page="HilosPages.LOGS">
    <template #body>
      <div
        class="row row-cols-1 row-cols-sm-2 g-3 mb-3"
        data-id="hilos-logs-tiles"
      >
        <div class="col">
          <div class="border rounded-3 p-3 h-100">
            <div
              class="d-flex align-items-center gap-2 text-body-secondary mb-1"
            >
              <i class="bi bi-clock-history" aria-hidden="true"></i>
              <span class="small">Last rotation</span>
            </div>
            <!-- The tile names when it last happened and how many batches there
            are. It does NOT say "on schedule": that would be a verdict on the
            rotation setting, which is not in this frame, and reassuring an admin
            falsely here costs more than saying less. -->
            <div class="fs-4 lh-1 mb-1" data-id="hilos-logs-tile-rotation">
              {{
                formatLogsOverviewRotationAt(overview?.lastRotationAt ?? null)
              }}
            </div>
            <div class="small text-body-secondary">
              {{ logsOverviewBatchesNote(overview) }}
            </div>
          </div>
        </div>

        <div class="col">
          <div class="border rounded-3 p-3 h-100">
            <div
              class="d-flex align-items-center gap-2 text-body-secondary mb-1"
            >
              <i class="bi bi-graph-up-arrow" aria-hidden="true"></i>
              <span class="small">Written per day</span>
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
            <!-- Last of the three on purpose: the note above qualifies the figure,
            and this line says what the figure MEANS for the disk. The consequence
            is read after the caveat, and it is shown even while the caveat
            stands - the rate is there to divide by either way. -->
            <div
              v-if="logsOverviewForecastNote(overview)"
              class="small text-body-secondary"
              data-id="hilos-logs-growth-forecast"
            >
              {{ logsOverviewForecastNote(overview) }}
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
            <div
              class="d-flex align-items-center gap-2 text-body-secondary mb-1"
            >
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
            <div
              class="d-flex align-items-center gap-2 text-body-secondary mb-1"
            >
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
            <div
              class="d-flex align-items-center gap-2 text-body-secondary mb-1"
            >
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
                  {{ formatLogsOverviewRecentAt(entry.at) }}
                </span>
                <span class="flex-grow-1 small">
                  {{ entry.message }}
                  <span class="d-block text-body-secondary">
                    {{ logsOverviewRecentOrigin(overview, entry) }}
                  </span>
                </span>
                <span
                  v-if="entry.traceFrames !== null"
                  class="badge text-bg-light border flex-shrink-0"
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

      <template v-if="clustered">
        <div class="d-flex flex-wrap align-items-baseline gap-2 mb-2 mt-4">
          <h2 class="h6 text-uppercase text-body-secondary mb-0">By node</h2>
          <span
            class="badge text-bg-success-subtle text-success-emphasis border border-success-subtle"
          >
            <i class="bi bi-broadcast me-1" aria-hidden="true"></i>
            Updates itself
          </span>
        </div>
        <p class="small text-body-secondary">
          A journal is written on the node the event happened on and stays
          there. The tiles above add every node together; here you can see whose
          journal is growing faster.
        </p>
        <!-- An ordinary table and not the viewport one: the rows are one per node
        that reported and ride the same frame as the tiles, so a descriptor, a
        pager and a busy state would all be paid for a list that never pages. -->
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead>
              <tr>
                <th scope="col">Node</th>
                <th scope="col">Last rotation</th>
                <th scope="col" class="text-end d-none d-lg-table-cell">
                  Live
                </th>
                <th scope="col" class="text-end d-none d-lg-table-cell">
                  Archive
                </th>
                <th scope="col" class="text-end d-none d-lg-table-cell">
                  Per day
                </th>
                <th scope="col">To take out</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="node in nodes"
                :key="node.nodeId"
                :data-id="`hilos-logs-node-${node.nodeId}`"
              >
                <td>
                  <span class="badge text-bg-light border">
                    {{ node.nodeId }}
                  </span>
                  <!-- The sub-line carries whatever the hidden columns were
                  carrying, so a narrow screen loses the layout and not the
                  figures. -->
                  <div
                    v-if="node.available"
                    class="small text-body-secondary d-lg-none"
                  >
                    {{ formatLogsOverviewBytes(node.liveBytes) }} ·
                    {{ formatLogsOverviewBytes(node.archiveBytes) }} ·
                    {{ formatLogsOverviewBytes(node.growthBytesPerDay) }}
                  </div>
                </td>
                <!-- A node that could not read its own store says so once, in
                place of its figures. Dashes across every column would read as
                six separate unknowns instead of one node that did not answer. -->
                <td
                  v-if="!node.available"
                  colspan="5"
                  class="small text-body-secondary"
                  :data-id="`hilos-logs-node-nodata-${node.nodeId}`"
                >
                  No data — this node could not read its log store
                </td>
                <template v-else>
                  <td class="small">
                    {{ formatLogsOverviewRotationAt(node.lastRotationAt) }}
                  </td>
                  <td class="text-end d-none d-lg-table-cell">
                    {{ formatLogsOverviewBytes(node.liveBytes) }}
                  </td>
                  <td class="text-end d-none d-lg-table-cell">
                    {{ formatLogsOverviewBytes(node.archiveBytes) }}
                  </td>
                  <td class="text-end d-none d-lg-table-cell">
                    {{ formatLogsOverviewBytes(node.growthBytesPerDay) }}
                  </td>
                  <td>
                    <HilosLink
                      v-if="(node.batchesDueForTakeout ?? 0) > 0"
                      :to="rotationsPath"
                      class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle text-decoration-none"
                      :data-id="`hilos-logs-node-due-${node.nodeId}`"
                    >
                      {{ node.batchesDueForTakeout }}
                    </HilosLink>
                    <span v-else class="text-body-secondary small">—</span>
                  </td>
                </template>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

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
        <template v-if="clustered">
          No node could read its log store.
        </template>
        <template v-else>The log store could not be read.</template>
        Check the log directory setting and the permissions on it. The tiles
        stay empty rather than zero — a zero would be a measurement nobody took.
      </div>
    </template>
  </HilosAdminPage>
</template>
