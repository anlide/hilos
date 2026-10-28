<!-- The Maintenance Words tab: lists stubs for protected-mode operations. The core
owns rows and copy; this view binds its window to Words and shares Maintenance's
section head. -->
<script setup lang="ts">
import { HilosPages } from '@hilos/core/base'
import {
  createHilosMaintenanceWordsTable,
  type HilosMaintenanceContext,
} from '@hilos/core/admin/maintenance'
import { onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'

const props = defineProps<{
  /** The project context: scope stores, the connection, and the action lifecycle. */
  context: HilosMaintenanceContext
}>()

const words = createHilosMaintenanceWordsTable(
  props.context,
  HilosPages.MAINTENANCE_WORDS,
)
const wordsTable = words.controller

// Bind the table to Words on mount, consuming its initial page window, and
// release its listeners on unmount.
onMounted(() => {
  words.start()
})
onUnmounted(() => {
  words.dispose()
})
</script>

<template>
  <HilosAdminPage
    :page="HilosPages.MAINTENANCE_WORDS"
    head-kind="section"
    tabs-label="Maintenance pages"
  >
    <HilosViewportTable
      data-id="hilos-maintenance-words-table"
      :controller="wordsTable"
    >
      <template #cell-operation="{ row }">
        <span :data-id="`hilos-maintenance-words-row-${row.operation}`">{{
          row.operation
        }}</span>
      </template>
      <template #cell-title="{ row }">{{ row.title }}</template>
      <template #cell-message="{ row }">{{ row.message }}</template>
      <template #cell-bannerMessage="{ row }">{{ row.bannerMessage }}</template>
    </HilosViewportTable>
  </HilosAdminPage>
</template>
