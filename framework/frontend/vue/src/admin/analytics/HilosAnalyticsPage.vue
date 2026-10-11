<!-- HilosAnalyticsPage — the Analytics section overview. In Phase-1 it shows
an empty state with a line and a link to the admin users list; overview content
follows in HIL-1402. The core owns the shared words and the admin shell draws
the page identity. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  ANALYTICS_OVERVIEW_EMPTY_TEXT,
  ANALYTICS_OVERVIEW_OPEN_USERS_LABEL,
  HilosPages,
} from '@hilos/core'
import { inject } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosLink from '../../HilosLink.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error(
    'HilosAnalyticsPage requires a provided router: app.provide(hilosRouterKey, router).',
  )
}

const usersPath = router.resolvePath(HilosPages.USERS, {})
</script>

<template>
  <HilosAdminPage :page="HilosPages.ANALYTICS">
    <div
      class="border rounded p-4 text-center text-body-secondary"
      data-id="hilos-analytics-overview-empty"
    >
      <i class="bi bi-inbox fs-2 d-block mb-2" aria-hidden="true"></i>
      <p>{{ ANALYTICS_OVERVIEW_EMPTY_TEXT }}</p>
      <HilosLink
        v-if="usersPath !== undefined"
        :to="usersPath"
        class="btn btn-sm btn-outline-secondary"
        data-id="hilos-analytics-overview-open-users"
        >{{ ANALYTICS_OVERVIEW_OPEN_USERS_LABEL }}</HilosLink
      >
    </div>
  </HilosAdminPage>
</template>
