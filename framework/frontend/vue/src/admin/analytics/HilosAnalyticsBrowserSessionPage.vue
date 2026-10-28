<script setup lang="ts">
import {
  ANALYTICS_ACTION_CREATED_TS_FIELD,
  ANALYTICS_ACTION_PAGE_NAME_FIELD,
  ANALYTICS_ACTION_NAME_FIELD,
  ANALYTICS_BROWSER_SESSION_COPY,
  ANALYTICS_BROWSER_SESSION_ROUTE_PARAM,
  ANALYTICS_NO_VALUE,
  createHilosAnalyticsActionsTable,
  formatAnalyticsLastSignedIn,
  formatAnalyticsTime,
  hilosAnalyticsBrowserSession,
  readAnalyticsRouteId,
  type HilosAnalyticsActionsTable,
  type HilosAnalyticsContext,
} from '@hilos/core/admin/analytics'
import {
  bindPageInstanceName,
  computedSignal,
  HilosPages,
  hilosTimeZone,
} from '@hilos/core/base'
import { computed, inject, onUnmounted, shallowRef, watch } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosHideable from '../../HilosHideable.vue'
import HilosSkeleton from '../../HilosSkeleton.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{ context: HilosAnalyticsContext }>()
const router = inject(hilosRouterKey)
if (!router) {
  throw new Error(
    'HilosAnalyticsBrowserSessionPage requires a provided Hilos router.',
  )
}

const pageLabel = props.context.scopes.pageDataSignal('pageLabel')
const unbindName = bindPageInstanceName(
  props.context.scopes,
  HilosPages.ANALYTICS_BROWSER_SESSION,
  computedSignal(() => {
    if (
      props.context.scopes.page()?.key !== HilosPages.ANALYTICS_BROWSER_SESSION
    ) {
      return undefined
    }
    const label = pageLabel.get()
    return typeof label === 'string' ? label : undefined
  }),
)
onUnmounted(unbindName)

const route = useSignal(router.currentRoute)
const browserSessionId = computed(() =>
  readAnalyticsRouteId(
    route.value.params[ANALYTICS_BROWSER_SESSION_ROUTE_PARAM],
  ),
)
const header = useSignal(hilosAnalyticsBrowserSession(props.context.scopes))
const identity = useSignal(router.pageIdentity)
const actions = shallowRef<HilosAnalyticsActionsTable | null>(null)

const timeZone = useSignal(hilosTimeZone)
function time(moment: number): string {
  void timeZone.value
  return formatAnalyticsTime(moment)
}

// The router clears its buffered page response after publishing the route.
// Rebind before the next render, once that synchronous navigation has finished.
watch(
  browserSessionId,
  (id) => {
    actions.value?.dispose()
    actions.value = null
    if (id === null) return
    actions.value = createHilosAnalyticsActionsTable(props.context, {
      page: HilosPages.ANALYTICS_BROWSER_SESSION,
      browserSessionId: id,
    })
    actions.value.start()
  },
  { immediate: true },
)
onUnmounted(() => actions.value?.dispose())
</script>

<template>
  <HilosAdminPage
    :page="HilosPages.ANALYTICS_BROWSER_SESSION"
    head-kind="entity"
  >
    <template #head-visual>
      <span
        class="bg-body-secondary rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0 p-3 fs-3 lh-1"
        aria-hidden="true"
        data-id="analytics-browser-session-head-icon"
      >
        <i :class="['bi', identity?.pageHeadOwner?.icon]"></i>
      </span>
    </template>
    <template #head-description>
      <p
        class="text-body-secondary mb-0"
        data-id="analytics-browser-session-head-description"
      >
        {{ identity?.pageHeadOwner?.lead }}
      </p>
    </template>
    <template #head-skeleton-visual>
      <span
        class="hilos-avatar-md rounded-circle bg-secondary-subtle flex-shrink-0"
        data-id="analytics-browser-session-head-skeleton-icon"
      ></span>
    </template>
    <div
      data-id="hilos-analytics-browser-session-header"
      class="mb-4 text-break"
    >
      <HilosSkeleton v-if="header === undefined" :lines="[6, 8]" />
      <HilosHideable v-else :value="header">
        <template #default="{ value }">
          <p data-id="hilos-analytics-browser-session-browser" class="mb-2">
            {{ value.browser ?? ANALYTICS_NO_VALUE }}
          </p>
          <p data-id="hilos-analytics-browser-session-person" class="mb-0">
            <span v-if="value.lastSignedInUserId !== null"
              >{{ ANALYTICS_BROWSER_SESSION_COPY.lastSignedIn }}:</span
            >
            {{ formatAnalyticsLastSignedIn(value) }}
          </p>
        </template>
      </HilosHideable>
    </div>
    <HilosViewportTable
      v-if="actions"
      :key="browserSessionId ?? undefined"
      :controller="actions.controller"
      data-id="hilos-analytics-actions-table"
    >
      <template #[`cell-${ANALYTICS_ACTION_CREATED_TS_FIELD}`]="{ row }">
        <span data-id="hilos-analytics-action-when" :data-record="row.rowKey">
          <HilosHideable :value="row.createdTs">
            <template #default="{ value }">
              <span :data-created-ts="value">{{ time(value) }}</span>
            </template>
          </HilosHideable>
        </span>
      </template>
      <template #[`cell-${ANALYTICS_ACTION_PAGE_NAME_FIELD}`]="{ row }">
        <span
          data-id="hilos-analytics-action-page"
          :data-record="row.rowKey"
          class="text-break"
        >
          <HilosHideable :value="row.pageName">
            <template #default="{ value }">{{
              value ?? ANALYTICS_NO_VALUE
            }}</template>
          </HilosHideable>
        </span>
      </template>
      <template #[`cell-${ANALYTICS_ACTION_NAME_FIELD}`]="{ row }">
        <span
          data-id="hilos-analytics-action-name"
          :data-record="row.rowKey"
          class="text-break"
        >
          <HilosHideable :value="row.actionName" />
        </span>
      </template>
    </HilosViewportTable>
  </HilosAdminPage>
</template>
