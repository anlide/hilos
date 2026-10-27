<script setup lang="ts">
import {
  CHANGE_LOG_OVERVIEW_SECTION,
  CHANGE_LOG_SECTION_NOTE,
  CHANGE_LOG_TILE_JOURNAL_LABEL,
  CHANGE_LOG_TILE_TRACKED_LABEL,
  CHANGE_LOG_TILE_TRACKED_NOTE,
  changeLogJournalNote,
  formatChangeLogCoverage,
  formatChangeLogEntries,
  readHilosChangeLogOverview,
  type HilosChangeLogContext,
} from '@hilos/core/admin/change-log'
import { HilosPages, hilosTabLinks, hilosTimeZone } from '@hilos/core/base'
import { computed, inject } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosLink from '../../HilosLink.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{
  /** The project's connection and page scope. */
  context: HilosChangeLogContext
}>()

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error('HilosChangeLogPage requires a provided Hilos router.')
}
const identity = useSignal(router.pageIdentity)
const route = useSignal(router.currentRoute)
const tabs = computed(() =>
  hilosTabLinks(
    identity.value?.pageTabs ?? [],
    route.value.params,
    router.resolvePath,
  ),
)
const feedPath = computed(
  () => tabs.value.find((tab) => tab.page === HilosPages.CHANGE_LOG_FEED)?.to,
)
const tablesPath = computed(
  () => tabs.value.find((tab) => tab.page === HilosPages.CHANGE_LOG_TABLES)?.to,
)

const pageOverview = useSignal(
  props.context.scopes.pageDataSignal(CHANGE_LOG_OVERVIEW_SECTION),
)
const overview = computed(() => readHilosChangeLogOverview(pageOverview.value))
const timeZone = useSignal(hilosTimeZone)
const journalNote = computed(() => {
  void timeZone.value
  return overview.value ? changeLogJournalNote(overview.value) : '—'
})
</script>

<template>
  <HilosAdminPage
    :page="HilosPages.CHANGE_LOG"
    head-kind="section"
    tabs-label="Change Log pages"
  >
    <p class="text-body-secondary">{{ CHANGE_LOG_SECTION_NOTE }}</p>

    <div class="row row-cols-1 row-cols-sm-2 g-3 mb-4">
      <div class="col">
        <component
          :is="feedPath === undefined ? 'div' : HilosLink"
          :to="feedPath"
          :data-id="
            feedPath === undefined ? undefined : 'hilos-change-log-link-feed'
          "
          class="d-block border rounded-3 p-3 h-100 text-body text-decoration-none"
        >
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
            {{ journalNote }}
          </div>
        </component>
      </div>
      <div class="col">
        <component
          :is="tablesPath === undefined ? 'div' : HilosLink"
          :to="tablesPath"
          :data-id="
            tablesPath === undefined
              ? undefined
              : 'hilos-change-log-link-tables'
          "
          class="d-block border rounded-3 p-3 h-100 text-body text-decoration-none"
        >
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
        </component>
      </div>
    </div>
  </HilosAdminPage>
</template>
