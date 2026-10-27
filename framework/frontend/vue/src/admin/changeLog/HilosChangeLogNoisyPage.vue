<script setup lang="ts">
import {
  CHANGE_LOG_NOISY_COLUMN_FIELD,
  CHANGE_LOG_NOISY_COLUMNS_NOTE,
  CHANGE_LOG_NOISY_REASON_FIELD,
  createHilosChangeLogNoisyColumnsTable,
  type HilosChangeLogContext,
} from '@hilos/core/admin/change-log'
import { HilosPages } from '@hilos/core/base'
import { onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'

const props = defineProps<{
  /** The project's connection and page scope. */
  context: HilosChangeLogContext
}>()

const columns = createHilosChangeLogNoisyColumnsTable(props.context)
onMounted(() => columns.start())
onUnmounted(() => columns.dispose())
</script>

<template>
  <HilosAdminPage
    :page="HilosPages.CHANGE_LOG_NOISY"
    head-kind="section"
    tabs-label="Change Log pages"
  >
    <p class="text-body-secondary">{{ CHANGE_LOG_NOISY_COLUMNS_NOTE }}</p>
    <HilosViewportTable :controller="columns.controller">
      <template #[`cell-${CHANGE_LOG_NOISY_COLUMN_FIELD}`]="{ row }">
        <code class="text-break">{{ row.table }}.{{ row.column }}</code>
      </template>
      <template #[`cell-${CHANGE_LOG_NOISY_REASON_FIELD}`]="{ row }">
        <span class="small text-break">{{ row.reason }}</span>
      </template>
    </HilosViewportTable>
  </HilosAdminPage>
</template>
