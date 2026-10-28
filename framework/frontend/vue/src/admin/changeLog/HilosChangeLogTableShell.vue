<!-- The table entity's shared head and navigation, using only the current
page's permitted declaration. Every tab supplies its own body below it. -->
<script setup lang="ts">
import {
  CHANGE_LOG_TABLE_COPY as copy,
  CHANGE_LOG_TABLE_ROUTE_PARAM,
  CHANGE_LOG_TABLE_SECTION,
  changeLogTableJournalSince,
  formatChangeLogTableOwner,
  readHilosChangeLogTable,
  type HilosChangeLogReceiptContext,
} from '@hilos/core/admin/change-log'
import {
  bindPageInstanceName,
  computedSignal,
  HilosPages,
} from '@hilos/core/base'
import { computed, inject, onUnmounted } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{
  /** The existing connection, page scopes and tracked actions. */
  context: HilosChangeLogReceiptContext
  /** The subscribing table page whose navigation link is current. */
  page: string
}>()

defineSlots<{
  /** The current table's body, hidden until its declaration arrives. */
  default?(): unknown
}>()

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error('HilosChangeLogTableShell requires a provided Hilos router.')
}
const pageTable = props.context.scopes.pageDataSignal(CHANGE_LOG_TABLE_SECTION)
const currentTable = computedSignal(() => {
  const page = props.context.scopes.page()?.key
  const route = router.currentRoute.get()
  if (
    (page !== HilosPages.CHANGE_LOG_TABLE &&
      page !== HilosPages.CHANGE_LOG_TABLE_COLUMNS &&
      page !== HilosPages.CHANGE_LOG_TABLE_TRIGGERS &&
      page !== HilosPages.CHANGE_LOG_TABLE_HISTORY) ||
    route.page !== page
  ) {
    return null
  }
  const detail = readHilosChangeLogTable(pageTable.get())
  return detail?.name === route.params[CHANGE_LOG_TABLE_ROUTE_PARAM]
    ? detail
    : null
})
const unbindName = bindPageInstanceName(
  props.context.scopes,
  HilosPages.CHANGE_LOG_TABLE,
  computedSignal(() => currentTable.get()?.name),
)
onUnmounted(unbindName)
const table = useSignal(currentTable)
const since = computed(() =>
  table.value ? changeLogTableJournalSince(table.value) : null,
)
</script>

<template>
  <HilosAdminPage
    :page="page"
    head-kind="entity"
    head-title-class="font-monospace fs-5"
    tabs-label="Table pages"
  >
    <template v-if="table" #head-visual>
      <span
        class="bg-body-secondary rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0 p-3 fs-3 lh-1"
        aria-hidden="true"
        data-id="hilos-change-log-table-head-icon"
      >
        <i class="bi bi-table"></i>
      </span>
    </template>
    <template v-if="table" #head-badges>
      <span
        class="d-inline-flex flex-wrap gap-2"
        data-id="hilos-change-log-table-head-badges"
      >
        <span class="badge bg-body-secondary text-body border">{{
          formatChangeLogTableOwner(table.owner)
        }}</span>
        <span
          v-if="table.journaled"
          class="badge bg-success-subtle text-success-emphasis border border-success-subtle"
        >
          <i class="bi bi-journal-check me-1" aria-hidden="true"></i>
          {{ copy.journaled }}
        </span>
      </span>
    </template>
    <template v-if="table" #head-description>
      <p
        class="text-body-secondary mb-0"
        data-id="hilos-change-log-table-head-description"
      >
        <template v-if="!table.journaled">{{ copy.untracked }}</template>
        <template v-else-if="since !== null"
          >Journaled since migration
          {{ String(since).padStart(3, '0') }}</template
        >
      </p>
    </template>
    <template v-if="table">
      <slot />
    </template>
  </HilosAdminPage>
</template>
