<script setup lang="ts">
import {
  CHANGE_LOG_COLUMN_MODES,
  CHANGE_LOG_COLUMN_MODES_TITLE,
  CHANGE_LOG_TABLES_NOTE,
  CHANGE_LOG_TABLES_OPEN_LABEL,
  CHANGE_LOG_TABLES_UNTRACKED_NOTE,
  createHilosChangeLogTablesTable,
  formatChangeLogColumnCounts,
  formatChangeLogTableChanges,
  formatChangeLogTableLastChange,
  formatChangeLogTableOwner,
  type HilosChangeLogContext,
  type HilosChangeLogTablesRow,
} from '@hilos/core/admin/change-log'
import { HilosPages } from '@hilos/core/base'
import { inject, onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosLink from '../../HilosLink.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

const props = defineProps<{
  /** The project's connection and page scope. */
  context: HilosChangeLogContext
}>()

const router = inject(hilosRouterKey)
const tables = createHilosChangeLogTablesTable(props.context)

/** Resolve the optional detail link once for each drawn actions cell. */
function tablePaths(row: HilosChangeLogTablesRow): string[] {
  const path = row.journaled
    ? router?.resolvePath(HilosPages.CHANGE_LOG_TABLE, { tableId: row.name })
    : undefined
  return path === undefined ? [] : [path]
}

onMounted(() => tables.start())
onUnmounted(() => tables.dispose())
</script>

<template>
  <HilosAdminPage
    :page="HilosPages.CHANGE_LOG_TABLES"
    head-kind="section"
    tabs-label="Change Log pages"
  >
    <p class="text-body-secondary">{{ CHANGE_LOG_TABLES_NOTE }}</p>

    <HilosViewportTable :controller="tables.controller">
      <template #cell-name="{ row }">
        <code
          class="text-break"
          :class="{ 'text-body-secondary': !row.journaled }"
          >{{ row.name }}</code
        >
        <div v-if="!row.journaled" class="small text-body-secondary">
          {{ CHANGE_LOG_TABLES_UNTRACKED_NOTE }}
        </div>
      </template>
      <template #cell-owner="{ row }">
        <span :class="{ 'text-body-secondary': !row.journaled }">{{
          formatChangeLogTableOwner(row.owner)
        }}</span>
      </template>
      <template #cell-columns="{ row }">
        <span :class="{ 'text-body-secondary': !row.journaled }">{{
          formatChangeLogColumnCounts(row.columns)
        }}</span>
      </template>
      <template #cell-changesLast24h="{ row }">
        <span :class="{ 'text-body-secondary': !row.journaled }">{{
          formatChangeLogTableChanges(row.changesLast24h)
        }}</span>
      </template>
      <template #cell-lastChangeAt="{ row }">
        <span :class="{ 'text-body-secondary': !row.journaled }">{{
          formatChangeLogTableLastChange(row.lastChangeAt)
        }}</span>
      </template>
      <template #cell-actions="{ row }">
        <HilosLink
          v-for="path in tablePaths(row)"
          :key="path"
          :to="path"
          class="btn btn-sm btn-outline-secondary"
          :data-id="`hilos-change-log-open-table-${row.name}`"
          :aria-label="`${CHANGE_LOG_TABLES_OPEN_LABEL} ${row.name}`"
          >{{ CHANGE_LOG_TABLES_OPEN_LABEL }}</HilosLink
        >
      </template>
    </HilosViewportTable>

    <section class="mt-4" data-id="hilos-change-log-column-modes">
      <h2 class="h5">{{ CHANGE_LOG_COLUMN_MODES_TITLE }}</h2>
      <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3">
        <div
          v-for="mode in CHANGE_LOG_COLUMN_MODES"
          :key="mode.key"
          class="col"
        >
          <div class="border rounded-3 p-3 h-100">
            <span class="badge text-wrap mb-2" :class="mode.tone">
              <i :class="`bi ${mode.icon} me-1`" aria-hidden="true"></i>
              {{ mode.label }}
            </span>
            <p class="small text-body-secondary mb-0">{{ mode.description }}</p>
          </div>
        </div>
      </div>
    </section>
  </HilosAdminPage>
</template>
