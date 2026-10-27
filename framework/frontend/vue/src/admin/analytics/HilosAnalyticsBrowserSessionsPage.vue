<!-- Sessions overlapping the selected period. The shared head and table own
navigation, filters, facets, paging and mobile cards; this page supplies cells. -->
<script setup lang="ts">
import {
  ANALYTICS_BROWSER_SESSION_LIST_ROW_KEY_FIELD,
  ANALYTICS_BROWSER_SESSION_LIST_LAST_SIGNED_IN_USER_ID_FIELD,
  ANALYTICS_BROWSER_SESSION_LIST_BROWSER_FIELD,
  ANALYTICS_BROWSER_SESSION_LIST_FIRST_SEEN_TS_FIELD,
  ANALYTICS_BROWSER_SESSION_LIST_LAST_SEEN_TS_FIELD,
  ANALYTICS_BROWSER_SESSION_LIST_ACTION_COUNT_FIELD,
  ANALYTICS_BROWSER_SESSION_ROUTE_PARAM,
  ANALYTICS_PERSON_ROUTE_PARAM,
  ANALYTICS_NO_VALUE,
  createHilosAnalyticsBrowserSessionListTable,
  formatAnalyticsBrowserSessionListPerson,
  formatAnalyticsBrowserSessionListTime,
  type HilosAnalyticsBrowserSessionListRow,
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
  throw new Error(
    'HilosAnalyticsBrowserSessionsPage requires a provided Hilos router.',
  )
}

const sessions = createHilosAnalyticsBrowserSessionListTable(props.context, {
  currentRoute: router.currentRoute,
  replacePath: (path) => router.replacePath(path),
})
sessions.start()
onUnmounted(() => sessions.dispose())

const sessionPath = (rowKey: number): string | undefined =>
  router.resolvePath(HilosPages.ANALYTICS_BROWSER_SESSION, {
    [ANALYTICS_BROWSER_SESSION_ROUTE_PARAM]: String(rowKey),
  })

// Neither account field may contribute to an address while either is hidden.
const personPath = (
  row: HilosAnalyticsBrowserSessionListRow,
): string | undefined => {
  if (
    isHiddenValue(row.lastSignedInUserId) ||
    isHiddenValue(row.lastSignedInUserName) ||
    row.lastSignedInUserId === null
  )
    return undefined
  return router.resolvePath(HilosPages.ANALYTICS_PERSON, {
    [ANALYTICS_PERSON_ROUTE_PARAM]: String(row.lastSignedInUserId),
  })
}
</script>

<template>
  <HilosAdminPage
    :page="HilosPages.ANALYTICS_BROWSER_SESSIONS"
    head-kind="section"
    tabs-label="Analytics pages"
  >
    <HilosViewportTable
      :controller="sessions.controller"
      data-id="hilos-analytics-browser-session-list-table"
    >
      <template
        #[`cell-${ANALYTICS_BROWSER_SESSION_LIST_ROW_KEY_FIELD}`]="{ row }"
      >
        <template
          v-for="path in [sessionPath(row.rowKey)]"
          :key="path ?? 'none'"
        >
          <HilosLink
            v-if="path"
            :to="path"
            data-id="hilos-analytics-browser-session-list-session"
            :data-record="row.rowKey"
            >#{{ row.rowKey }}</HilosLink
          >
          <span
            v-else
            data-id="hilos-analytics-browser-session-list-session"
            :data-record="row.rowKey"
            >#{{ row.rowKey }}</span
          >
        </template>
      </template>
      <template
        #[`cell-${ANALYTICS_BROWSER_SESSION_LIST_LAST_SIGNED_IN_USER_ID_FIELD}`]="{
          row,
        }"
      >
        <span
          class="text-break"
          data-id="hilos-analytics-browser-session-list-person"
          :data-record="row.rowKey"
        >
          <HilosHideable :value="formatAnalyticsBrowserSessionListPerson(row)">
            <template #default="{ value }">
              <template v-for="path in [personPath(row)]" :key="path ?? 'none'">
                <HilosLink v-if="path" :to="path">{{ value }}</HilosLink>
                <template v-else>{{ value }}</template>
              </template>
            </template>
          </HilosHideable>
        </span>
      </template>
      <template
        #[`cell-${ANALYTICS_BROWSER_SESSION_LIST_BROWSER_FIELD}`]="{ row }"
      >
        <span
          class="text-break"
          data-id="hilos-analytics-browser-session-list-browser"
          :data-record="row.rowKey"
        >
          <HilosHideable :value="row.browser">
            <template #default="{ value }">{{
              value ?? ANALYTICS_NO_VALUE
            }}</template>
          </HilosHideable>
        </span>
      </template>
      <template
        #[`cell-${ANALYTICS_BROWSER_SESSION_LIST_FIRST_SEEN_TS_FIELD}`]="{
          row,
        }"
      >
        <span
          class="text-nowrap"
          data-id="hilos-analytics-browser-session-list-first-seen"
          :data-record="row.rowKey"
        >
          <HilosHideable :value="row.firstSeenTs">
            <template #default="{ value }">{{
              formatAnalyticsBrowserSessionListTime(
                value,
                sessions.periodContext,
              )
            }}</template>
          </HilosHideable>
        </span>
      </template>
      <template
        #[`cell-${ANALYTICS_BROWSER_SESSION_LIST_LAST_SEEN_TS_FIELD}`]="{ row }"
      >
        <span
          class="text-nowrap"
          data-id="hilos-analytics-browser-session-list-last-seen"
          :data-record="row.rowKey"
        >
          <HilosHideable :value="row.lastSeenTs">
            <template #default="{ value }">{{
              formatAnalyticsBrowserSessionListTime(
                value,
                sessions.periodContext,
              )
            }}</template>
          </HilosHideable>
        </span>
      </template>
      <template
        #[`cell-${ANALYTICS_BROWSER_SESSION_LIST_ACTION_COUNT_FIELD}`]="{ row }"
      >
        <span
          data-id="hilos-analytics-browser-session-list-actions"
          :data-record="row.rowKey"
        >
          <HilosHideable :value="row.actionCount" />
        </span>
      </template>
    </HilosViewportTable>
  </HilosAdminPage>
</template>
