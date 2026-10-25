<!-- People in browser sessions of the selected period. The shared Analytics
head names the standard table and its mobile list; the core owns the captured
calendar context, Period filter, facets and paging. Hidden names keep their
public person-number link. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  ANALYTICS_PERSON_LAST_SEEN_TS_FIELD,
  ANALYTICS_PERSON_ROUTE_PARAM,
  ANALYTICS_PERSON_ROW_KEY_FIELD,
  ANALYTICS_PERSON_SESSION_COUNT_FIELD,
  createHilosAnalyticsPeopleTable,
  formatAnalyticsPeopleTime,
  formatAnalyticsPersonName,
  type HilosAnalyticsContext,
} from '@hilos/core/admin/analytics'
import { HilosPages, isHiddenValue } from '@hilos/core/base'
import { inject, onUnmounted } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosHideable from '../../HilosHideable.vue'
import HilosLink from '../../HilosLink.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

const props = defineProps<{ context: HilosAnalyticsContext }>()
const router = inject(hilosRouterKey)
if (!router) {
  throw new Error('HilosAnalyticsPeoplePage requires a provided Hilos router.')
}

const people = createHilosAnalyticsPeopleTable(props.context, {
  page: HilosPages.ANALYTICS_PEOPLE,
})
people.start()
onUnmounted(() => people.dispose())

// A person's existing Analytics page, when the project serves it.
const personPath = (rowKey: number): string | undefined =>
  router.resolvePath(HilosPages.ANALYTICS_PERSON, {
    [ANALYTICS_PERSON_ROUTE_PARAM]: String(rowKey),
  })
</script>

<template>
  <HilosAdminPage
    :page="HilosPages.ANALYTICS_PEOPLE"
    head-kind="section"
    tabs-label="Analytics pages"
  >
    <HilosViewportTable
      :controller="people.controller"
      data-id="hilos-analytics-people-table"
    >
      <template #[`cell-${ANALYTICS_PERSON_ROW_KEY_FIELD}`]="{ row }">
        <span
          class="text-break"
          data-id="hilos-analytics-person-name"
          :data-record="row.rowKey"
        >
          <template
            v-for="path in [personPath(row.rowKey)]"
            :key="path ?? 'none'"
          >
            <HilosLink
              v-if="path"
              :to="path"
              data-id="hilos-analytics-person-link"
              :data-record="row.rowKey"
              >{{ formatAnalyticsPersonName(row) }}</HilosLink
            >
            <span
              v-else
              data-id="hilos-analytics-person-link"
              :data-record="row.rowKey"
              >{{ formatAnalyticsPersonName(row) }}</span
            >
          </template>
          <HilosHideable
            v-if="isHiddenValue(row.name)"
            :value="row.name"
            class="ms-1"
          />
        </span>
      </template>
      <template #[`cell-${ANALYTICS_PERSON_SESSION_COUNT_FIELD}`]="{ row }">
        <span
          data-id="hilos-analytics-person-sessions"
          :data-record="row.rowKey"
        >
          <HilosHideable :value="row.sessionCount" />
        </span>
      </template>
      <template #[`cell-${ANALYTICS_PERSON_LAST_SEEN_TS_FIELD}`]="{ row }">
        <span
          data-id="hilos-analytics-person-last-seen"
          :data-record="row.rowKey"
        >
          <HilosHideable :value="row.lastSeenTs">
            <template #default="{ value }">{{
              formatAnalyticsPeopleTime(value, people.periodContext)
            }}</template>
          </HilosHideable>
        </span>
      </template>
    </HilosViewportTable>
  </HilosAdminPage>
</template>
