<!-- HilosUsersPage — the framework Hilos users-list page (HilosPages.USERS): the
users table inside the admin shell. All table logic, the row view-model, and what
the table declares about its frame — columns, search, empty state — are the core
headless's (createHilosUsersTable / HilosUserRow); this view owns only the markup,
so a project mounts it by passing its HilosUsersContext. The framework owns every
cell except the trailing actions cell, which a project fills through the
`#row-actions` slot (e.g. a link to the detail page); the takeover lives on the
person's card since HIL-1170. The bar's "Past deadline on" filter narrows the
list to the people past one legal document's deadline and lives in the address
too (`/hilos/users/terms`, HIL-945): the legal section's root links its count
there, and changing the filter rewrites the address. An account folded into
another one wears a gray "Merged" badge beside its name (HIL-1292).
Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosUsersTable,
  HilosPages,
  hilosStandingBadge,
  type HilosUserRow,
  type HilosUsersContext,
} from '@hilos/core'
import { inject, onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosHideable from '../../HilosHideable.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

const props = defineProps<{
  /** The project context: scope stores, connection, and the user collection. */
  context: HilosUsersContext
}>()

defineSlots<{
  /** The trailing actions cell for one row (e.g. an "Open" link). */
  'row-actions'(props: { row: HilosUserRow }): unknown
}>()

// The lapsed filter is read from the address the page opened on and written
// back as it changes; mounted without a navigator, the list opens whole and
// leaves the address alone.
const router = inject(hilosRouterKey, undefined)
const users = createHilosUsersTable(props.context, router)
const usersTable = users.controller

// Bind the server-windowed table to the connection on mount, request the first
// window, and unbind on unmount.
onMounted(() => users.start())
onUnmounted(() => users.dispose())

// The one badge the list draws: the merged standing, read off the row's merge
// slot rather than off a standing the list does not carry.
const mergedBadge = hilosStandingBadge('merged')
</script>

<template>
  <HilosAdminPage :page="HilosPages.USERS">
    <HilosViewportTable :controller="usersTable">
      <template #cell-id="{ row }">{{ row.id }}</template>
      <template #cell-name="{ row }"
        ><HilosHideable :value="row.name" /><span
          v-if="row.merged && mergedBadge !== null"
          class="badge ms-1"
          :class="`text-bg-${mergedBadge.tone}`"
          data-id="hilos-users-merged"
          ><i class="bi me-1" :class="mergedBadge.icon" aria-hidden="true"></i
          >{{ mergedBadge.label }}</span
        ></template
      >
      <template #cell-presence="{ row }">
        <span
          class="badge"
          :class="
            row.presence === 'online' ? 'text-bg-success' : 'text-bg-secondary'
          "
          >{{ row.presence }}</span
        >
      </template>
      <template #cell-onlineSessionCount="{ row }">{{
        row.onlineSessionCount
      }}</template>
      <template #cell-lastActivity="{ row }">{{
        row.lastActivity ?? '—'
      }}</template>
      <template #cell-actions="{ row }">
        <slot name="row-actions" :row="row" />
      </template>
    </HilosViewportTable>
  </HilosAdminPage>
</template>
