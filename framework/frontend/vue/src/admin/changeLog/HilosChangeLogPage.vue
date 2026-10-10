<!-- HilosChangeLogPage — the framework Change Log dashboard. The page response
supplies one journal overview; the feed asks for its own frozen table window.
The core owns the figures, empty-state words, row labels and table declaration.
This view draws them inside the admin shell. Bootstrap classes only. -->
<script setup lang="ts">
import {
  CHANGE_LOG_NO_RECEIPT_TEXT,
  CHANGE_LOG_OPEN_TABLES_LABEL,
  CHANGE_LOG_OVERVIEW_SECTION,
  CHANGE_LOG_SECTION_NOTE,
  CHANGE_LOG_TILE_JOURNAL_LABEL,
  CHANGE_LOG_TILE_TRACKED_LABEL,
  CHANGE_LOG_TILE_TRACKED_NOTE,
  changeLogChannelBadge,
  changeLogFeedEmpty,
  changeLogJournalNote,
  createHilosChangeLogFeedTable,
  formatChangeLogCoverage,
  formatChangeLogEntries,
  formatChangeLogOnBehalfOf,
  formatChangeLogTime,
  formatChangeLogTouched,
  formatChangeLogWho,
  HilosPages,
  isHiddenValue,
  readHilosChangeLogOverview,
  type HilosChangeLogContext,
} from '@hilos/core'
import { computed, inject, onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosHiddenMark from '../../HilosHiddenMark.vue'
import HilosLink from '../../HilosLink.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{
  /** The project's connection and page scope. */
  context: HilosChangeLogContext
}>()

const router = inject(hilosRouterKey)
const pageOverview = useSignal(
  props.context.scopes.pageDataSignal(CHANGE_LOG_OVERVIEW_SECTION),
)
const overview = computed(() => readHilosChangeLogOverview(pageOverview.value))
const empty = computed(() => changeLogFeedEmpty(overview.value))
const tablesPath = router?.resolvePath(HilosPages.CHANGE_LOG_TABLES, {})
const feed = createHilosChangeLogFeedTable(props.context, {
  tables: () => overview.value?.journaledTables ?? [],
})

onMounted(() => feed.start())
onUnmounted(() => feed.dispose())
</script>

<template>
  <HilosAdminPage :page="HilosPages.CHANGE_LOG">
    <p class="text-body-secondary">{{ CHANGE_LOG_SECTION_NOTE }}</p>

    <div class="row row-cols-1 row-cols-sm-2 g-3 mb-4">
      <div class="col">
        <div class="border rounded-3 p-3 h-100">
          <div class="d-flex align-items-center gap-2 text-body-secondary mb-1">
            <i class="bi bi-database" aria-hidden="true"></i>
            <span class="small">{{ CHANGE_LOG_TILE_JOURNAL_LABEL }}</span>
          </div>
          <div class="fs-4 lh-1 mb-1" data-id="hilos-change-log-tile-journal">
            {{
              overview ? formatChangeLogEntries(overview.journalEntries) : '—'
            }}
          </div>
          <div class="small text-body-secondary">
            {{ overview ? changeLogJournalNote(overview) : '—' }}
          </div>
        </div>
      </div>
      <div class="col">
        <div class="border rounded-3 p-3 h-100">
          <div class="d-flex align-items-center gap-2 text-body-secondary mb-1">
            <i class="bi bi-table" aria-hidden="true"></i>
            <span class="small">{{ CHANGE_LOG_TILE_TRACKED_LABEL }}</span>
          </div>
          <div class="fs-4 lh-1 mb-1" data-id="hilos-change-log-tile-tracked">
            {{ overview ? formatChangeLogCoverage(overview) : '—' }}
          </div>
          <div class="small text-body-secondary">
            {{ CHANGE_LOG_TILE_TRACKED_NOTE }}
          </div>
        </div>
      </div>
    </div>

    <HilosViewportTable :controller="feed.controller">
      <template #cell-createdAt="{ row }">{{
        formatChangeLogTime(row.createdAt)
      }}</template>
      <template #cell-actorLabel="{ row }">
        <span :class="{ 'text-body-secondary': row.actorDeleted }">
          <template v-if="isHiddenValue(formatChangeLogWho(row))">
            <HilosHiddenMark /><template v-if="row.actorId !== null">
              (#{{ row.actorId }})</template
            >
          </template>
          <template v-else>{{ formatChangeLogWho(row) }}</template>
        </span>
        <div v-if="row.subjectId !== null" class="small text-body-secondary">
          on behalf of
          <template v-if="isHiddenValue(formatChangeLogOnBehalfOf(row))">
            <HilosHiddenMark /> (#{{ row.subjectId }})
          </template>
          <template v-else>{{
            formatChangeLogOnBehalfOf(row) ?? `#${row.subjectId}`
          }}</template>
        </div>
        <span
          v-if="
            row.receiptId !== null &&
            changeLogChannelBadge(row.channel) !== null
          "
          class="badge bg-body-secondary text-body border mt-1"
        >
          <i
            :class="`bi ${changeLogChannelBadge(row.channel)?.icon} me-1`"
            aria-hidden="true"
          ></i>
          {{ changeLogChannelBadge(row.channel)?.label }}
        </span>
      </template>
      <template #cell-action="{ row }">
        <code v-if="row.receiptId !== null">{{ row.action ?? '—' }}</code>
        <span v-else class="text-body-secondary small">{{
          CHANGE_LOG_NO_RECEIPT_TEXT
        }}</span>
      </template>
      <template #cell-touched="{ row }">
        <code class="small text-body-secondary">{{
          formatChangeLogTouched(row.touched)
        }}</code>
      </template>
      <template #empty>
        <div data-id="hilos-change-log-feed-empty" :data-state="empty.kind">
          <i
            class="bi bi-inbox fs-1 text-body-secondary mb-2 d-block"
            aria-hidden="true"
          ></i>
          <div class="fw-semibold small mb-1">{{ empty.title }}</div>
          <p class="small text-body-secondary mb-3">{{ empty.hint }}</p>
          <HilosLink
            v-if="empty.kind === 'untracked' && tablesPath !== undefined"
            :to="tablesPath"
            class="btn btn-sm btn-outline-secondary"
            data-id="hilos-change-log-open-tables"
            >{{ CHANGE_LOG_OPEN_TABLES_LABEL }}</HilosLink
          >
        </div>
      </template>
    </HilosViewportTable>
  </HilosAdminPage>
</template>
