<!-- HilosAnalyticsPersonPage — one person's page in the Analytics section
(HilosPages.ANALYTICS_PERSON): the browser sessions in which they signed in
last. The admin shell draws the one heading (the person's name and number from
the page's answer), the lead, and the table's accessible name; the table is the
core's browser-sessions viewport, preset to the person in the address and drawn
once per subscription, without live updates. Each session number opens that
session's page (HIL-1419). A viewer of the admin view mode sees Hidden in place
of every value. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  ANALYTICS_BROWSER_SESSION_ACTION_COUNT_FIELD,
  ANALYTICS_BROWSER_SESSION_BROWSER_FIELD,
  ANALYTICS_BROWSER_SESSION_FIRST_SEEN_TS_FIELD,
  ANALYTICS_BROWSER_SESSION_LAST_SEEN_TS_FIELD,
  ANALYTICS_BROWSER_SESSION_ROUTE_PARAM,
  ANALYTICS_NO_VALUE,
  ANALYTICS_PERSON_ROUTE_PARAM,
  createHilosAnalyticsBrowserSessionsTable,
  formatAnalyticsSessionNumber,
  formatAnalyticsTime,
  HilosPages,
  readAnalyticsRouteId,
  type HilosAnalyticsBrowserSessionsTable,
  type HilosAnalyticsContext,
} from '@hilos/core'
import { computed, inject, onUnmounted, shallowRef, watch } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosHideable from '../../HilosHideable.vue'
import HilosLink from '../../HilosLink.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{ context: HilosAnalyticsContext }>()
const router = inject(hilosRouterKey)
if (!router) {
  throw new Error('HilosAnalyticsPersonPage requires a provided Hilos router.')
}

const route = useSignal(router.currentRoute)
const userId = computed(() =>
  readAnalyticsRouteId(route.value.params[ANALYTICS_PERSON_ROUTE_PARAM]),
)
const sessions = shallowRef<HilosAnalyticsBrowserSessionsTable | null>(null)

// A session's page, or nothing when the application has no address for it.
const sessionPath = (rowKey: number): string | undefined =>
  router.resolvePath(HilosPages.ANALYTICS_BROWSER_SESSION, {
    [ANALYTICS_BROWSER_SESSION_ROUTE_PARAM]: String(rowKey),
  })

// The router clears its buffered page response after publishing the route.
// Rebind before the next render, once that synchronous navigation has finished.
watch(
  userId,
  (id) => {
    sessions.value?.dispose()
    sessions.value = null
    if (id === null) return
    sessions.value = createHilosAnalyticsBrowserSessionsTable(props.context, {
      page: HilosPages.ANALYTICS_PERSON,
      userId: id,
    })
    sessions.value.start()
  },
  { immediate: true },
)
onUnmounted(() => sessions.value?.dispose())
</script>

<template>
  <HilosAdminPage :page="HilosPages.ANALYTICS_PERSON">
    <HilosViewportTable
      v-if="sessions"
      :key="userId ?? undefined"
      :controller="sessions.controller"
      data-id="hilos-analytics-browser-sessions-table"
    >
      <template #cell-rowKey="{ row }">
        <template
          v-for="path in [sessionPath(row.rowKey)]"
          :key="path ?? 'none'"
        >
          <HilosLink
            v-if="path"
            :to="path"
            data-id="hilos-analytics-session-link"
            :data-record="row.rowKey"
            >{{ formatAnalyticsSessionNumber(row.rowKey) }}</HilosLink
          >
          <span
            v-else
            data-id="hilos-analytics-session-link"
            :data-record="row.rowKey"
            >{{ formatAnalyticsSessionNumber(row.rowKey) }}</span
          >
        </template>
      </template>
      <template
        #[`cell-${ANALYTICS_BROWSER_SESSION_FIRST_SEEN_TS_FIELD}`]="{ row }"
      >
        <span
          data-id="hilos-analytics-session-first-seen"
          :data-record="row.rowKey"
        >
          <HilosHideable :value="row.firstSeenTs">
            <template #default="{ value }">{{
              formatAnalyticsTime(value)
            }}</template>
          </HilosHideable>
        </span>
      </template>
      <template
        #[`cell-${ANALYTICS_BROWSER_SESSION_LAST_SEEN_TS_FIELD}`]="{ row }"
      >
        <span
          data-id="hilos-analytics-session-last-seen"
          :data-record="row.rowKey"
        >
          <HilosHideable :value="row.lastSeenTs">
            <template #default="{ value }">{{
              formatAnalyticsTime(value)
            }}</template>
          </HilosHideable>
        </span>
      </template>
      <template #[`cell-${ANALYTICS_BROWSER_SESSION_BROWSER_FIELD}`]="{ row }">
        <span
          data-id="hilos-analytics-session-browser"
          :data-record="row.rowKey"
          class="text-break"
        >
          <HilosHideable :value="row.browser">
            <template #default="{ value }">{{
              value ?? ANALYTICS_NO_VALUE
            }}</template>
          </HilosHideable>
        </span>
      </template>
      <template
        #[`cell-${ANALYTICS_BROWSER_SESSION_ACTION_COUNT_FIELD}`]="{ row }"
      >
        <span
          data-id="hilos-analytics-session-actions"
          :data-record="row.rowKey"
        >
          <HilosHideable :value="row.actionCount" />
        </span>
      </template>
    </HilosViewportTable>
  </HilosAdminPage>
</template>
