<!-- Analytics overview: three aggregates from the core's captured snapshot.
The shared head draws the catalog identity; counts and periods stay together
for this page opening. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  analyticsOverviewTiles,
  createHilosAnalyticsOverview,
  ANALYTICS_BROWSER_SESSIONS_PERIOD_ROUTE_PARAM,
  type HilosAnalyticsOverviewTile,
  type HilosAnalyticsContext,
} from '@hilos/core/admin/analytics'
import { HilosPages } from '@hilos/core/base'
import { computed, inject, onUnmounted } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosLink from '../../HilosLink.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{ context: HilosAnalyticsContext }>()
const router = inject(hilosRouterKey)
const overview = createHilosAnalyticsOverview(props.context.scopes)
overview.start()
onUnmounted(() => overview.dispose())

const snapshot = useSignal(overview.snapshot)
const tiles = computed(() =>
  snapshot.value === undefined
    ? undefined
    : analyticsOverviewTiles(snapshot.value),
)

const sessionsPath = (
  key: HilosAnalyticsOverviewTile['key'],
): string | undefined =>
  router?.resolvePath(HilosPages.ANALYTICS_BROWSER_SESSIONS, {
    [ANALYTICS_BROWSER_SESSIONS_PERIOD_ROUTE_PARAM]: {
      week: 'this-week',
      day: 'today',
      hour: 'this-hour',
    }[key],
  })
</script>

<template>
  <HilosAdminPage
    :page="HilosPages.ANALYTICS"
    head-kind="section"
    tabs-label="Analytics pages"
  >
    <div
      v-if="tiles === undefined"
      class="row row-cols-1 row-cols-sm-3 g-3 placeholder-glow"
      aria-hidden="true"
      data-id="hilos-analytics-overview-skeleton"
    >
      <div v-for="key in ['week', 'day', 'hour']" :key="key" class="col">
        <div class="border rounded-3 p-3 h-100">
          <span class="placeholder col-7 d-block mb-2 rounded"></span>
          <span
            class="placeholder placeholder-lg col-3 d-block mb-2 rounded"
          ></span>
          <span class="placeholder col-9 d-block rounded"></span>
        </div>
      </div>
    </div>
    <div
      v-else
      class="row row-cols-1 row-cols-sm-3 g-3 mb-4"
      data-id="hilos-analytics-overview-tiles"
    >
      <div v-for="tile in tiles" :key="tile.key" class="col">
        <dl
          class="border rounded-3 p-3 h-100 mb-0"
          :data-id="`hilos-analytics-visitors-${tile.key}`"
        >
          <dt class="small text-body-secondary fw-normal">{{ tile.label }}</dt>
          <dd
            class="fs-4 fw-semibold lh-sm my-1"
            :data-id="`hilos-analytics-visitors-${tile.key}-count`"
          >
            <template
              v-for="path in [sessionsPath(tile.key)]"
              :key="path ?? 'none'"
            >
              <HilosLink
                v-if="path"
                :to="path"
                class="text-decoration-none"
                :data-id="`hilos-analytics-visitors-${tile.key}-link`"
                >{{ tile.count }}</HilosLink
              >
              <template v-else>{{ tile.count }}</template>
            </template>
          </dd>
          <dd
            class="small text-body-secondary mb-0"
            :data-id="`hilos-analytics-visitors-${tile.key}-since`"
          >
            {{ tile.since }}
          </dd>
        </dl>
      </div>
    </div>
  </HilosAdminPage>
</template>
